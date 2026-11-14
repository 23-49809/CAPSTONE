<?php
declare(strict_types=1);

require_once __DIR__ . '/DocumentAnalysis.php';
require_once __DIR__ . '/DocumentRequirements.php';

/**
 * Application side of AI document verification: the job queue in
 * ai_verification_jobs, building a job from a request's private uploads,
 * storing validated results and the Staff-only view of them.
 *
 * The AI processing (queue, claim, results) only ever writes ai_verification_jobs.
 * The one other write is a Staff action: recordOriginalOpened() adds an audit entry
 * when a Staff member opens an original. It never changes a request's status, never
 * approves or rejects, and never sends messages.
 */
final class AiVerification
{
    public const STATUSES = ['pending', 'processing', 'completed', 'failed', 'needs_manual_review'];
    /** Default lease: the default worker timeout (900 s) plus 120 s. The dispatcher passes its own. */
    public const LEASE_SECONDS = 1020;
    /** Worker outages retried before giving up: about three days at the 15-minute maximum delay. */
    public const MAX_TRANSIENT_FAILURES = 300;
    /** Times the worker may die (or its model fail) on one request before the job stops retrying. */
    public const MAX_CRASH_FAILURES = 3;
    private const MAX_TRANSIENT_DELAY_SECONDS = 900;
    /**
     * A job waiting on a setting is due again after a minute, so every dispatcher run
     * reports the problem (exit code 3) until it is fixed, and the job runs within a
     * minute of the fix.
     */
    private const CONFIGURATION_DELAY_SECONDS = 60;
    private const UPLOAD_PATH = '~^private_uploads/requests/[A-Za-z0-9_]+\.(?:pdf|jpg|jpeg|png|webp)$~';

    /*
     * How each error is retried (see retryClass()):
     *  - transient:     the worker or the network is down, or the worker is busy. Not caused by this
     *                   request, so it does NOT use an attempt; retried with a growing delay, up to
     *                   MAX_TRANSIENT_FAILURES.
     *  - crash:         the worker process died, or Ollama failed, while working on this request (after
     *                   the health check said ready). Could be an outage or could be this request, so it
     *                   does NOT use an attempt but is limited to MAX_CRASH_FAILURES per job.
     *  - configuration: a setting must be fixed (shared secret or clock, worker settings, TLS, a web-server
     *                   limit or a wrong URL, leftover files that cannot be removed). Does NOT use an
     *                   attempt; the dispatcher stops with exit code 3 so an administrator notices.
     *                   Retried every minute, without limit, so every run reports it.
     *  - counted:       possibly caused by this request (bad or invalid answer, model or worker timeout,
     *                   worker error, lost lease). Uses an attempt; failed after max_attempts.
     *  - permanent:     retrying cannot help (the worker refused this job, its files could not be
     *                   prepared). Failed at once.
     */
    public const RETRY_TRANSIENT = 'transient';
    public const RETRY_CRASH = 'crash';
    public const RETRY_CONFIGURATION = 'configuration';
    public const RETRY_COUNTED = 'counted';
    public const RETRY_PERMANENT = 'permanent';
    private const TRANSIENT = ['worker_unavailable', 'worker_busy'];
    private const CRASH = ['worker_crashed', 'model_unavailable'];
    private const CONFIGURATION = ['worker_auth_failed', 'worker_not_configured', 'worker_cleanup_failed', 'worker_tls_failed',
        'worker_body_too_large', 'worker_proxy_error'];
    private const COUNTED = ['worker_bad_response', 'invalid_worker_result', 'worker_error', 'worker_timeout', 'model_timeout', 'lease_expired'];

    /**
     * Privacy rule: the AI check only runs for requests submitted on or after
     * the date the Privacy Policy that describes it took effect. That date is
     * server configuration (never a built-in default), as an ISO 8601 time
     * with its offset, e.g. YYYY-MM-DDTHH:MM:SS+08:00. Unset or invalid: no
     * request is eligible, so nothing is queued, requeued or processed.
     */
    public const PRIVACY_EFFECTIVE_VARIABLE = 'SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT';
    public const INELIGIBLE_NOT_ENABLED = 'not_enabled';
    public const INELIGIBLE_BEFORE_EFFECTIVE_DATE = 'before_effective_date';

    /**
     * The configured Privacy Policy effective time as a Unix timestamp, or null
     * when it is unset or not a valid, complete date and time with an offset.
     */
    public static function privacyEffectiveFrom(?string $value = null): ?int
    {
        if ($value === null) {
            $configured = getenv(self::PRIVACY_EFFECTIVE_VARIABLE);
            $value = is_string($configured) ? $configured : '';
        }
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(Z|[+-](\d{2}):(\d{2}))$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59
            || (isset($m[8]) && $m[8] !== '' && ((int) $m[8] > 14 || (int) $m[9] > 59))) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Per request: whether the AI check may run, and if not, why. Staff only
     * (it is shown with the request). Keyed by request ID.
     *
     * @param list<int> $requestIds
     * @return array<int, array{eligible:bool, reason:?string, effectiveFrom:?string}>
     */
    public static function eligibility(PDO $pdo, array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn (int $id): bool => $id > 0)));
        if ($requestIds === []) {
            return [];
        }
        $from = self::privacyEffectiveFrom();
        $effective = $from === null ? null : gmdate('Y-m-d\TH:i:s\Z', $from);
        if ($from === null) {
            return array_fill_keys($requestIds, ['eligible' => false, 'reason' => self::INELIGIBLE_NOT_ENABLED, 'effectiveFrom' => null]);
        }
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $pdo->prepare("SELECT id, UNIX_TIMESTAMP(created_at) >= ? AS eligible FROM requests WHERE id IN ($placeholders)");
        $stmt->execute([$from, ...$requestIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eligible = (int) $row['eligible'] === 1;
            $result[(int) $row['id']] = ['eligible' => $eligible, 'reason' => $eligible ? null : self::INELIGIBLE_BEFORE_EFFECTIVE_DATE, 'effectiveFrom' => $effective];
        }
        return $result;
    }

    /** null when the request may be checked, otherwise INELIGIBLE_NOT_ENABLED or INELIGIBLE_BEFORE_EFFECTIVE_DATE. */
    public static function ineligibleReason(PDO $pdo, int $requestId): ?string
    {
        $state = self::eligibility($pdo, [$requestId])[$requestId] ?? ['reason' => self::INELIGIBLE_BEFORE_EFFECTIVE_DATE];
        return $state['reason'];
    }

    /**
     * Queues a request at submission, only if it may be checked (see
     * privacyEffectiveFrom()). Returns true when it was queued.
     */
    public static function enqueueIfEligible(PDO $pdo, int $requestId): bool
    {
        $from = self::privacyEffectiveFrom();
        if ($from === null || self::ineligibleReason($pdo, $requestId) !== null) {
            return false;
        }
        self::enqueue($pdo, $requestId, $from);
        return true;
    }

    /**
     * The distinct effective dates recorded on waiting jobs (pending or processing)
     * that differ from $from, including null for a job queued without one. Not
     * empty means the website queued jobs under a different date than this
     * dispatcher's setting: a configuration error, so nothing may be processed.
     * Reads only; never changes a job.
     *
     * @return list<?int>
     */
    public static function effectiveDateMismatches(PDO $pdo, int $from): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT privacy_effective_from FROM ai_verification_jobs
             WHERE status IN ('pending', 'processing') AND (privacy_effective_from IS NULL OR privacy_effective_from <> ?)
             LIMIT 5"
        );
        $stmt->execute([$from]);
        return array_map(static fn ($value): ?int => $value === null ? null : (int) $value, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** True when the migration has been run; submissions work the same without it. */
    public static function available(PDO $pdo): bool
    {
        static $known = [];
        $key = spl_object_id($pdo);
        if (!isset($known[$key])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_verification_jobs'");
            $stmt->execute();
            $known[$key] = (int) $stmt->fetchColumn() === 1;
        }
        return $known[$key];
    }

    /** error_code of a job an operator stopped because the effective date was corrected. */
    public const RETIRED_EFFECTIVE_DATE_CHANGED = 'effective_date_changed';

    /**
     * Operator recovery, step 1 (read only): the waiting jobs (pending or processing)
     * recorded under a different effective date than $from, or none. 'leased' marks a
     * job a dispatcher is still working on (processing, lease not yet expired): those
     * are never retired. With $forUpdate the rows are locked (inside a transaction).
     *
     * @return list<array{id:int, status:string, recorded:?int, leased:bool}>
     */
    public static function mismatchedJobs(PDO $pdo, int $from, bool $forUpdate = false): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, status, privacy_effective_from, (status = 'processing' AND lease_expires_at > NOW()) AS leased
             FROM ai_verification_jobs
             WHERE status IN ('pending', 'processing') AND (privacy_effective_from IS NULL OR privacy_effective_from <> ?)
             ORDER BY id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$from]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'status' => (string) $row['status'],
            'recorded' => $row['privacy_effective_from'] === null ? null : (int) $row['privacy_effective_from'], 'leased' => (int) $row['leased'] === 1],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The confirmation code for exactly these jobs, this dispatcher date ($from) and
     * the website date the operator supplied ($websiteFrom): "<number to retire>-<12 hex>".
     * Any change to the set (a job added, finished or claimed) or to either date gives a
     * different code. It is a fingerprint against acting on a stale preview, not a secret.
     *
     * @param list<array{id:int, status:string, recorded:?int, leased:bool}> $jobs
     */
    public static function retirementCode(array $jobs, int $from, int $websiteFrom): string
    {
        $retire = array_values(array_filter($jobs, static fn (array $job): bool => !$job['leased']));
        $material = 'dispatcher=' . $from . '|website=' . $websiteFrom . '|' . implode(',', array_map(static fn (array $job): string => $job['id'] . ':' . $job['status'] . ':' . ($job['recorded'] ?? 'none'), $retire));
        return count($retire) . '-' . substr(hash('sha256', $material), 0, 12);
    }

    /**
     * Operator recovery, step 2: marks the mismatched, not-leased waiting jobs as
     * failed (error_code effective_date_changed), only if the website date the operator
     * supplied ($websiteFrom) equals this dispatcher's configured date ($from, read
     * again here) and $confirmation equals the code of the current set (see
     * retirementCode()); otherwise changes nothing.
     * One transaction with the rows locked. Jobs are kept, never deleted; requests
     * and their status history are not touched; leased jobs and correctly dated jobs
     * are left as they are. When and why is written to the job's outcome_reason.
     * A dispatcher that still holds an old claim token can no longer change the job.
     * $invocation is systemd's INVOCATION_ID of the run (or null), recorded so the run
     * can be matched to sudo's log, which is the reliable record of who ran it.
     *
     * @return array{done:bool, reason:?string, retired:int, leased:int} reason when not done:
     *         'dates_differ' or 'confirmation_mismatch'
     */
    public static function retireMismatched(PDO $pdo, int $from, int $websiteFrom, string $confirmation, ?string $invocation): array
    {
        $pdo->beginTransaction();
        try {
            // Everything is checked again here, with the rows locked: both dates, then the code.
            if (self::privacyEffectiveFrom() !== $from || $websiteFrom !== $from) {
                $pdo->rollBack();
                return ['done' => false, 'reason' => 'dates_differ', 'retired' => 0, 'leased' => 0];
            }
            $jobs = self::mismatchedJobs($pdo, $from, true);
            if (!hash_equals(self::retirementCode($jobs, $from, $websiteFrom), $confirmation)) {
                $pdo->rollBack();
                return ['done' => false, 'reason' => 'confirmation_mismatch', 'retired' => 0, 'leased' => count(array_filter($jobs, static fn (array $job): bool => $job['leased']))];
            }
            $at = static fn (?int $epoch): string => $epoch === null ? 'none'
                : (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('+08:00'))->format('Y-m-d\TH:i:sP');
            $run = is_string($invocation) && preg_match('/^[a-f0-9]{32}$/', $invocation)
                ? 'systemd invocation ' . $invocation : 'no systemd invocation ID';
            $update = $pdo->prepare(
                "UPDATE ai_verification_jobs SET status = 'failed', error_code = :code, outcome_reason = :reason, claim_token = NULL, lease_expires_at = NULL
                 WHERE id = :id AND status IN ('pending', 'processing') AND NOT (status = 'processing' AND lease_expires_at > NOW())
                   AND (privacy_effective_from IS NULL OR privacy_effective_from <> :from)"
            );
            $retired = 0;
            $leased = 0;
            foreach ($jobs as $job) {
                if ($job['leased']) {
                    $leased++;
                    continue;
                }
                $update->execute(['code' => self::RETIRED_EFFECTIVE_DATE_CHANGED, 'id' => $job['id'], 'from' => $from,
                    'reason' => 'Stopped by an operator at ' . $at(time()) . ' with --retire-mismatched (' . $run . '; see sudo\'s log for who ran it): queued with AI effective date '
                        . $at($job['recorded']) . ', but the website and dispatcher date is ' . $at($from) . '.']);
                $retired += $update->rowCount();
            }
            $pdo->commit();
            return ['done' => true, 'reason' => null, 'retired' => $retired, 'leased' => $leased];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Queues a request once. A second call for the same request changes nothing.
     * Does not check eligibility: use enqueueIfEligible() or requeue(). Records the
     * effective date the website used ($effectiveFrom); without one the job is never
     * claimed and the dispatcher reports it.
     */
    public static function enqueue(PDO $pdo, int $requestId, ?int $effectiveFrom = null): void
    {
        $pdo->prepare('INSERT INTO ai_verification_jobs (request_id, privacy_effective_from) VALUES (:id, :from) ON DUPLICATE KEY UPDATE request_id = request_id')
            ->execute(['id' => $requestId, 'from' => $effectiveFrom]);
    }

    /**
     * Staff asked for the check to run (again). Refused while a worker holds
     * the job, and for a request that may not be checked (the Privacy Policy
     * effective date is unset, or the request is older). Previous results are
     * cleared so they are not mistaken for new ones.
     * Returns 'queued', 'in_progress', INELIGIBLE_NOT_ENABLED or INELIGIBLE_BEFORE_EFFECTIVE_DATE.
     */
    public static function requeue(PDO $pdo, int $requestId): string
    {
        $ineligible = self::ineligibleReason($pdo, $requestId);
        if ($ineligible !== null) {
            return $ineligible;   // nothing is changed: an existing job and its result stay as they are
        }
        $from = (int) self::privacyEffectiveFrom();   // set: the request was found eligible above
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, status, lease_expires_at > NOW() AS leased FROM ai_verification_jobs WHERE request_id = :id FOR UPDATE');
            $stmt->execute(['id' => $requestId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job && $job['status'] === 'processing' && (int) $job['leased'] === 1) {
                $pdo->commit();
                return 'in_progress';
            }
            if ($job) {
                $pdo->prepare(
                    "UPDATE ai_verification_jobs SET status = 'pending', outcome = NULL, outcome_reason = NULL, findings_json = NULL,
                            document_checks_json = NULL, model_name = NULL, model_digest = NULL, ocr_engine = NULL, attempts = 0, transient_failures = 0, crash_failures = 0,
                            privacy_effective_from = :from, next_attempt_at = NOW(), claim_token = NULL, lease_expires_at = NULL, started_at = NULL, completed_at = NULL,
                            error_code = NULL, staff_review_status = 'not_reviewed', reviewed_by = NULL, reviewed_at = NULL
                     WHERE id = :id"
                )->execute(['id' => $job['id'], 'from' => $from]);
            } else {
                self::enqueue($pdo, $requestId, $from);
            }
            $pdo->commit();
            return 'queued';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * True when at least one eligible job is waiting and due, so the dispatcher
     * can skip the worker check when idle. Jobs of requests submitted before
     * the Privacy Policy effective date are never due (they stay as they are).
     */
    public static function hasDueJob(PDO $pdo): bool
    {
        $from = self::privacyEffectiveFrom();
        if ($from === null) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT 1 FROM ai_verification_jobs j JOIN requests r ON r.id = j.request_id
             WHERE ((j.status = 'pending' AND j.next_attempt_at <= NOW()) OR (j.status = 'processing' AND j.lease_expires_at < NOW()))
               AND UNIX_TIMESTAMP(r.created_at) >= ? AND j.privacy_effective_from = ?
             LIMIT 1"
        );
        $stmt->execute([$from, $from]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Claims the next due job for one dispatcher. A job whose worker lease ran
     * out is claimed again (it counts as an attempt). Returns null when idle.
     * The lease must be longer than the dispatcher's HTTP timeout, so a job is
     * never claimed again while the first dispatcher still waits for its answer.
     * Only jobs of requests submitted on or after the Privacy Policy effective
     * date, and queued by the website under that same date, are claimed; with no
     * valid date, nothing is (others are left as they are).
     */
    public static function claim(PDO $pdo, int $leaseSeconds = self::LEASE_SECONDS): ?array
    {
        if ($leaseSeconds < 60 || $leaseSeconds > 7200) {
            throw new InvalidArgumentException('The job lease is out of range.');
        }
        $from = self::privacyEffectiveFrom();
        if ($from === null) {
            return null;
        }
        for ($tries = 0; $tries < 5; $tries++) {
            $pdo->beginTransaction();
            try {
                // Locks only the job row (OF j), never the request.
                $select = $pdo->prepare(
                    "SELECT j.id, j.request_id, j.status, j.attempts, j.max_attempts, j.transient_failures, j.crash_failures
                     FROM ai_verification_jobs j JOIN requests r ON r.id = j.request_id
                     WHERE ((j.status = 'pending' AND j.next_attempt_at <= NOW()) OR (j.status = 'processing' AND j.lease_expires_at < NOW()))
                       AND UNIX_TIMESTAMP(r.created_at) >= ? AND j.privacy_effective_from = ?
                     ORDER BY j.next_attempt_at, j.id LIMIT 1 FOR UPDATE OF j SKIP LOCKED"
                );
                $select->execute([$from, $from]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $pdo->commit();
                    return null;
                }
                if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
                    $pdo->prepare("UPDATE ai_verification_jobs SET status = 'failed', error_code = :code, claim_token = NULL, lease_expires_at = NULL WHERE id = :id")
                        ->execute(['id' => $row['id'], 'code' => $row['status'] === 'processing' ? 'lease_expired' : 'too_many_attempts']);
                    $pdo->commit();
                    continue;
                }
                $token = bin2hex(random_bytes(16));
                $pdo->prepare(
                    "UPDATE ai_verification_jobs SET status = 'processing', claim_token = :token, attempts = attempts + 1,
                            lease_expires_at = DATE_ADD(NOW(), INTERVAL :lease SECOND), started_at = NOW(), error_code = NULL
                     WHERE id = :id"
                )->execute(['token' => $token, 'lease' => $leaseSeconds, 'id' => $row['id']]);
                $pdo->commit();
                return ['id' => (int) $row['id'], 'request_id' => (int) $row['request_id'], 'attempts' => (int) $row['attempts'] + 1,
                    'max_attempts' => (int) $row['max_attempts'], 'transient_failures' => (int) $row['transient_failures'],
                    'crash_failures' => (int) $row['crash_failures'], 'claim_token' => $token, 'lease_seconds' => $leaseSeconds];
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
        return null;
    }

    /**
     * Everything needed to process a claimed job: the worker payload (only this
     * request's documents), the server-side requirement check and labels.
     */
    public static function buildJob(PDO $pdo, array $job, string $appRoot): array
    {
        $stmt = $pdo->prepare(
            'SELECT flow, is_owner, first_name, last_name, arp_number, document_type, transfer_type FROM requests WHERE id = :id'
        );
        $stmt->execute(['id' => $job['request_id']]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new RuntimeException('request_missing');
        }
        $rows = $pdo->prepare('SELECT doc_key, label, stored_path, mime_type, file_size, file_status FROM request_documents WHERE request_id = :id ORDER BY id');
        $rows->execute(['id' => $job['request_id']]);

        $uploadRoot = realpath($appRoot . '/private_uploads/requests');
        $uploaded = [];
        $labels = [];
        $documents = [];
        $applicationFindings = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) $row['doc_key'];
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key) || isset($uploaded[$key])) {
                continue;
            }
            $labels[$key] = (string) $row['label'];
            $uploaded[$key] = null;
            if ($row['file_status'] !== 'ok' || $row['stored_path'] === null) {
                continue;
            }
            $uploaded[$key] = ['mime' => (string) $row['mime_type'], 'size' => (int) $row['file_size']];
            $path = (string) $row['stored_path'];
            $real = preg_match(self::UPLOAD_PATH, $path) && $uploadRoot !== false ? realpath($appRoot . '/' . $path) : false;
            $bytes = $real !== false && str_starts_with($real, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($real)
                && filesize($real) < AiDocumentWorkerLimits::MAX_DOCUMENT_BYTES ? @file_get_contents($real) : false;
            if (!is_string($bytes) || $bytes === '' || count($documents) >= AiDocumentWorkerLimits::MAX_DOCUMENTS) {
                $applicationFindings[] = DocumentAnalysis::finding('ocr_uncertainty', 'other', [$key],
                    'The stored file for ' . $labels[$key] . ' could not be prepared for the automatic check. Open it and inspect it manually.', '', 'ocr');
                continue;
            }
            $documents[] = ['key' => $key, 'label' => $labels[$key], 'mime' => (string) $row['mime_type'], 'content_base64' => base64_encode($bytes)];
        }

        $requirements = (new DocumentRequirements())->evaluate((string) $request['flow'], (bool) $request['is_owner'], $uploaded);
        foreach ($requirements['items'] as $item) {
            $labels[$item['key']] = $item['label'];   // the server's requirement labels are authoritative
        }
        foreach ($documents as &$document) {
            $document['label'] = $labels[$document['key']];
        }
        unset($document);

        return [
            'payload' => [
                'version' => 1,
                'job_id' => $job['id'],
                'declared' => [
                    'flow' => (string) $request['flow'],
                    'is_owner' => (bool) $request['is_owner'],
                    'first_name' => (string) $request['first_name'],
                    'last_name' => (string) $request['last_name'],
                    'arp_number' => (string) ($request['arp_number'] ?? ''),
                    'document_type' => (string) ($request['document_type'] ?? ''),
                    'transfer_type' => (string) ($request['transfer_type'] ?? ''),
                ],
                'documents' => $documents,
            ],
            'requirements' => $requirements,
            'labels' => $labels,
            'keys' => array_column($documents, 'key'),
            'application_findings' => $applicationFindings,
        ];
    }

    /**
     * Processes one due job with $send (the worker client). Returns a summary
     * without any personal data, or null when there was nothing to do.
     * 'retry' tells the dispatcher how an error is handled (see retryClass()):
     * it stops its run after a transient or configuration error.
     *
     * @param Closure(array):array $send
     * @return null|array{job_id:int,status:string,outcome:?string,error:?string,retry:?string}
     */
    public static function processNext(PDO $pdo, string $appRoot, Closure $send, int $leaseSeconds = self::LEASE_SECONDS): ?array
    {
        $job = self::claim($pdo, $leaseSeconds);
        if ($job === null) {
            return null;
        }
        $failed = static fn (string $code): array => ['job_id' => $job['id'], 'status' => self::fail($pdo, $job, $code), 'outcome' => null,
            'error' => $code, 'retry' => self::retryClass($code)];
        try {
            $built = self::buildJob($pdo, $job, $appRoot);
        } catch (Throwable) {
            return $failed('job_build_failed');
        }

        if ($built['keys'] === []) {
            // Nothing to read: the requirement check alone decides.
            $worker = ['version' => 1, 'documents' => [], 'rule_findings' => [], 'model' => ['status' => 'skipped', 'name' => null, 'digest' => null, 'output' => null], 'ocr_engine' => 'none'];
        } else {
            try {
                $worker = DocumentAnalysis::validateWorkerResult($send($built['payload']), $built['keys']);
            } catch (UnexpectedValueException) {
                return $failed('invalid_worker_result');
            } catch (RuntimeException $exception) {
                $code = preg_match('/^[a-z_]{1,40}$/', $exception->getMessage()) ? $exception->getMessage() : 'worker_unavailable';
                return $failed($code);
            }
        }

        $result = DocumentAnalysis::classify($built['requirements'], $worker, $built['labels'], $built['application_findings']);
        // The model could not give a usable answer: keep what the rules found, and ask Staff to look.
        $status = $worker['model']['status'] === 'invalid_output' ? 'needs_manual_review' : 'completed';
        if ($status === 'needs_manual_review' && $result['outcome'] === DocumentAnalysis::OUTCOME_PASSED) {
            $result['outcome'] = DocumentAnalysis::OUTCOME_NEEDS_REVIEW;
        }
        $saved = self::store($pdo, $job, $status, $result, $worker, $status === 'needs_manual_review' ? 'model_output_invalid' : null);
        return ['job_id' => $job['id'], 'status' => $saved ? $status : 'lost_claim', 'outcome' => $saved ? $result['outcome'] : null,
            'error' => null, 'retry' => null];
    }

    /** How an error code is retried: one of the RETRY_* constants. Unknown codes are permanent. */
    public static function retryClass(string $code): string
    {
        return match (true) {
            in_array($code, self::TRANSIENT, true) => self::RETRY_TRANSIENT,
            in_array($code, self::CRASH, true) => self::RETRY_CRASH,
            in_array($code, self::CONFIGURATION, true) => self::RETRY_CONFIGURATION,
            in_array($code, self::COUNTED, true) => self::RETRY_COUNTED,
            default => self::RETRY_PERMANENT,
        };
    }

    /**
     * Records a failed try of a claimed job and returns its new status.
     *  - transient (including a busy worker): back to pending, the attempt is given back,
     *    and the delay grows 1, 2, 4, 8, then 15 minutes. After MAX_TRANSIENT_FAILURES
     *    the job fails as worker_outage_too_long, so a worker that stays busy (a stuck
     *    job holding its lock) cannot keep a job waiting forever.
     *  - crash: back to pending, the attempt is given back, delay 2 then 4 minutes; after
     *    MAX_CRASH_FAILURES the job fails as worker_crashed_repeatedly.
     *  - configuration: back to pending, the attempt is given back, retried after 1 minute.
     *  - counted: back to pending after 1, 2, 4... minutes until attempts run out, then failed.
     *  - permanent: failed.
     * Only the dispatcher that holds the claim token can change the job.
     */
    public static function fail(PDO $pdo, array $job, string $code): string
    {
        $class = self::retryClass($code);
        $refund = 0;
        $transient = 0;
        $crash = 0;
        if ($class === self::RETRY_CRASH) {
            $refund = 1;
            $crash = 1;
            $status = 'pending';
            $delay = min(self::MAX_TRANSIENT_DELAY_SECONDS, 120 * (2 ** min(10, (int) ($job['crash_failures'] ?? 0))));
            if ((int) ($job['crash_failures'] ?? 0) + 1 >= self::MAX_CRASH_FAILURES) {
                $status = 'failed';
                $code = 'worker_crashed_repeatedly';
            }
        } elseif ($class === self::RETRY_TRANSIENT || $class === self::RETRY_CONFIGURATION) {
            $refund = 1;
            $status = 'pending';
            if ($class === self::RETRY_CONFIGURATION) {
                $delay = self::CONFIGURATION_DELAY_SECONDS;
            } else {
                $transient = 1;
                $delay = min(self::MAX_TRANSIENT_DELAY_SECONDS, 60 * (2 ** min(10, (int) ($job['transient_failures'] ?? 0))));
                if ((int) ($job['transient_failures'] ?? 0) + 1 >= self::MAX_TRANSIENT_FAILURES) {
                    $status = 'failed';
                    $code = 'worker_outage_too_long';
                }
            }
        } elseif ($class === self::RETRY_COUNTED && $job['attempts'] < $job['max_attempts']) {
            $status = 'pending';
            $delay = min(3600, 60 * (2 ** max(0, $job['attempts'] - 1)));
        } else {
            $status = 'failed';
            $delay = 0;
        }
        $pdo->prepare(
            "UPDATE ai_verification_jobs SET status = :status, error_code = :code, claim_token = NULL, lease_expires_at = NULL,
                    attempts = GREATEST(CAST(attempts AS SIGNED) - :refund, 0), transient_failures = LEAST(transient_failures + :transient, 65535),
                    crash_failures = LEAST(crash_failures + :crash, 65535), next_attempt_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
             WHERE id = :id AND claim_token = :token AND status = 'processing'"
        )->execute(['status' => $status, 'code' => substr($code, 0, 60), 'refund' => $refund, 'transient' => $transient, 'crash' => $crash, 'delay' => $delay,
            'id' => $job['id'], 'token' => $job['claim_token']]);
        return $status;
    }

    /**
     * Audit entry written each time a Staff member opens or previews an uploaded original
     * (web/request-file.php). The AI result can only be marked reviewed by a Staff member
     * who has opened every uploaded original of the request (see markReviewed()), because
     * the AI can be wrong and only the originals decide. The entry names the reference and
     * the document ID, never the file's content or the applicant.
     */
    public const ORIGINAL_OPENED_ACTION = 'Request document opened';

    public static function originalOpenedTarget(string $reference, int $documentId): string
    {
        return $reference . ' document #' . $documentId;
    }

    /**
     * Records that Staff member $staffId opened document $documentId of request $reference:
     * once per Staff member and document, so previews never crowd other entries out of the
     * audit log.
     */
    public static function recordOriginalOpened(PDO $pdo, int $staffId, string $staffName, string $reference, int $documentId): void
    {
        $target = self::originalOpenedTarget($reference, $documentId);
        $pdo->prepare(
            "INSERT INTO audit_log (actor_type, actor_id, actor_name, action, target)
             SELECT 'staff', :id, :name, :action, :target FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM audit_log WHERE actor_type = 'staff' AND actor_id = :id2 AND action = :action2 AND target = :target2)"
        )->execute(['id' => $staffId, 'name' => $staffName, 'action' => self::ORIGINAL_OPENED_ACTION, 'target' => $target,
            'id2' => $staffId, 'action2' => self::ORIGINAL_OPENED_ACTION, 'target2' => $target]);
    }

    /**
     * IDs of the request's uploaded originals that Staff member $staffId has not opened yet
     * (only documents with a stored file count; a document the client did not upload has
     * nothing to open).
     *
     * @return list<int>
     */
    public static function unopenedOriginals(PDO $pdo, int $requestId, int $staffId): array
    {
        $stmt = $pdo->prepare(
            "SELECT rd.id FROM request_documents rd JOIN requests r ON r.id = rd.request_id
             WHERE rd.request_id = :request AND rd.file_status <> 'missing' AND rd.stored_path IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.actor_type = 'staff' AND a.actor_id = :staff AND a.action = :action
                                 AND a.target = CONCAT(r.reference_no, ' document #', rd.id))
             ORDER BY rd.id"
        );
        $stmt->execute(['request' => $requestId, 'staff' => $staffId, 'action' => self::ORIGINAL_OPENED_ACTION]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public const REVIEWED = 'reviewed';
    public const REVIEW_NOT_FINISHED = 'not_finished';
    public const REVIEW_ORIGINALS_NOT_OPENED = 'originals_not_opened';

    /**
     * Staff confirmed they looked at the result. Only for finished jobs, and only by a
     * Staff member who has opened every uploaded original of the request.
     * Returns REVIEWED, REVIEW_NOT_FINISHED or REVIEW_ORIGINALS_NOT_OPENED.
     */
    public static function markReviewed(PDO $pdo, int $requestId, int $staffId): string
    {
        $finished = $pdo->prepare("SELECT COUNT(*) FROM ai_verification_jobs WHERE request_id = :id AND status IN ('completed', 'needs_manual_review')");
        $finished->execute(['id' => $requestId]);
        if ((int) $finished->fetchColumn() !== 1) {
            return self::REVIEW_NOT_FINISHED;
        }
        if (self::unopenedOriginals($pdo, $requestId, $staffId) !== []) {
            return self::REVIEW_ORIGINALS_NOT_OPENED;
        }
        $stmt = $pdo->prepare(
            "UPDATE ai_verification_jobs SET staff_review_status = 'reviewed', reviewed_by = :staff, reviewed_at = NOW()
             WHERE request_id = :id AND status IN ('completed', 'needs_manual_review')"
        );
        $stmt->execute(['staff' => $staffId, 'id' => $requestId]);
        return $stmt->rowCount() === 1 ? self::REVIEWED : self::REVIEW_NOT_FINISHED;
    }

    /**
     * Staff-only display data, keyed by request ID. Only call this for Staff.
     *
     * @param list<int> $requestIds
     */
    public static function staffViews(PDO $pdo, array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn (int $id): bool => $id > 0)));
        if ($requestIds === [] || !self::available($pdo)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT j.request_id, j.status, j.outcome, j.outcome_reason, j.findings_json, j.document_checks_json, j.model_name,
                    j.ocr_engine, j.attempts, j.max_attempts, j.transient_failures, j.crash_failures, j.error_code, j.started_at, j.completed_at, j.next_attempt_at,
                    j.staff_review_status, j.reviewed_at, u.name AS reviewer_name
             FROM ai_verification_jobs j LEFT JOIN users u ON u.id = j.reviewed_by
             WHERE j.request_id IN ($placeholders)"
        );
        $stmt->execute($requestIds);
        $views = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $findings = json_decode((string) ($row['findings_json'] ?? '[]'), true);
            $documents = json_decode((string) ($row['document_checks_json'] ?? '[]'), true);
            $views[(int) $row['request_id']] = [
                'status' => (string) $row['status'],
                'outcome' => $row['outcome'] === null ? null : (string) $row['outcome'],
                'reason' => (string) ($row['outcome_reason'] ?? ''),
                'findings' => is_array($findings) ? array_values(array_map(static fn (array $f): array => [
                    'category' => (string) ($f['category'] ?? ''),
                    'field' => (string) ($f['field'] ?? ''),
                    'documents' => array_values(array_map('strval', (array) ($f['document_labels'] ?? []))),
                    'explanation' => (string) ($f['explanation'] ?? ''),
                    'evidence' => (string) ($f['evidence'] ?? ''),
                    'source' => (string) ($f['source'] ?? ''),
                ], array_filter($findings, 'is_array'))) : [],
                'documents' => is_array($documents) ? array_values(array_filter($documents, 'is_array')) : [],
                'model' => $row['model_name'] === null ? null : (string) $row['model_name'],
                'ocrEngine' => $row['ocr_engine'] === null ? null : (string) $row['ocr_engine'],
                'attempts' => (int) $row['attempts'],
                'maxAttempts' => (int) $row['max_attempts'],
                'workerRetries' => (int) $row['transient_failures'],
                'workerCrashes' => (int) $row['crash_failures'],
                'error' => $row['error_code'] === null ? null : self::errorMessage((string) $row['error_code']),
                'startedAt' => $row['started_at'],
                'completedAt' => $row['completed_at'],
                'nextAttemptAt' => $row['status'] === 'pending' ? $row['next_attempt_at'] : null,
                'reviewed' => $row['staff_review_status'] === 'reviewed',
                'reviewedBy' => $row['reviewer_name'] === null ? null : (string) $row['reviewer_name'],
                'reviewedAt' => $row['reviewed_at'],
            ];
        }
        return $views;
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'worker_unavailable' => 'The AI worker could not be reached. The check is retried automatically.',
            'worker_busy' => 'The AI worker was busy with another request. The check is retried automatically.',
            'model_unavailable' => 'The AI model on the worker was not available while checking this request. The check is retried a few times; if it keeps failing, an administrator must check Ollama.',
            'worker_crashed' => 'The AI worker stopped while checking this request. The check is retried a few times.',
            'worker_crashed_repeatedly' => 'The AI worker or its model stopped ' . self::MAX_CRASH_FAILURES . ' times while checking this request, so automatic retries stopped. Inspect the documents manually; an administrator should check the worker\'s logs.',
            'worker_auth_failed' => 'The AI worker refused the connection: the shared secret is not set up correctly. The check waits and is retried automatically, but an administrator must fix the configuration.',
            'worker_not_configured' => 'The AI worker\'s settings are not valid, so the check waits. An administrator must fix the worker configuration; the check then runs automatically.',
            'worker_cleanup_failed' => 'The AI worker could not delete files left from an interrupted check, so it is not taking new checks. An administrator must fix this; the check then runs automatically.',
            'worker_tls_failed' => 'The secure connection to the AI worker failed (certificate or CA settings). An administrator must fix the configuration; the check then runs automatically.',
            'worker_body_too_large' => 'A web server in front of the AI worker refused the documents as too large. An administrator must raise its limit; the check then runs automatically.',
            'worker_proxy_error' => 'The AI worker address answered with something that is not the AI worker (a wrong address or web-server setting). An administrator must fix the configuration; the check then runs automatically.',
            'worker_rejected' => 'The AI worker refused this job. Inspect the documents manually.',
            'worker_bad_response', 'invalid_worker_result' => 'The AI worker gave an answer that failed validation, so it was not used.',
            'worker_error' => 'The AI worker had an error while checking this request. The check is retried a limited number of times.',
            'worker_timeout' => 'The AI worker did not answer in time. The check is retried a limited number of times.',
            'model_timeout' => 'The AI model took too long on this request. The check is retried a limited number of times; if it keeps happening, an administrator should review the worker\'s time limits or model.',
            self::RETIRED_EFFECTIVE_DATE_CHANGED => 'This check was stopped by an administrator: it had been queued under a different AI effective date than the one now configured. Inspect the documents manually; if the request is eligible, run the check again.',
            'worker_outage_too_long' => 'The AI worker was unavailable for about three days, so automatic retries stopped. Run the check again when the worker is back, or inspect the documents manually.',
            'model_output_invalid' => 'The AI model did not give a valid answer, so only the automatic rule checks are shown.',
            'job_build_failed' => 'The request\'s documents could not be prepared for checking.',
            'lease_expired', 'too_many_attempts' => 'The check did not finish after several attempts.',
            default => 'The automatic check could not be completed.',
        };
    }

    private static function store(PDO $pdo, array $job, string $status, array $result, array $worker, ?string $errorCode): bool
    {
        $labels = [];
        foreach ($result['documents'] as $document) {
            $labels[$document['key']] = $document['label'];
        }
        $findings = array_map(static function (array $finding) use ($labels): array {
            $finding['document_labels'] = array_map(static fn (string $key): string => $labels[$key] ?? $key, $finding['document_keys']);
            return $finding;
        }, $result['findings']);
        $stmt = $pdo->prepare(
            "UPDATE ai_verification_jobs SET status = :status, outcome = :outcome, outcome_reason = :reason, findings_json = :findings,
                    document_checks_json = :documents, model_name = :model, model_digest = :digest, ocr_engine = :ocr,
                    completed_at = NOW(), claim_token = NULL, lease_expires_at = NULL, error_code = :error,
                    staff_review_status = 'not_reviewed', reviewed_by = NULL, reviewed_at = NULL
             WHERE id = :id AND claim_token = :token AND status = 'processing'"
        );
        $stmt->execute([
            'status' => $status,
            'outcome' => $result['outcome'],
            'reason' => mb_substr($result['reason'], 0, 500),
            'findings' => json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'documents' => json_encode($result['documents'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'model' => $worker['model']['name'],
            'digest' => $worker['model']['digest'],
            'ocr' => $worker['ocr_engine'],
            'error' => $errorCode,
            'id' => $job['id'],
            'token' => $job['claim_token'],
        ]);
        return $stmt->rowCount() === 1;
    }
}

/** Limits shared with the worker, without loading the worker's OCR code into the web app. */
final class AiDocumentWorkerLimits
{
    public const MAX_DOCUMENTS = 10;
    public const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;
}
