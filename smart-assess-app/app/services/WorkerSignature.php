<?php
declare(strict_types=1);

/**
 * HMAC-SHA256 authentication between the PHP application and the private AI
 * worker. Requests carry a timestamp and a random nonce (replay protection is
 * the worker's job: it remembers nonces for MAX_SKEW_SECONDS). Responses are
 * signed over the request nonce, the HTTP status and the body, so the
 * application only accepts the answer to the request it sent, unaltered.
 * The shared secret is never sent or logged.
 */
final class WorkerSignature
{
    public const MAX_SKEW_SECONDS = 300;
    public const HEADER_TIMESTAMP = 'X-SA-Timestamp';
    public const HEADER_NONCE = 'X-SA-Nonce';
    public const HEADER_SIGNATURE = 'X-SA-Signature';
    public const HEADER_RESPONSE_SIGNATURE = 'X-SA-Response-Signature';

    public function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The worker secret must be at least 32 characters.');
        }
    }

    public static function fromEnvironment(string $variable): self
    {
        $secret = getenv($variable);
        if (!is_string($secret) || strlen(trim($secret)) < 32) {
            throw new RuntimeException($variable . ' is not set or is shorter than 32 characters.');
        }
        return new self(trim($secret));
    }

    /** @return array<string,string> headers to send */
    public function signRequest(string $method, string $path, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        return [
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => $this->requestMac($method, $path, (string) $timestamp, $nonce, $body),
        ];
    }

    /** True only for a well-formed, fresh, correctly signed request. Replays are checked by the caller. */
    public function verifyRequest(string $method, string $path, string $body, string $timestamp, string $nonce, string $signature, int $now): bool
    {
        if (!preg_match('/^\d{9,11}$/', $timestamp) || !preg_match('/^[a-f0-9]{32}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }
        if (abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }
        return hash_equals($this->requestMac($method, $path, $timestamp, $nonce, $body), $signature);
    }

    public function signResponse(string $nonce, int $status, string $body): string
    {
        return hash_hmac('sha256', "smart-assess-worker-response-v2\n" . $nonce . "\n" . $status . "\n" . hash('sha256', $body), $this->secret);
    }

    public function verifyResponse(string $nonce, int $status, string $body, string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1 && hash_equals($this->signResponse($nonce, $status, $body), $signature);
    }

    private function requestMac(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', "smart-assess-worker-request-v1\n" . strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body), $this->secret);
    }
}
