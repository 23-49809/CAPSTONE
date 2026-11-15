<?php
declare(strict_types=1);

/**
 * HMAC-SHA256 authentication between the remote pull worker (a machine that
 * connects OUT to the website, e.g. bin/ai-remote-pull.php on a Mac) and the
 * website's endpoint web/ai-remote.php.
 *
 *  - Its own secret (SMART_ASSESS_AI_REMOTE_SECRET): never the AWS worker's
 *    secret. The endpoint refuses to run when both are equal.
 *  - Its own message prefixes and headers, so a signature made for the AWS
 *    worker protocol (WorkerSignature) can never be accepted here, or the
 *    other way round.
 *  - Requests: method, path, timestamp, nonce and the body's SHA-256. The
 *    timestamp must be within MAX_SKEW_SECONDS; the endpoint stores every
 *    accepted nonce (ai_remote_nonces) so a request cannot be replayed.
 *  - Responses: signed over the request's nonce, the HTTP status and the
 *    body's SHA-256, so the worker only accepts the answer to the request it
 *    sent, unaltered (this also covers streamed document downloads).
 * The secret is never sent, printed or logged.
 */
final class RemoteWorkerSignature
{
    public const MAX_SKEW_SECONDS = 300;
    public const MIN_SECRET_LENGTH = 32;
    public const HEADER_TIMESTAMP = 'X-SA-Remote-Timestamp';
    public const HEADER_NONCE = 'X-SA-Remote-Nonce';
    public const HEADER_SIGNATURE = 'X-SA-Remote-Signature';
    public const HEADER_RESPONSE_SIGNATURE = 'X-SA-Remote-Response-Signature';
    public const HEADER_BODY_SHA256 = 'X-SA-Remote-Body-Sha256';
    private const REQUEST_PREFIX = "smart-assess-remote-request-v1\n";
    private const RESPONSE_PREFIX = "smart-assess-remote-response-v1\n";

    public function __construct(#[\SensitiveParameter] private readonly string $secret)
    {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException('The remote worker secret must be at least ' . self::MIN_SECRET_LENGTH . ' characters.');
        }
    }

    /**
     * Reads the secret from a file that only its owner can read (mode 0600 or 0400).
     * Used by the remote worker, so the secret is never in its command line or environment.
     */
    public static function fromFile(string $path): self
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new RuntimeException('The remote worker secret file must be an absolute path.');
        }
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The remote worker secret file is missing, unreadable or a symbolic link.');
        }
        $mode = fileperms($path) & 0o777;
        if (($mode & 0o077) !== 0) {
            throw new RuntimeException('The remote worker secret file must be readable by its owner only (chmod 600).');
        }
        if (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) {
            throw new RuntimeException('The remote worker secret file must belong to the user running the worker.');
        }
        $secret = trim((string) file_get_contents($path, false, null, 0, 4096));
        return new self($secret);
    }

    /** Website side: the endpoint's secret from its environment, or null when not configured. */
    public static function fromEnvironment(string $variable = 'SMART_ASSESS_AI_REMOTE_SECRET'): ?self
    {
        $secret = getenv($variable);
        if (!is_string($secret) || strlen(trim($secret)) < self::MIN_SECRET_LENGTH) {
            return null;
        }
        return new self(trim($secret));
    }

    /** True when this secret equals $other (compared in constant time). Used to refuse a reused AWS worker secret. */
    public function sameSecretAs(#[\SensitiveParameter] string $other): bool
    {
        return hash_equals($this->secret, trim($other));
    }

    /** @return array<string,string> headers to send */
    public function signRequest(string $method, string $path, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        return [
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => $this->requestMac($method, $path, (string) $timestamp, $nonce, hash('sha256', $body)),
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
        return hash_equals($this->requestMac($method, $path, $timestamp, $nonce, hash('sha256', $body)), $signature);
    }

    /** Signature of a response whose body has the given SHA-256 (hex). */
    public function signResponseHash(string $nonce, int $status, string $bodySha256): string
    {
        return hash_hmac('sha256', self::RESPONSE_PREFIX . $nonce . "\n" . $status . "\n" . strtolower($bodySha256), $this->secret);
    }

    public function signResponse(string $nonce, int $status, string $body): string
    {
        return $this->signResponseHash($nonce, $status, hash('sha256', $body));
    }

    public function verifyResponse(string $nonce, int $status, string $body, string $signature): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1 && hash_equals($this->signResponse($nonce, $status, $body), $signature);
    }

    private function requestMac(string $method, string $path, string $timestamp, string $nonce, string $bodySha256): string
    {
        return hash_hmac('sha256', self::REQUEST_PREFIX . strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodySha256, $this->secret);
    }
}
