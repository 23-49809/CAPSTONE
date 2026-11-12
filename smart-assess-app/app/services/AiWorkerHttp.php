<?php
declare(strict_types=1);

require_once __DIR__ . '/AiDocumentWorker.php';
require_once __DIR__ . '/WorkerSignature.php';
require_once __DIR__ . '/WorkerStateCleaner.php';

/**
 * HTTP handling for the private AI worker. One route: POST /v1/analyze.
 * Every request must be signed (WorkerSignature), fresh and never seen before;
 * one job runs at a time. Responses are signed. Logs carry only the job ID and
 * an error code, never document content or applicant details.
 */
final class AiWorkerHttp
{
    public const PATH = '/v1/analyze';

    /** Health answers (the "error" field of {"ok":false,...}). */
    public const NOT_READY = 'not_ready';            // temporary: e.g. Ollama not answering yet
    public const NOT_CONFIGURED = 'not_configured';  // an administrator must fix a setting
    public const CLEANUP_FAILED = 'cleanup_failed';  // files left by an interrupted job could not be removed
    public const STUCK = 'stuck';                    // a job has held the lock longer than any job may run

    /**
     * @param null|Closure():(bool|string) $ready health check: true when the worker can take a job;
     *        false or NOT_READY when it cannot yet; NOT_CONFIGURED when a setting is wrong. Without
     *        it, health only says the worker answers.
     * @param int $abandonedAfterSeconds job folders untouched this long, found while holding the
     *        worker lock, are removed as abandoned (see WorkerStateCleaner)
     * @param int $stuckAfterSeconds a job holding the lock longer than this is reported as STUCK
     *        (the longest job plus a margin; worker/index.php passes MAX_JOB_SECONDS + 120)
     */
    public function __construct(
        private readonly WorkerSignature $signature,
        private readonly Closure $workerFactory,
        private readonly string $stateDirectory,
        private readonly int $maxBodyBytes = 75 * 1024 * 1024,
        private readonly ?Closure $ready = null,
        private readonly int $abandonedAfterSeconds = 60,
        private readonly int $stuckAfterSeconds = 1020
    ) {
    }

    /**
     * @param array<string,string> $headers header name => value (any case)
     * @return array{0:int,1:array<string,string>,2:string}
     */
    public function handle(string $method, string $path, array $headers, string $body, ?int $now = null): array
    {
        $now ??= time();
        $headers = array_change_key_case($headers, CASE_LOWER);
        $nonce = (string) ($headers[strtolower(WorkerSignature::HEADER_NONCE)] ?? '');
        if ($path === '/v1/health' && $method === 'GET') {
            // No details and no authentication: only "ready", "not ready" or which kind of
            // problem, so the dispatcher can wait (or report a setting to fix) without claiming
            // jobs. Jobs themselves are always authenticated.
            $state = $this->healthState();
            return $state === null ? $this->respond(200, ['ok' => true], null) : $this->respond(503, ['ok' => false, 'error' => $state], null);
        }
        if ($path !== self::PATH) {
            return $this->respond(404, ['error' => 'not_found'], null);
        }
        if ($method !== 'POST') {
            return $this->respond(405, ['error' => 'method_not_allowed'], null);
        }
        if (strlen($body) > $this->maxBodyBytes) {
            return $this->respond(413, ['error' => 'too_large'], null);
        }
        $valid = $this->signature->verifyRequest(
            $method,
            $path,
            $body,
            (string) ($headers[strtolower(WorkerSignature::HEADER_TIMESTAMP)] ?? ''),
            $nonce,
            (string) ($headers[strtolower(WorkerSignature::HEADER_SIGNATURE)] ?? ''),
            $now
        );
        if (!$valid) {
            error_log('[SMART_ASSESS_WORKER] Rejected an unauthenticated request.');
            return $this->respond(401, ['error' => 'unauthorized'], null);
        }
        if (!$this->rememberNonce($nonce, $now)) {
            return $this->respond(409, ['error' => 'replayed'], null);
        }

        try {
            $job = AiDocumentWorker::validateJob(json_decode($body, true, 16, JSON_THROW_ON_ERROR));
        } catch (JsonException | UnexpectedValueException) {
            return $this->respond(400, ['error' => 'invalid_job'], $nonce);
        }

        $lock = @fopen($this->stateDirectory . DIRECTORY_SEPARATOR . 'worker.lock', 'c');
        if ($lock === false) {
            return $this->respond(503, ['error' => self::NOT_CONFIGURED], $nonce);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return $this->respond(503, ['error' => 'busy'], $nonce);
        }
        // When this job took the lock, so the health check can tell a stuck job from a long one.
        self::stampLock($lock);
        try {
            // Holding the lock, so no other job runs: remove what an interrupted job left behind.
            if (!$this->sweepAbandoned()) {
                return $this->respond(503, ['error' => self::CLEANUP_FAILED], $nonce);
            }
            try {
                $worker = ($this->workerFactory)();
            } catch (Throwable) {
                // A setting the startup check missed: not this job's fault.
                error_log('[SMART_ASSESS_WORKER] Job ' . $job['job_id'] . ' refused: the worker is not configured correctly.');
                return $this->respond(503, ['error' => self::NOT_CONFIGURED], $nonce);
            }
            $result = $worker->run($job);
            return $this->respond(200, $result, $nonce);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'worker_not_configured') {
                error_log('[SMART_ASSESS_WORKER] Job ' . $job['job_id'] . ' refused: the worker is not configured correctly.');
                return $this->respond(503, ['error' => self::NOT_CONFIGURED], $nonce);
            }
            $code = in_array($exception->getMessage(), ['model_unavailable', 'model_timeout', 'job_storage_failed'], true) ? $exception->getMessage() : 'worker_error';
            error_log('[SMART_ASSESS_WORKER] Job ' . $job['job_id'] . ' failed: ' . $code . '.');
            return $this->respond(503, ['error' => $code], $nonce);
        } catch (Throwable) {
            error_log('[SMART_ASSESS_WORKER] Job ' . $job['job_id'] . ' failed: worker_error.');
            return $this->respond(500, ['error' => 'worker_error'], $nonce);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * null when ready, otherwise NOT_READY, NOT_CONFIGURED or CLEANUP_FAILED. When no
     * job is running, folders left by interrupted jobs are removed first, so this also
     * cleans up after a killed request even when no new job arrives.
     */
    private function healthState(): ?string
    {
        $lock = @fopen($this->stateDirectory . DIRECTORY_SEPARATOR . 'worker.lock', 'c');
        if ($lock === false) {
            return self::NOT_CONFIGURED;   // the state folder is missing or not writable
        }
        // A running job holds the lock; then nothing is cleaned now (the next free moment will).
        $free = flock($lock, LOCK_EX | LOCK_NB);
        try {
            if ($free) {
                // Every holder records when it took the lock, this health check included, so an
                // overlapping health check never mistakes the previous job's time for this holder's.
                self::stampLock($lock);
                if (!$this->sweepAbandoned()) {
                    return self::CLEANUP_FAILED;
                }
            } else {
                $held = $this->lockHeldSeconds();
                if ($held !== null && $held > $this->stuckAfterSeconds) {
                    // A new holder writes its time right after taking the lock: look again shortly,
                    // so a lock taken a moment ago is not reported with the previous holder's time.
                    usleep(250_000);
                    $again = @fopen($this->stateDirectory . DIRECTORY_SEPARATOR . 'worker.lock', 'c');
                    $released = $again !== false && flock($again, LOCK_SH | LOCK_NB);
                    if ($again !== false) {
                        if ($released) {
                            flock($again, LOCK_UN);
                        }
                        fclose($again);
                    }
                    $held = $released ? null : $this->lockHeldSeconds();
                    if ($held !== null && $held > $this->stuckAfterSeconds) {
                        error_log('[SMART_ASSESS_WORKER] A job has held the worker lock for ' . $held . ' s, longer than any job may run; restart php-fpm on the worker.');
                        return self::STUCK;
                    }
                }
            }
        } finally {
            if ($free) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
        if ($this->ready === null) {
            return null;
        }
        try {
            $ready = ($this->ready)();
        } catch (Throwable) {
            return self::NOT_READY;
        }
        return match (true) {
            $ready === true => null,
            $ready === self::NOT_CONFIGURED => self::NOT_CONFIGURED,
            default => self::NOT_READY,
        };
    }

    /** Records, in the lock file, when the current holder took the lock. The caller holds it. */
    private static function stampLock($lock): void
    {
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, (string) time());
        fflush($lock);
    }

    /** Seconds the current holder has held the worker lock, from its recorded time; null if unknown. */
    private function lockHeldSeconds(): ?int
    {
        $path = $this->stateDirectory . DIRECTORY_SEPARATOR . 'worker.lock';
        clearstatcache(true, $path);
        $content = trim((string) @file_get_contents($path));
        $since = ctype_digit($content) ? (int) $content : (int) @filemtime($path);
        return $since > 0 ? time() - $since : null;
    }

    /** Removes folders left by interrupted jobs. The caller holds the worker lock. False if some could not be removed. */
    private function sweepAbandoned(): bool
    {
        try {
            return (new WorkerStateCleaner($this->stateDirectory, $this->abandonedAfterSeconds))->sweep()['failed'] === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Records a nonce; false if it was already used. Old nonces are pruned. */
    private function rememberNonce(string $nonce, int $now): bool
    {
        $directory = $this->stateDirectory . DIRECTORY_SEPARATOR . 'nonces';
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            return false;
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if ((int) @filemtime($file) < $now - 2 * WorkerSignature::MAX_SKEW_SECONDS) {
                @unlink($file);
            }
        }
        $handle = @fopen($directory . DIRECTORY_SEPARATOR . $nonce, 'x');
        if ($handle === false) {
            return false;
        }
        fclose($handle);
        return true;
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    private function respond(int $status, array $payload, ?string $nonce): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];
        if ($nonce !== null) {
            $headers[WorkerSignature::HEADER_RESPONSE_SIGNATURE] = $this->signature->signResponse($nonce, $status, $body);
        }
        return [$status, $headers, $body];
    }
}
