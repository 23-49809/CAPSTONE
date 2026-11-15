<?php
declare(strict_types=1);

require_once __DIR__ . '/AiVerification.php';
require_once __DIR__ . '/RemoteWorkerSignature.php';

/**
 * Website side of the remote (pull) AI worker, used only by web/ai-remote.php.
 *
 * A remote worker (bin/ai-remote-pull.php, e.g. on a Mac) connects OUT to the
 * website; nothing connects to it. It may only:
 *   claim     - take the next eligible job, exactly as the AWS dispatcher would
 *               (same eligibility: the Privacy Policy effective date and the
 *               job's recorded date; same lease and SKIP LOCKED claim), only
 *               while 'remote' is the selected processor, only while no other
 *               job holds a live claim (ONE active remote claim at a time), and
 *               within an hourly claim limit;
 *   document  - download ONE document of a job it holds a live claim on, found
 *               through AiVerification::jobManifest() (never a path it sends),
 *               within an hourly download limit;
 *   complete  - return a result, validated like the AWS worker's and then made
 *               untrusted (see distrustRemoteText()) before it is stored, only
 *               within the live lease;
 *   fail      - report one of a fixed set of error codes for its claim.
 * Every operation checks the claim token (constant-time comparison) and the
 * job's state in the database; nothing here trusts the remote worker.
 * Locks are always taken in the same order: the processor setting, then a job.
 * Nothing here logs or returns document text; requests are never approved,
 * rejected or changed.
 */
final class AiRemoteQueue
{
    public const DEFAULT_LEASE_SECONDS = 1020;
    public const MIN_LEASE_SECONDS = 300;
    public const MAX_LEASE_SECONDS = 3600;
    /** Kept from the lease for the downloads before and the upload after the worker's own budget. */
    public const WORKER_MARGIN_SECONDS = 120;
    /** At most this many jobs may be under a live remote claim at the same time. */
    public const MAX_ACTIVE_CLAIMS = 1;
    /*
     * Exposure limits (review H-A). Sized for SMART ASSESS's real volume, so a stolen secret can
     * reach only a handful of requests before the limits, the time window and the alerts stop it.
     * Every claim counts, whatever happens to it afterwards (completed, failed or expired), so
     * failing or completing jobs quickly never frees more claims.
     */
    public const DEFAULT_MAX_CLAIMS_PER_HOUR = 3;
    public const LIMIT_MAX_CLAIMS_PER_HOUR = 20;
    public const DEFAULT_MAX_CLAIMS_PER_DAY = 10;
    public const LIMIT_MAX_CLAIMS_PER_DAY = 50;
    public const DEFAULT_MAX_DOWNLOADS_PER_HOUR = 15;
    public const LIMIT_MAX_DOWNLOADS_PER_HOUR = 100;
    /** SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL may be at most this far ahead (longer windows are refused). */
    public const MAX_ENABLED_WINDOW_SECONDS = 31 * 86400;
    /** Fixed, server-controlled labels for a remote result (review H-B): never the remote worker's words. */
    public const REMOTE_MODEL_LABEL = 'remote, unverified';
    public const REMOTE_OCR_LABEL = 'remote worker';
    /**
     * The OCR error codes OcrService and AiDocumentWorker can produce. A remote result may only use
     * these; anything else becomes unreadable_document, so no remote-chosen word reaches Staff.
     */
    public const OCR_ERROR_CODES = ['file_missing', 'outside_upload_directory', 'file_unreadable', 'file_too_large', 'unsupported_mime_type',
        'temporary_processing_failed', 'extraction_failed', 'unreadable_document', 'image_too_large', 'deadline_exceeded', 'process_timeout',
        'output_limit_exceeded', 'missing_tool', 'untrusted_tool', 'render_failed', 'time_budget_exceeded'];
    /**
     * A remote worker may give a job back after a failed download this many times without using an
     * attempt; after that a failed download counts as an attempt, so a job cannot cycle forever.
     */
    public const REMOTE_TRANSIENT_LIMIT = 5;
    /**
     * Error codes a remote worker may report; anything else is refused (it cannot invent new retry
     * rules). worker_busy and worker_unavailable are not accepted: the remote worker runs one job at a
     * time and checks its own OCR and model before claiming.
     */
    public const FAIL_CODES = ['remote_download_failed', 'model_unavailable', 'model_timeout', 'worker_not_configured', 'worker_error', 'worker_timeout', 'worker_crashed'];
    /** Events kept in ai_remote_events (job IDs only; never names, text, addresses or secrets). */
    public const EVENTS = ['claim', 'download', 'complete', 'fail', 'rejected', 'replay', 'rate_limited', 'maintenance'];
    public const EVENT_RETENTION_SECONDS = 30 * 86400;

    /** The remote lease: SMART_ASSESS_AI_REMOTE_LEASE_SECONDS (300-3600), default 1020. */
    public static function leaseSeconds(): int
    {
        return self::setting('SMART_ASSESS_AI_REMOTE_LEASE_SECONDS', self::DEFAULT_LEASE_SECONDS, self::MIN_LEASE_SECONDS, self::MAX_LEASE_SECONDS);
    }

    /** SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_HOUR (1-20), default 3: jobs handed out per rolling hour. */
    public static function maxClaimsPerHour(): int
    {
        return self::setting('SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_HOUR', self::DEFAULT_MAX_CLAIMS_PER_HOUR, 1, self::LIMIT_MAX_CLAIMS_PER_HOUR);
    }

    /** SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_DAY (1-50), default 10: jobs handed out per rolling 24 hours. */
    public static function maxClaimsPerDay(): int
    {
        return self::setting('SMART_ASSESS_AI_REMOTE_MAX_CLAIMS_PER_DAY', self::DEFAULT_MAX_CLAIMS_PER_DAY, 1, self::LIMIT_MAX_CLAIMS_PER_DAY);
    }

    /** SMART_ASSESS_AI_REMOTE_MAX_DOWNLOADS_PER_HOUR (1-100), default 15: documents served per rolling hour. */
    public static function maxDownloadsPerHour(): int
    {
        return self::setting('SMART_ASSESS_AI_REMOTE_MAX_DOWNLOADS_PER_HOUR', self::DEFAULT_MAX_DOWNLOADS_PER_HOUR, 1, self::LIMIT_MAX_DOWNLOADS_PER_HOUR);
    }

    /**
     * The end of the agreed remote-processing window: SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL, a date and
     * time with offset (YYYY-MM-DDTHH:MM:SS+08:00). REQUIRED: unset, invalid, already past, or more
     * than MAX_ENABLED_WINDOW_SECONDS ahead all mean "closed" (the endpoint then answers 404).
     */
    public static function enabledUntil(int $now, ?string $value = null): ?int
    {
        $value ??= (string) getenv('SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL');
        $until = AiVerification::privacyEffectiveFrom($value);   // the same strict date-time-with-offset parser
        if ($until === null || $until <= $now || $until > $now + self::MAX_ENABLED_WINDOW_SECONDS) {
            return null;
        }
        return $until;
    }

    private static function setting(string $variable, int $default, int $min, int $max): int
    {
        $value = getenv($variable);
        if (!is_string($value) || !ctype_digit(trim($value))) {
            return $default;
        }
        return max($min, min($max, (int) trim($value)));
    }

    /**
     * Optional extra layer (never the only one): SMART_ASSESS_AI_REMOTE_ALLOWED_IPS, a comma-separated
     * list of IPv4/IPv6 addresses and IPv4 CIDR ranges. Empty or unset: every address may try (the
     * signature is still required). An invalid entry never matches.
     */
    public static function ipAllowed(string $ip, string $list): bool
    {
        $list = trim($list);
        if ($list === '') {
            return true;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(',', $list))) as $entry) {
            if (str_contains($entry, '/')) {
                [$network, $bits] = explode('/', $entry, 2);
                $net = @inet_pton($network);
                if ($net === false || strlen($net) !== 4 || strlen($packed) !== 4 || !ctype_digit($bits) || (int) $bits > 32) {
                    continue;
                }
                $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
                if ((unpack('N', $packed)[1] & $mask) === (unpack('N', $net)[1] & $mask)) {
                    return true;
                }
            } elseif (($entryPacked = @inet_pton($entry)) !== false && hash_equals($entryPacked, $packed)) {
                return true;
            }
        }
        return false;
    }

    /** Records one endpoint event (job ID only). Old events are removed now and then. */
    public static function recordEvent(PDO $pdo, string $event, ?int $jobId, int $now): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Unknown remote event.');
        }
        $pdo->prepare('INSERT INTO ai_remote_events (event, job_id, created_at) VALUES (:event, :job, :now)')
            ->execute(['event' => $event, 'job' => $jobId !== null && $jobId > 0 ? $jobId : null, 'now' => $now]);
        if (random_int(1, 50) === 1) {
            $pdo->prepare('DELETE FROM ai_remote_events WHERE created_at < :before')->execute(['before' => $now - self::EVENT_RETENTION_SECONDS]);
        }
    }

    public static function eventCount(PDO $pdo, string $event, int $since): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ai_remote_events WHERE event = :event AND created_at >= :since');
        $stmt->execute(['event' => $event, 'since' => $since]);
        return (int) $stmt->fetchColumn();
    }

    /** Event counts for monitoring (bin/ai-processor.php --status). @return array<string,int> */
    public static function eventSummary(PDO $pdo, int $since): array
    {
        $stmt = $pdo->prepare('SELECT event, COUNT(*) AS n FROM ai_remote_events WHERE created_at >= :since GROUP BY event');
        $stmt->execute(['since' => $since]);
        $summary = array_fill_keys(self::EVENTS, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary[(string) $row['event']] = (int) $row['n'];
        }
        return $summary;
    }

    /** Jobs under a live claim now (any processor). */
    public static function activeClaims(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM ai_verification_jobs WHERE status = 'processing' AND lease_expires_at > NOW()")->fetchColumn();
    }

    /**
     * Records an accepted request nonce. False when it was already seen (a replay).
     * Nonces older than twice the allowed clock skew are removed, since such requests
     * are refused by their timestamp anyway.
     */
    public static function rememberNonce(PDO $pdo, string $nonce, int $now): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return false;
        }
        $pdo->prepare('DELETE FROM ai_remote_nonces WHERE seen_at < :before')->execute(['before' => $now - 2 * RemoteWorkerSignature::MAX_SKEW_SECONDS]);
        try {
            $pdo->prepare('INSERT INTO ai_remote_nonces (nonce, seen_at) VALUES (:nonce, :now)')->execute(['nonce' => $nonce, 'now' => $now]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return false;
            }
            throw $exception;
        }
        return true;
    }

    /**
     * Claims the next eligible job for the remote worker.
     *
     * @return array{error:string}|array{job:null, active_claim?:bool, finished_without_documents?:int, retry_later?:bool}|array{job:array}
     */
    public static function claim(PDO $pdo, string $appRoot, int $leaseSeconds, ?int $now = null): array
    {
        $now ??= time();
        $from = AiVerification::privacyEffectiveFrom();
        if ($from === null) {
            return ['error' => 'not_enabled'];
        }
        if (AiVerification::effectiveDateMismatches($pdo, $from) !== []) {
            return ['error' => 'effective_date_mismatch'];
        }
        // One claimant at a time: the active-claim and rate checks and the claim itself run under a
        // named lock, so two concurrent requests can never both pass the checks.
        $locked = (int) $pdo->query("SELECT GET_LOCK(CONCAT('sa_ai_remote_claim:', DATABASE()), 10)")->fetchColumn();
        if ($locked !== 1) {
            return ['error' => 'claim_lock_unavailable'];
        }
        try {
            if (self::activeClaims($pdo) >= self::MAX_ACTIVE_CLAIMS) {
                return ['job' => null, 'active_claim' => true];
            }
            if (self::eventCount($pdo, 'claim', $now - 3600) >= self::maxClaimsPerHour()
                || self::eventCount($pdo, 'claim', $now - 86400) >= self::maxClaimsPerDay()) {
                self::recordEvent($pdo, 'rate_limited', null, $now);
                return ['error' => 'rate_limited'];
            }
            $job = AiVerification::claim($pdo, $leaseSeconds, null, AiVerification::PROCESSOR_REMOTE);
            if ($job === null) {
                return ['job' => null];
            }
            self::recordEvent($pdo, 'claim', $job['id'], $now);
            return self::prepareClaim($pdo, $appRoot, $job);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK(CONCAT('sa_ai_remote_claim:', DATABASE()))");
        }
    }

    private static function prepareClaim(PDO $pdo, string $appRoot, array $job): array
    {
        try {
            $manifest = AiVerification::jobManifest($pdo, $job, $appRoot);
        } catch (RuntimeException $exception) {
            AiVerification::fail($pdo, $job, $exception->getMessage() === 'stored_file_unreadable' ? 'stored_file_unreadable' : 'job_build_failed');
            return ['job' => null, 'retry_later' => true];
        } catch (Throwable) {
            AiVerification::fail($pdo, $job, 'job_build_failed');
            return ['job' => null, 'retry_later' => true];
        }
        if ($manifest['keys'] === []) {
            // Nothing to read: the requirement check alone decides, as on the AWS path.
            AiVerification::storeRemoteResult($pdo, $job, $manifest, AiVerification::noDocumentWorkerResult());
            return ['job' => null, 'finished_without_documents' => $job['id']];
        }
        $documents = [];
        foreach ($manifest['documents'] as $document) {
            $sha256 = @hash_file('sha256', $document['path']);
            if (!is_string($sha256)) {
                AiVerification::fail($pdo, $job, 'stored_file_unreadable');
                return ['job' => null, 'retry_later' => true];
            }
            $documents[] = ['key' => $document['key'], 'label' => $document['label'], 'mime' => $document['mime'], 'size' => $document['size'], 'sha256' => $sha256];
        }
        $row = self::jobRow($pdo, $job['id'], false);
        $remaining = (int) ($row['remaining'] ?? 0);
        return ['job' => [
            'id' => $job['id'],
            'claim_token' => $job['claim_token'],
            'lease_seconds_remaining' => $remaining,
            'time_budget_seconds' => max(30, min(1800, $remaining - self::WORKER_MARGIN_SECONDS)),
            'declared' => $manifest['declared'],
            'documents' => $documents,
        ]];
    }

    /**
     * One document of a job the caller holds a live claim on.
     *
     * @return array{error:string}|array{path:string, mime:string, size:int, sha256:string}
     */
    public static function document(PDO $pdo, string $appRoot, int $jobId, string $token, string $key, ?int $now = null): array
    {
        $now ??= time();
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key)) {
            return ['error' => 'unknown_document'];
        }
        $row = self::jobRow($pdo, $jobId, false);
        $state = self::claimState($row, $token);
        if ($state !== 'live') {
            return ['error' => $state];
        }
        if (AiVerification::processor($pdo) !== AiVerification::PROCESSOR_REMOTE) {
            return ['error' => 'processor_not_selected'];
        }
        try {
            $manifest = AiVerification::jobManifest($pdo, $row, $appRoot);
        } catch (Throwable) {
            return ['error' => 'document_unavailable'];
        }
        $document = null;
        foreach ($manifest['documents'] as $candidate) {
            if ($candidate['key'] === $key) {
                $document = $candidate;
            }
        }
        if ($document === null) {
            return ['error' => 'unknown_document'];
        }
        $sha256 = @hash_file('sha256', $document['path']);
        if (!is_string($sha256)) {
            return ['error' => 'document_unavailable'];
        }
        // The hourly limit, the once-per-claim record and the event are one atomic step: under a named
        // lock (so concurrent downloads cannot overshoot the limit) and with a primary key on
        // (job, claim, document) (so the same document is never served twice under one claim).
        $locked = (int) $pdo->query("SELECT GET_LOCK(CONCAT('sa_ai_remote_download:', DATABASE()), 10)")->fetchColumn();
        if ($locked !== 1) {
            return ['error' => 'download_lock_unavailable'];
        }
        try {
            if (self::eventCount($pdo, 'download', $now - 3600) >= self::maxDownloadsPerHour()) {
                self::recordEvent($pdo, 'rate_limited', $jobId, $now);
                return ['error' => 'rate_limited'];
            }
            try {
                $pdo->prepare('INSERT INTO ai_remote_downloads (job_id, claim_hash, doc_key, created_at) VALUES (:job, :claim, :key, :now)')
                    ->execute(['job' => $jobId, 'claim' => hash('sha256', $token), 'key' => $key, 'now' => $now]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    return ['error' => 'already_downloaded'];
                }
                throw $exception;
            }
            self::recordEvent($pdo, 'download', $jobId, $now);
            if (random_int(1, 50) === 1) {
                $pdo->prepare('DELETE FROM ai_remote_downloads WHERE created_at < :before')->execute(['before' => $now - self::EVENT_RETENTION_SECONDS]);
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK(CONCAT('sa_ai_remote_download:', DATABASE()))");
        }
        return ['path' => $document['path'], 'mime' => $document['mime'], 'size' => $document['size'], 'sha256' => $sha256];
    }

    /**
     * Stores the remote worker's result for its live claim, after the same validation as the
     * AWS worker's, made untrusted (distrustRemoteText()). Idempotent: repeating the completion
     * of an already stored claim answers 'already_stored' and changes nothing.
     * Lock order: the processor setting (shared), then the job (exclusive), the same order as
     * claim() and AiVerification::setProcessor(), so they can never deadlock each other.
     *
     * @return array{result:string, status?:string, outcome?:?string}
     */
    public static function complete(PDO $pdo, string $appRoot, int $jobId, string $token, mixed $result, ?int $now = null): array
    {
        $now ??= time();
        $pdo->beginTransaction();
        try {
            $processor = AiVerification::processor($pdo, true);
            $row = self::jobRow($pdo, $jobId, true);
            if ($row !== null && preg_match('/^[a-f0-9]{32}$/', $token) && is_string($row['remote_result_token'] ?? null)
                && hash_equals($row['remote_result_token'], $token) && in_array($row['status'], ['completed', 'needs_manual_review'], true)) {
                $pdo->commit();
                return ['result' => 'already_stored', 'status' => (string) $row['status']];
            }
            $state = self::claimState($row, $token);
            if ($state !== 'live') {
                $pdo->commit();
                return ['result' => $state];
            }
            if ($processor !== AiVerification::PROCESSOR_REMOTE) {
                $pdo->commit();
                return ['result' => 'processor_not_selected'];
            }
            try {
                $built = AiVerification::jobManifest($pdo, $row, $appRoot);
            } catch (RuntimeException $exception) {
                // The request's files cannot be prepared now: give the job back under the normal rules.
                $status = AiVerification::fail($pdo, $row, $exception->getMessage() === 'stored_file_unreadable' ? 'stored_file_unreadable' : 'job_build_failed');
                self::recordEvent($pdo, 'fail', $jobId, $now);
                $pdo->commit();
                return ['result' => 'not_stored', 'status' => $status];
            }
            try {
                $worker = DocumentAnalysis::validateWorkerResult($result, $built['keys']);
            } catch (UnexpectedValueException) {
                $status = AiVerification::fail($pdo, $row, 'invalid_worker_result');
                self::recordEvent($pdo, 'rejected', $jobId, $now);
                $pdo->commit();
                return ['result' => 'rejected', 'status' => $status];
            }
            $summary = AiVerification::storeRemoteResult($pdo, $row, $built, self::distrustRemoteText($worker, $built['labels']));
            if ($summary['status'] !== 'lost_claim') {
                self::recordEvent($pdo, 'complete', $jobId, $now);
            }
            $pdo->commit();
            return $summary['status'] === 'lost_claim'
                ? ['result' => 'stale']
                : ['result' => 'stored', 'status' => (string) $summary['status'], 'outcome' => $summary['outcome']];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * A remote worker is less trusted than the worker on the website's own server: the website
     * never sees the OCR text, so it cannot check anything the remote worker quotes or words.
     * Therefore, for a remote result:
     *  - every "evidence" quote (model and rule findings) is removed, so no text the remote worker
     *    sent is ever shown to Staff as "text read from the document";
     *  - every rule finding is re-worded here from its validated codes (category, field, document
     *    labels) and marked as reported by the remote worker; its own wording is discarded;
     *  - the model name and OCR engine are replaced by fixed labels (REMOTE_MODEL_LABEL,
     *    REMOTE_OCR_LABEL) and the digest is dropped;
     *  - OCR error codes outside OCR_ERROR_CODES become unreadable_document.
     * Model findings are already worded by the server (DocumentAnalysis::classify()), and a remote
     * outcome is never "passed" (AiVerification::storeRemoteResult()).
     *
     * @param array<string,string> $labels document key => label
     */
    public static function distrustRemoteText(array $worker, array $labels): array
    {
        $label = static fn (array $keys): string => $keys === [] ? 'the documents' : implode(', ', array_map(static fn (string $key): string => $labels[$key] ?? $key, $keys));
        foreach ($worker['rule_findings'] as &$finding) {
            $documents = $label($finding['document_keys']);
            $finding['explanation'] = 'Automatic check reported by the remote AI worker (unverified): ' . match ($finding['field']) {
                'name' => 'the applicant\'s surname may not appear in the text read from ' . $documents . '.',
                'date' => 'a date in ' . $documents . ' may not exist on the calendar.',
                'property_identifier' => 'the ARP/TCT number entered in the form may not appear in the text read from ' . $documents . '.',
                default => 'a possible issue in ' . $documents . '.',
            } . ' Compare the original yourself.';
            $finding['evidence'] = '';
        }
        unset($finding);
        if (is_array($worker['model']['output'] ?? null)) {
            foreach ($worker['model']['output']['findings'] as &$finding) {
                $finding['evidence'] = '';
            }
            unset($finding);
        }
        // Review H-B: the model name, digest, OCR engine and OCR error codes are shown to Staff (or worded
        // into findings), so none of them may come from the remote worker.
        if (($worker['model']['name'] ?? null) !== null) {
            $worker['model']['name'] = self::REMOTE_MODEL_LABEL;
        }
        $worker['model']['digest'] = null;
        $worker['ocr_engine'] = self::REMOTE_OCR_LABEL;
        foreach ($worker['documents'] as &$document) {
            if ($document['ocr_error'] !== null && !in_array($document['ocr_error'], self::OCR_ERROR_CODES, true)) {
                $document['ocr_error'] = 'unreadable_document';
            }
        }
        unset($document);
        return $worker;
    }

    /**
     * Records a failure the remote worker reports for its claim, with the normal retry rules and
     * two limits against endless cycling: "not configured" after a claim uses an attempt (the
     * worker checks its settings before claiming), and after REMOTE_TRANSIENT_LIMIT failed
     * downloads of one job a failed download uses an attempt too. Accepted while the claim token
     * is current, even after the lease ran out; refused for any code not in FAIL_CODES.
     *
     * @return array{result:string, status?:string}
     */
    public static function fail(PDO $pdo, int $jobId, string $token, string $code, ?int $now = null): array
    {
        $now ??= time();
        if (!in_array($code, self::FAIL_CODES, true)) {
            return ['result' => 'invalid_code'];
        }
        $row = self::jobRow($pdo, $jobId, false);
        $state = self::claimState($row, $token);
        if ($state !== 'live' && $state !== 'lease_expired') {
            return ['result' => $state];
        }
        if ($code === 'worker_not_configured'
            || ($code === 'remote_download_failed' && $row['transient_failures'] >= self::REMOTE_TRANSIENT_LIMIT)) {
            $code = 'worker_error';   // counted: uses an attempt
        }
        $status = AiVerification::fail($pdo, $row, $code);
        self::recordEvent($pdo, 'fail', $jobId, $now);
        return ['result' => 'recorded', 'status' => $status, 'code' => $code];
    }

    /** 'live', 'lease_expired', 'stale' (not this claim, or no longer processing) or 'unknown'. */
    private static function claimState(?array $row, string $token): string
    {
        if ($row === null) {
            return 'unknown';
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_string($row['claim_token']) || !hash_equals($row['claim_token'], $token)
            || $row['status'] !== 'processing') {
            return 'stale';
        }
        return (int) $row['live'] === 1 ? 'live' : 'lease_expired';
    }

    private static function jobRow(PDO $pdo, int $jobId, bool $forUpdate): ?array
    {
        if ($jobId < 1) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT id, request_id, status, attempts, max_attempts, transient_failures, crash_failures, claim_token, remote_result_token,
                    lease_expires_at > NOW() AS live, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), lease_expires_at)) AS remaining
             FROM ai_verification_jobs WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        foreach (['id', 'request_id', 'attempts', 'max_attempts', 'transient_failures', 'crash_failures'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }
}

/**
 * Monitoring for events that happen BEFORE a request is authenticated (so they must not touch
 * the database): refused addresses, failed signatures, requests outside the window. Counts are
 * kept in one small file and at most one summary line per kind is logged per minute, so an
 * attacker cannot flood the log. Lines have the stable prefix "[SMART_ASSESS_REMOTE] monitor:"
 * for alerting. Never logs an address, header, body, nonce or secret.
 *
 * Storage (review L-A): an application-owned private folder, by default
 * smart-assess-app/logs/ai-remote-monitor (never the shared temporary folder):
 *  - the folder is created 0700 when missing; an existing one must be a real folder (not a
 *    symbolic link) owned by the web-server user, and is tightened to 0700 if it is looser;
 *  - the counter file must not be a symbolic link; it is created 0600 under umask 077, opened,
 *    and then checked to be the same file as the path (device and inode) and owned by the
 *    web-server user; it is tightened to 0600 if it is looser;
 *  - if any of this fails the counter is not used (fail closed), and web/ai-remote.php refuses
 *    every request with 404 (ready() is false), so the endpoint never runs without monitoring.
 */
final class AiRemoteMonitor
{
    public const INTERVAL_SECONDS = 60;
    public const KINDS = ['unauthenticated', 'address_refused', 'rate_limited', 'replay', 'maintenance', 'outside_window', 'claim_outside_window'];
    public const COUNTER_FILE = 'smart-assess-remote-monitor.json';

    /** smart-assess-app/logs/ai-remote-monitor (application-owned; outside the web root). */
    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 2) . '/logs/ai-remote-monitor';
    }

    /** True when the monitoring folder exists (or was created) and is private: the endpoint refuses to run otherwise. */
    public static function ready(?string $directory = null): bool
    {
        return self::secureDirectory($directory ?? self::defaultDirectory());
    }

    /** Counts one event of $kind. False (nothing written) when the folder or counter file cannot be secured. */
    public static function note(string $kind, ?string $directory = null, ?int $now = null): bool
    {
        if (!in_array($kind, self::KINDS, true)) {
            return false;
        }
        $now ??= time();
        $directory = rtrim($directory ?? self::defaultDirectory(), '/');
        $handle = self::secureDirectory($directory) ? self::openCounter($directory . '/' . self::COUNTER_FILE) : null;
        if ($handle === null) {
            error_log('[SMART_ASSESS_REMOTE] monitor: unavailable (the monitoring folder or counter file is missing, a link, not owned by the web server or not private); one '
                . $kind . ' event was not counted.');
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $state = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];
            $entry = is_array($state[$kind] ?? null) ? $state[$kind] : ['count' => 0, 'since' => $now, 'logged' => 0];
            $entry['count'] = (int) $entry['count'] + 1;
            if ($now - (int) $entry['logged'] >= self::INTERVAL_SECONDS) {
                error_log(sprintf('[SMART_ASSESS_REMOTE] monitor: %d %s event(s) since %s', $entry['count'], $kind, gmdate('Y-m-d\TH:i:s\Z', (int) $entry['since'])));
                $entry = ['count' => 0, 'since' => $now, 'logged' => $now];
            }
            $state[$kind] = $entry;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);
            flock($handle, LOCK_UN);
            return true;
        } finally {
            fclose($handle);
        }
    }

    private static function secureDirectory(string $directory): bool
    {
        if ($directory === '' || $directory[0] !== '/' || str_contains($directory, "\0")) {
            return false;
        }
        clearstatcache(true, $directory);
        if (!is_link($directory) && !file_exists($directory)) {
            $mask = umask(0o077);
            $made = @mkdir($directory, 0700);
            umask($mask);
            if (!$made) {
                return false;
            }
            clearstatcache(true, $directory);
        }
        $info = @lstat($directory);
        if ($info === false || is_link($directory) || ($info['mode'] & 0o170000) !== 0o040000 || !self::ownedByMe((int) $info['uid'])) {
            return false;
        }
        if (($info['mode'] & 0o777) !== 0o700 && !@chmod($directory, 0700)) {
            return false;
        }
        clearstatcache(true, $directory);
        return (fileperms($directory) & 0o777) === 0o700 && !is_link($directory);
    }

    /** @return resource|null the opened counter file, or null when it cannot be secured */
    private static function openCounter(string $file)
    {
        clearstatcache(true, $file);
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            return null;
        }
        $mask = umask(0o077);
        $handle = @fopen($file, 'c+');
        umask($mask);
        if ($handle === false) {
            return null;
        }
        $opened = fstat($handle);
        clearstatcache(true, $file);
        $named = @lstat($file);
        if ($opened === false || $named === false || is_link($file) || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
            || ($named['mode'] & 0o170000) !== 0o100000 || !self::ownedByMe((int) $named['uid'])
            || (($named['mode'] & 0o777) !== 0o600 && !@chmod($file, 0600))) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private static function ownedByMe(int $uid): bool
    {
        return !function_exists('posix_geteuid') || $uid === posix_geteuid();
    }
}
