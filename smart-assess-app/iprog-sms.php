<?php
declare(strict_types=1);

final class IprogSmsService
{
    private const ENDPOINT = 'https://www.iprogsms.com/api/v1/sms_messages';

    private function __construct(private readonly string $apiToken)
    {
        if ($this->apiToken === '') {
            throw new RuntimeException('IPROG_API_TOKEN is not configured.');
        }
    }

    public static function fromEnvironment(): self
    {
        $token = getenv('IPROG_API_TOKEN');
        if ($token === false || trim($token) === '') {
            throw new RuntimeException('IPROG_API_TOKEN is not configured.');
        }

        return new self(trim($token));
    }

    public static function normalizePhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = trim($phoneNumber);
        if (preg_match('/^63\d{10}$/', $phoneNumber)) {
            return $phoneNumber;
        }
        if (preg_match('/^\+63\d{10}$/', $phoneNumber)) {
            return substr($phoneNumber, 1);
        }
        if (preg_match('/^09\d{9}$/', $phoneNumber)) {
            return '63' . substr($phoneNumber, 1);
        }

        throw new InvalidArgumentException('Recipient must be a Philippine mobile number.');
    }

    /**
     * @return array{http_status:int, provider_status:string, message_id:?string}
     */
    public function send(string $phoneNumber, string $message): array
    {
        if (trim($message) === '' || strlen($message) > 1000) {
            throw new InvalidArgumentException('SMS message must contain 1 to 1000 bytes.');
        }
        $normalizedPhoneNumber = self::normalizePhoneNumber($phoneNumber);

        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the IPROG SMS request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'api_token' => $this->apiToken,
                'phone_number' => $normalizedPhoneNumber,
                'message' => $message,
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);

        if ($body === false || $curlError !== 0) {
            error_log('[IPROG_SMS] Transport failure; cURL error code ' . $curlError . '.');
            throw new RuntimeException('IPROG SMS request failed.');
        }

        $response = json_decode($body, true);
        if (!is_array($response)) {
            error_log('[IPROG_SMS] Invalid JSON response; HTTP ' . $httpStatus . '.');
            throw new RuntimeException('IPROG SMS returned an invalid response.');
        }

        $providerStatusValue = $response['status'] ?? null;
        $providerStatus = is_scalar($providerStatusValue) ? (string) $providerStatusValue : 'invalid';
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $messageId = $response['message_id'] ?? $data['message_id'] ?? null;
        $messageId = is_scalar($messageId) ? (string) $messageId : null;

        if ($httpStatus < 200 || $httpStatus >= 300 || strtolower($providerStatus) !== 'success') {
            $safeStatus = preg_replace('/[^A-Za-z0-9_-]/', '', $providerStatus) ?? '';
            $safeProviderMessage = $this->safeProviderMessage(
                $response['message'] ?? null,
                $phoneNumber,
                $normalizedPhoneNumber,
                $message
            );
            error_log(sprintf(
                '[IPROG_SMS] Provider rejected request; HTTP %d, status %s, message: %s',
                $httpStatus,
                $safeStatus,
                $safeProviderMessage
            ));

            throw new RuntimeException(sprintf(
                'IPROG SMS rejected the request (HTTP %d; provider status %s): %s',
                $httpStatus,
                $safeStatus !== '' ? $safeStatus : 'unavailable',
                $safeProviderMessage
            ));
        }

        return [
            'http_status' => $httpStatus,
            'provider_status' => $providerStatus,
            'message_id' => $messageId,
        ];
    }

    private function safeProviderMessage(
        mixed $providerMessage,
        string $phoneNumber,
        string $normalizedPhoneNumber,
        string $message
    ): string {
        if (is_array($providerMessage) || is_object($providerMessage)) {
            $encoded = json_encode(
                $providerMessage,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            $providerMessage = $encoded === false ? '[unavailable]' : $encoded;
        } elseif (is_scalar($providerMessage)) {
            $providerMessage = (string) $providerMessage;
        } else {
            $providerMessage = 'No provider message supplied.';
        }

        $safeMessage = str_replace(
            [$this->apiToken, $phoneNumber, $normalizedPhoneNumber, '+' . $normalizedPhoneNumber, $message],
            '[redacted]',
            $providerMessage
        );
        $safeMessage = preg_replace(
            [
                '/(?<![A-Za-z0-9])(?:\+?63[\s().-]*|0)9(?:[\s().-]*\d){9}(?!\d)/',
                '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            ],
            '[redacted]',
            $safeMessage
        ) ?? '[unavailable]';

        return substr($safeMessage, 0, 500);
    }
}
