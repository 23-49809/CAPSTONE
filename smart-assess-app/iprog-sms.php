<?php
declare(strict_types=1);

/**
 * A send that did not succeed. outcome() tells callers whether IPROG
 * definitely did not take the message (REJECTED) or whether it may still have
 * been accepted (UNCERTAIN: timeouts, dropped connections, server errors,
 * unrecognised replies). An UNCERTAIN send must never be retried automatically.
 */
final class IprogSmsException extends RuntimeException
{
    public const REJECTED = 'rejected';
    public const UNCERTAIN = 'uncertain';

    public function __construct(string $message, private readonly string $outcome)
    {
        parent::__construct($message);
    }

    public function outcome(): string
    {
        return $this->outcome;
    }
}

final class IprogSmsService
{
    /** cURL errors raised before the request reaches IPROG (DNS, connect, TLS). */
    private const NOT_SENT_CURL_ERRORS = [6, 7, 35, 51, 58, 60, 77];

    private const ENDPOINT = 'https://www.iprogsms.com/api/v1/sms_messages';

    private const CREDITS_ENDPOINT = 'https://www.iprogsms.com/api/v1/account/sms_credits';

    private const STATUS_ENDPOINT = 'https://www.iprogsms.com/api/v1/sms_messages/status';

    /** Reasons IPROG refuses a send for. Whole words, so "content" or "SMART ASSESS" never match. */
    private const KNOWN_REFUSAL = '/invalid token|unauthori[sz]ed|\\b(?:smart(?!\\s+assess)|tnt)\\b|shared sender|sender name|insufficient|not enough|no (?:sms )?credits?|invalid (?:phone|mobile|number|recipient)/i';

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
        if (preg_match('/^639\d{9}$/', $phoneNumber)) {
            return $phoneNumber;
        }
        if (preg_match('/^\+639\d{9}$/', $phoneNumber)) {
            return substr($phoneNumber, 1);
        }
        if (preg_match('/^09\d{9}$/', $phoneNumber)) {
            return '63' . substr($phoneNumber, 1);
        }

        throw new InvalidArgumentException('Recipient must be a Philippine mobile number.');
    }

    /**
     * Checks the token and credits without sending or spending anything.
     * reachable is false when IPROG could not be asked at all; token_ok and
     * credits are null when its reply did not say. The token is never logged
     * or returned.
     *
     * @return array{reachable:bool, token_ok:?bool, credits:?int}
     */
    public function accountStatus(): array
    {
        $status = ['reachable' => false, 'token_ok' => null, 'credits' => null];
        $response = $this->getJson(self::CREDITS_ENDPOINT, ['api_token' => $this->apiToken], $status['reachable']);
        if ($response === null) {
            return $status;
        }
        if (is_string($response['message'] ?? null) && stripos($response['message'], 'invalid token') !== false) {
            $status['token_ok'] = false;
            return $status;
        }
        $providerStatus = $response['status'] ?? null;
        if ($providerStatus === 'success' || $providerStatus === 200 || $providerStatus === '200') {
            $status['token_ok'] = true;
        }
        $balance = is_array($response['data'] ?? null) ? ($response['data']['load_balance'] ?? null) : null;
        if (is_numeric($balance)) {
            $status['token_ok'] = true;
            $status['credits'] = (int) floor((float) $balance);
        }
        return $status;
    }

    /**
     * IPROG's current status for a message it accepted ("pending", "completed",
     * ...), lowercased, or null when it cannot say. Spends nothing, sends nothing.
     */
    public function messageStatus(string $messageId): ?string
    {
        $reachable = false;
        $response = $this->getJson(self::STATUS_ENDPOINT, ['api_token' => $this->apiToken, 'message_id' => $messageId], $reachable);
        $status = $response['message_status'] ?? null;
        return is_string($status) && $status !== '' ? strtolower(trim($status)) : null;
    }

    /** GET a JSON reply from IPROG. Null when unreachable or unreadable; the URL holds the token, so it is never logged. */
    private function getJson(string $url, array $query, bool &$reachable): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $handle = curl_init($url . '?' . http_build_query($query));
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($handle);
        if (!is_string($body)) {
            return null;
        }
        $reachable = true;
        $response = json_decode($body, true);
        return is_array($response) ? $response : null;
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
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is not installed.');
        }

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
            throw new IprogSmsException(
                'IPROG SMS request failed.',
                in_array($curlError, self::NOT_SENT_CURL_ERRORS, true)
                    ? IprogSmsException::REJECTED
                    : IprogSmsException::UNCERTAIN
            );
        }

        $response = json_decode($body, true);
        if (!is_array($response)) {
            error_log('[IPROG_SMS] Invalid JSON response; HTTP ' . $httpStatus . '.');
            throw new IprogSmsException(
                'IPROG SMS returned an invalid response.',
                $httpStatus === 401 || $httpStatus === 403
                    ? IprogSmsException::REJECTED
                    : IprogSmsException::UNCERTAIN
            );
        }

        $providerStatusValue = $response['status'] ?? null;
        $providerStatus = is_scalar($providerStatusValue) ? (string) $providerStatusValue : 'invalid';
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $messageId = $response['message_id'] ?? $data['message_id'] ?? null;
        $messageId = is_scalar($messageId) ? (string) $messageId : null;

        // IPROG's documented success body for sms_messages is
        // {"status": 200, "message": "...", "message_id": "iSms-..."}: status is
        // the integer 200, not "success". Anything else is treated as a rejection.
        $providerAccepted = $providerStatusValue === 200 || $providerStatusValue === '200';
        if ($httpStatus < 200 || $httpStatus >= 300 || !$providerAccepted) {
            $safeStatus = preg_replace('/[^A-Za-z0-9_-]/', '', $providerStatus) ?? '';
            $safeProviderMessage = $this->safeProviderMessage(
                $response['message'] ?? null,
                $phoneNumber,
                $normalizedPhoneNumber,
                $message
            );
            $outcome = self::rejectionOutcome($httpStatus, $providerStatusValue, $safeProviderMessage);
            $summary = $outcome === IprogSmsException::REJECTED
                ? 'rejected the request'
                : 'did not confirm the request (it may still have been queued)';
            error_log(sprintf(
                '[IPROG_SMS] Provider %s; HTTP %d, status %s, message: %s',
                $summary,
                $httpStatus,
                $safeStatus,
                $safeProviderMessage
            ));

            throw new IprogSmsException(sprintf(
                'IPROG SMS %s (HTTP %d; provider status %s): %s',
                $summary,
                $httpStatus,
                $safeStatus !== '' ? $safeStatus : 'unavailable',
                $safeProviderMessage
            ), $outcome);
        }

        return [
            'http_status' => $httpStatus,
            'provider_status' => $providerStatus,
            'message_id' => $messageId,
        ];
    }

    /**
     * "Not sent" is claimed only when IPROG says so unmistakably: HTTP 401/403,
     * or an error reply whose reason is one it is known to refuse a send for.
     * Everything else (HTTP 5xx, an unrecognised reason or reply) may still have
     * been queued, so it is UNCERTAIN and must never be retried automatically.
     */
    private static function rejectionOutcome(int $httpStatus, mixed $providerStatusValue, string $providerMessage): string
    {
        if ($httpStatus === 401 || $httpStatus === 403) {
            return IprogSmsException::REJECTED;
        }
        if ($httpStatus >= 500 || !preg_match(self::KNOWN_REFUSAL, $providerMessage)) {
            return IprogSmsException::UNCERTAIN;
        }
        if ($httpStatus >= 400 && $httpStatus < 500) {
            return IprogSmsException::REJECTED;
        }
        if (is_int($providerStatusValue) || (is_string($providerStatusValue) && ctype_digit($providerStatusValue))) {
            return (int) $providerStatusValue >= 400 ? IprogSmsException::REJECTED : IprogSmsException::UNCERTAIN;
        }
        return is_string($providerStatusValue) && in_array(strtolower($providerStatusValue), ['error', 'failed', 'fail'], true)
            ? IprogSmsException::REJECTED
            : IprogSmsException::UNCERTAIN;
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

        $sensitiveValues = [
            $this->apiToken,
            $phoneNumber,
            $normalizedPhoneNumber,
            '+' . $normalizedPhoneNumber,
            $message,
        ];
        $encodedSensitiveValues = [];
        foreach ($sensitiveValues as $value) {
            $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($encodedValue)) {
                $encodedSensitiveValues[] = substr($encodedValue, 1, -1);
            }
        }
        $safeMessage = str_replace(
            array_merge($sensitiveValues, $encodedSensitiveValues),
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
