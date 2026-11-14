<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerSignature.php';

/**
 * Sends one job to the private AI worker and returns its decoded answer, only
 * if the answer is signed for this exact request. Throws RuntimeException with
 * a code (AiVerification::retryClass() decides how each is retried):
 *  - transient: worker_unavailable (cannot connect; unsigned 503 or replay 409), worker_busy
 *  - crash: worker_crashed (connection dropped mid-job; unsigned 500/502, i.e. the worker
 *    process died), model_unavailable (Ollama failed during this job)
 *  - configuration: worker_auth_failed (401: secrets or clocks differ), worker_not_configured,
 *    worker_cleanup_failed, worker_tls_failed (certificate or CA), worker_body_too_large
 *    (unsigned 413: a web-server body limit), worker_proxy_error (any other unsigned answer:
 *    the URL reaches something that is not the worker, e.g. a wrong path or a proxy page)
 *  - counted: worker_timeout (no answer in time; unsigned 504), model_timeout, worker_error,
 *    worker_bad_response (a response signature that does not verify, too large, not JSON)
 *  - permanent: worker_rejected (the worker refused the job)
 *
 * Unsigned answers can only come from the web server or proxy in front of the
 * worker, or from the worker before it could check the request, so they never
 * count as this job's fault; a successful result always needs a valid signature.
 *
 * Time limits: the job tells the worker to answer within workerBudgetSeconds(),
 * which is shorter than this client's HTTP timeout, and the dispatcher claims
 * the job for leaseSeconds(), which is longer. So a valid job that takes long
 * is neither cut off by the client nor claimed by a second dispatcher.
 */
final class AiWorkerClient
{
    public const DEFAULT_TIMEOUT_SECONDS = 900;
    public const MIN_TIMEOUT_SECONDS = 60;
    public const MAX_TIMEOUT_SECONDS = 1800;
    /** Lease = timeout + this, so the job is still ours when the HTTP call gives up. */
    public const LEASE_MARGIN_SECONDS = 120;
    private const HEALTH_TIMEOUT_SECONDS = 10;

    private readonly string $url;

    public function __construct(
        string $baseUrl,
        private readonly WorkerSignature $signature,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $maxResponseBytes = 1_048_576,
        private readonly ?string $caFile = null,
        private readonly ?string $unixSocket = null
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new InvalidArgumentException('The AI worker URL must be a plain http(s)://host[:port] address.');
        }
        // Documents contain personal data: only loopback may use plain HTTP.
        if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new InvalidArgumentException('A non-local AI worker must use HTTPS.');
        }
        if ($timeoutSeconds < self::MIN_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('The AI worker timeout is out of range.');
        }
        // A private CA for the worker's certificate, trusted only for this connection.
        if ($caFile !== null && ($caFile === '' || $caFile[0] !== '/' || !is_file($caFile) || !is_readable($caFile))) {
            throw new InvalidArgumentException('The AI worker CA file must be an absolute path to a readable file.');
        }
        // Optional Unix socket (Step 13): the HTTP request goes to this socket file instead of a TCP port.
        // Used when the caller runs without network access (systemd PrivateNetwork=yes), so it can reach
        // only this one service, whatever happens to the host firewall. Loopback http:// URLs only (the
        // URL then only names the Host header); an absolute path short enough for a socket address.
        if ($unixSocket !== null && ($unixSocket === '' || $unixSocket[0] !== '/' || strlen($unixSocket) > 100
            || str_contains($unixSocket, "\0") || !preg_match('#^/[A-Za-z0-9._/-]+$#', $unixSocket) || str_contains($unixSocket, '/../')
            || $scheme !== 'http' || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            throw new InvalidArgumentException('The AI worker socket must be an absolute path, used with a loopback http:// URL.');
        }
        $this->url = $baseUrl;
    }

    public static function fromEnvironment(): self
    {
        $url = getenv('SMART_ASSESS_AI_WORKER_URL');
        if (!is_string($url) || trim($url) === '') {
            throw new RuntimeException('SMART_ASSESS_AI_WORKER_URL is not configured.');
        }
        $timeout = getenv('SMART_ASSESS_AI_WORKER_TIMEOUT');
        $caFile = getenv('SMART_ASSESS_AI_WORKER_CA_FILE');
        $socket = getenv('SMART_ASSESS_AI_WORKER_SOCKET');
        return new self($url, WorkerSignature::fromEnvironment('SMART_ASSESS_AI_WORKER_SECRET'),
            is_string($timeout) && ctype_digit(trim($timeout)) ? (int) trim($timeout) : self::DEFAULT_TIMEOUT_SECONDS, 1_048_576,
            is_string($caFile) && trim($caFile) !== '' ? trim($caFile) : null,
            is_string($socket) && trim($socket) !== '' ? trim($socket) : null);
    }

    /**
     * The private CA option, added with "+" so its key stays CURLOPT_CAINFO
     * (unpacking an array with "..." renumbers integer keys, which silently
     * turned this into a different curl option).
     */
    private function caOption(): array
    {
        return ($this->caFile !== null ? [CURLOPT_CAINFO => $this->caFile] : [])
            + ($this->unixSocket !== null ? [CURLOPT_UNIX_SOCKET_PATH => $this->unixSocket] : []);
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * How long the worker may spend on one job: the HTTP timeout minus a margin
     * for sending up to 70 MB and receiving the answer (a quarter of the
     * timeout, at most 120 s). 900 s gives 780 s.
     */
    public function workerBudgetSeconds(): int
    {
        return $this->timeoutSeconds - min(120, intdiv($this->timeoutSeconds, 4));
    }

    /** How long a dispatcher holds a claimed job: longer than the HTTP timeout. 900 s gives 1020 s. */
    public function leaseSeconds(): int
    {
        return $this->timeoutSeconds + self::LEASE_MARGIN_SECONDS;
    }

    public const HEALTH_READY = 'ready';
    public const HEALTH_NOT_READY = 'not_ready';            // temporary: wait
    public const HEALTH_UNREACHABLE = 'unreachable';        // no answer: wait
    public const HEALTH_NOT_CONFIGURED = 'not_configured';  // an administrator must fix a setting
    public const HEALTH_CLEANUP_FAILED = 'cleanup_failed';  // leftover job files could not be removed
    public const HEALTH_TLS_FAILED = 'tls_failed';          // certificate, CA or TLS setup
    public const HEALTH_STUCK = 'stuck';                    // a job has held the worker lock too long

    /** TLS errors mean the certificate or CA setup is wrong, not that the worker is down. */
    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /** True only when health() is HEALTH_READY. */
    public function healthy(): bool
    {
        return $this->health() === self::HEALTH_READY;
    }

    /**
     * Quick readiness check before claiming any job (GET /v1/health, 10 s).
     * The worker reports ready only when its settings are valid, its OCR
     * tools and languages are installed and Ollama lists the model. The
     * answer is not signed, so it only decides whether to try (or which
     * problem to report); every job is still authenticated, and any failure
     * after this check is handled by the normal retry rules.
     */
    public function health(): string
    {
        $curl = curl_init($this->url . '/v1/health');
        if ($curl === false) {
            return self::HEALTH_UNREACHABLE;
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::HEALTH_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ] + $this->caOption());
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status === 0) {
            return in_array(curl_errno($curl), self::TLS_ERRORS, true) ? self::HEALTH_TLS_FAILED : self::HEALTH_UNREACHABLE;
        }
        $answer = is_string($body) && strlen($body) < 200 ? json_decode($body, true) : null;
        $ok = is_array($answer) ? ($answer['ok'] ?? null) : null;
        $error = is_array($answer) ? ($answer['error'] ?? null) : null;
        if ($status === 200 && $ok === true) {
            return self::HEALTH_READY;
        }
        if ($ok === false && in_array($error, [self::HEALTH_NOT_CONFIGURED, self::HEALTH_CLEANUP_FAILED, self::HEALTH_STUCK], true)) {
            return $error;
        }
        if ($status >= 500) {
            return self::HEALTH_NOT_READY;   // 503 not_ready, or a proxy that cannot reach the worker yet
        }
        // Any other answer (200 that is not the worker's, 3xx, 4xx): the URL does not reach the worker.
        return self::HEALTH_NOT_CONFIGURED;
    }

    public function analyze(array $job): array
    {
        // The worker answers within this budget (it skips work rather than overrun it).
        $job['time_budget_seconds'] = $this->workerBudgetSeconds();
        $body = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $this->signature->signRequest('POST', '/v1/analyze', $body);
        $nonce = $headers[WorkerSignature::HEADER_NONCE];
        $responseHeaders = [];
        $response = '';
        $tooLarge = false;
        $curl = curl_init($this->url . '/v1/analyze');
        if ($curl === false) {
            throw new RuntimeException('worker_unavailable');
        }
        $httpHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > $this->maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ] + $this->caOption());
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        $connected = (float) curl_getinfo($curl, CURLINFO_CONNECT_TIME) > 0;
        if ($tooLarge) {
            throw new RuntimeException('worker_bad_response');
        }
        if ($ok === false || $status === 0) {
            throw new RuntimeException(match (true) {
                in_array($error, self::TLS_ERRORS, true) => 'worker_tls_failed',
                // Connected but no answer in time: this job may be too heavy, so it counts.
                $error === CURLE_OPERATION_TIMEDOUT && $connected => 'worker_timeout',
                // Connected, then the connection broke (nothing back, reset, partial answer):
                // the worker process died during this job.
                $connected && in_array($error, [18, 52, 55, 56], true) => 'worker_crashed',
                // Could not connect at all: the worker is down, which is not this job's fault.
                default => 'worker_unavailable',
            });
        }
        if ($status === 401) {
            throw new RuntimeException('worker_auth_failed');
        }
        $signature = $responseHeaders[strtolower(WorkerSignature::HEADER_RESPONSE_SIGNATURE)] ?? '';
        if (!$this->signature->verifyResponse($nonce, $status, $response, $signature)) {
            if ($signature !== '') {
                // Signed, but the signature does not match this answer: corrupted or altered.
                throw new RuntimeException('worker_bad_response');
            }
            // Unsigned: from the web server or proxy in front of the worker, or from the worker
            // before it could check the request. Never this job's fault.
            $answer = strlen($response) < 2000 ? json_decode($response, true) : null;
            $reason = is_array($answer) ? ($answer['error'] ?? null) : null;
            throw new RuntimeException(match (true) {
                $reason === 'not_configured' => 'worker_not_configured',
                $status === 500, $status === 502 => 'worker_crashed',     // the PHP process died
                $status === 504 => 'worker_timeout',                       // the web server gave up waiting
                $status >= 500, $status === 409 => 'worker_unavailable',   // 503, restarting; 409 replay: retry with a new nonce
                $status === 413 => 'worker_body_too_large',
                default => 'worker_proxy_error',
            });
        }
        if ($status >= 500) {
            // A signed answer may say why; only known codes are passed on.
            $answer = json_decode($response, true);
            throw new RuntimeException(match (is_array($answer) ? ($answer['error'] ?? null) : null) {
                'model_unavailable' => 'model_unavailable',
                'busy' => 'worker_busy',
                'model_timeout' => 'model_timeout',
                'worker_error' => 'worker_error',
                'not_configured' => 'worker_not_configured',
                'cleanup_failed' => 'worker_cleanup_failed',
                default => 'worker_unavailable',   // e.g. job_storage_failed: the worker's disk, not this job
            });
        }
        if ($status !== 200) {
            throw new RuntimeException('worker_rejected');
        }
        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('worker_bad_response');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('worker_bad_response');
        }
        return $decoded;
    }
}
