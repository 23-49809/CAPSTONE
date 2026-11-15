<?php
declare(strict_types=1);

/*
 * SMART ASSESS remote AI worker (pull): runs on a machine such as a Mac, connects OUT to
 * the website over HTTPS, and processes AI jobs with that machine's own Tesseract/Poppler
 * and Ollama. Nothing connects to this machine; it opens no port.
 *
 *   php bin/ai-remote-pull.php [--once] [--max-jobs=N] [--poll-seconds=N] [--max-runtime=N]
 *
 * --once           one cycle: claim at most one job, then exit (for tests and supervised runs)
 * --max-jobs=N     stop after N jobs (default: no limit)
 * --poll-seconds=N wait between empty polls, 10-3600 (default 30)
 * --max-runtime=N  stop after N seconds (default: no limit)
 *
 * Settings (environment). The secret is read from a file, never from the environment:
 *   SMART_ASSESS_AI_REMOTE_URL          https://<site>/ai-remote.php (plain http only for loopback tests)
 *   SMART_ASSESS_AI_REMOTE_SECRET_FILE  absolute path, mode 0600, owned by this user
 *   SMART_ASSESS_AI_REMOTE_CA_FILE      optional private CA certificate
 *   SMART_ASSESS_WORKER_STATE_DIR       private 0700 folder for job files and the lock
 *   SMART_ASSESS_WORKER_OLLAMA_MODEL    e.g. llama3.2:3b (exactly as "ollama list" shows it)
 *   and the same optional SMART_ASSESS_WORKER_* settings as worker/index.php: OLLAMA_URL
 *   (loopback or HTTPS), OLLAMA_TIMEOUT, OLLAMA_CONTEXT_TOKENS, OLLAMA_MAX_OUTPUT_TOKENS,
 *   OLLAMA_MAX_RESPONSE_BYTES, MAX_INPUT_CHARS, MAX_JOB_SECONDS, OCR_LANGUAGES,
 *   OCR_PROCESS_TIMEOUT, OCR_TOTAL_TIMEOUT, TESSERACT_PATH, PDFINFO_PATH, PDFTOTEXT_PATH, PDFTOPPM_PATH.
 *
 * Safety:
 *  - Claims nothing unless the local OCR tools and the model are ready, and the website
 *    reports that 'remote' is the selected processor.
 *  - One instance at a time (lock file in the state folder); folders left by an
 *    interrupted job are removed before any new claim.
 *  - The lease is tracked on the wall clock: after sleep or a lost connection, a result
 *    whose lease has run out is never sent (the website would refuse it anyway), and the
 *    job is processed again by a later claim.
 *  - Documents are kept in memory and in the job folder only while the job runs.
 *  - Prints job IDs, statuses, counts and timings only: never names, numbers or text.
 *
 * Exit codes: 0 done; 2 not configured; 3 the website refused (disabled, authentication,
 * processor not selected, AI not enabled); 75 temporary (website unreachable, local OCR or
 * model not ready, another instance running).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$app = dirname(__DIR__);
require_once $app . '/app/services/AiRemoteClient.php';
require_once $app . '/app/services/AiDocumentWorker.php';
require_once $app . '/app/services/OllamaClient.php';
require_once $app . '/app/services/WorkerStateCleaner.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Up to 10 documents of 5 MB each, also held base64-encoded for the worker.
ini_set('memory_limit', '512M');

const SA_PULL_EXIT_NOT_CONFIGURED = 2;
const SA_PULL_EXIT_REFUSED = 3;
const SA_PULL_EXIT_TEMPORARY = 75;
/** A result is sent only if at least this much of the lease is left (upload and storage). */
const SA_PULL_SEND_MARGIN_SECONDS = 30;
/** Pauses between deliveries of one result (null: last try). */
const SA_PULL_DELIVERY_PAUSES = [5, 10, 20, 40, null];

$say = static function (string $line): void {
    fwrite(STDOUT, '[' . gmdate('Y-m-d\TH:i:s\Z') . '] ' . $line . "\n");
};

foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--once' && !preg_match('/^--(max-jobs|poll-seconds|max-runtime)=[1-9][0-9]{0,6}$/', $argument)) {
        fwrite(STDERR, 'Unknown or invalid argument: ' . substr(preg_replace('/[^\x20-\x7e]/', '?', $argument), 0, 60) . ". Nothing was claimed.\n");
        exit(SA_PULL_EXIT_NOT_CONFIGURED);
    }
}
$options = getopt('', ['once', 'max-jobs:', 'poll-seconds:', 'max-runtime:']);
$once = isset($options['once']);
$maxJobs = isset($options['max-jobs']) ? (int) $options['max-jobs'] : PHP_INT_MAX;
$pollSeconds = isset($options['poll-seconds']) ? max(10, min(3600, (int) $options['poll-seconds'])) : 30;
$stopAt = isset($options['max-runtime']) ? time() + (int) $options['max-runtime'] : PHP_INT_MAX;

$setting = static function (string $name, ?string $default = null): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        if ($default === null) {
            throw new RuntimeException($name . ' is not configured.');
        }
        return $default;
    }
    return trim($value);
};
$number = static function (string $name, int $default) use ($setting): int {
    $value = $setting($name, (string) $default);
    if (!ctype_digit($value)) {
        throw new RuntimeException($name . ' must be a whole number.');
    }
    return (int) $value;
};

try {
    if (getenv('SMART_ASSESS_AI_REMOTE_SECRET') !== false) {
        throw new RuntimeException('SMART_ASSESS_AI_REMOTE_SECRET must not be set in the environment; use SMART_ASSESS_AI_REMOTE_SECRET_FILE.');
    }
    $client = new AiRemoteClient(
        $setting('SMART_ASSESS_AI_REMOTE_URL'),
        RemoteWorkerSignature::fromFile($setting('SMART_ASSESS_AI_REMOTE_SECRET_FILE')),
        ($ca = trim((string) getenv('SMART_ASSESS_AI_REMOTE_CA_FILE'))) !== '' ? $ca : null
    );
    $stateDirectory = realpath($setting('SMART_ASSESS_WORKER_STATE_DIR'));
    if ($stateDirectory === false || !is_dir($stateDirectory) || !is_writable($stateDirectory) || (fileperms($stateDirectory) & 0o077) !== 0) {
        throw new RuntimeException('SMART_ASSESS_WORKER_STATE_DIR must be an existing folder that only this user can access (chmod 700).');
    }
    $tools = [];
    foreach (['tesseract' => 'TESSERACT', 'pdfinfo' => 'PDFINFO', 'pdftotext' => 'PDFTOTEXT', 'pdftoppm' => 'PDFTOPPM'] as $tool => $variable) {
        $path = trim((string) getenv('SMART_ASSESS_WORKER_' . $variable . '_PATH'));
        if ($path !== '') {
            $tools[$tool] = $path;
        }
    }
    $ocrLanguages = $setting('SMART_ASSESS_WORKER_OCR_LANGUAGES', 'eng');
    $ocrProcessTimeout = $number('SMART_ASSESS_WORKER_OCR_PROCESS_TIMEOUT', 30);
    $ocrTotalTimeout = $number('SMART_ASSESS_WORKER_OCR_TOTAL_TIMEOUT', 90);
    $ollama = new OllamaClient(
        $setting('SMART_ASSESS_WORKER_OLLAMA_URL', 'http://127.0.0.1:11434'),
        $setting('SMART_ASSESS_WORKER_OLLAMA_MODEL'),
        $number('SMART_ASSESS_WORKER_OLLAMA_TIMEOUT', 180),
        $number('SMART_ASSESS_WORKER_OLLAMA_MAX_RESPONSE_BYTES', 262_144),
        $number('SMART_ASSESS_WORKER_OLLAMA_CONTEXT_TOKENS', 8192),
        $number('SMART_ASSESS_WORKER_OLLAMA_MAX_OUTPUT_TOKENS', 1500)
    );
    $maxInput = $number('SMART_ASSESS_WORKER_MAX_INPUT_CHARS', 16_000);
    $maxJobSeconds = $number('SMART_ASSESS_WORKER_MAX_JOB_SECONDS', 900);
    $ocrFactory = static fn (string $jobDirectory, int $seconds = PHP_INT_MAX): OcrService => new OcrService($jobDirectory,
        max(1, min($ocrProcessTimeout, $seconds)), max(1, min($ocrTotalTimeout, $seconds)), $tools, $ocrLanguages, $jobDirectory);
    $chat = static fn (string $system, string $user, array $schema, float $temperature, ?int $timeout = null): mixed
        => $ollama->chatJson($system, $user, $schema, $temperature, $timeout);
    $makeWorker = static fn (?string $digest): AiDocumentWorker => new AiDocumentWorker($stateDirectory, $ocrFactory, $chat, $ollama->model(),
        $digest, 'tesseract+poppler (' . $ocrLanguages . ')', $maxInput, $maxJobSeconds, $ollama->timeoutSeconds());
    $makeWorker(null);   // validates every worker setting now
    $ocrCheck = new OcrService($stateDirectory, $ocrProcessTimeout, $ocrTotalTimeout, $tools, $ocrLanguages);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Not configured: ' . $exception->getMessage() . " Nothing was claimed.\n");
    exit(SA_PULL_EXIT_NOT_CONFIGURED);
}

// One instance at a time; while holding the lock, no job folder can belong to a running job.
$lock = fopen($stateDirectory . '/remote-pull.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another remote pull worker is running with this state folder. Nothing was claimed.\n");
    exit(SA_PULL_EXIT_TEMPORARY);
}

/**
 * One cycle. Returns [outcome, exit code if the loop must stop or null].
 * outcomes: idle, processed, not_ready, unreachable, refused, job_failed, lease_lost
 */
$cycle = static function () use ($client, $makeWorker, $ollama, $ocrCheck, $stateDirectory, $say): array {
    $sweep = (new WorkerStateCleaner($stateDirectory, 0))->sweep();
    if ($sweep['failed'] > 0) {
        $say('Folders left by an interrupted job could not be removed; no job is claimed until they are.');
        return ['not_ready', SA_PULL_EXIT_TEMPORARY];
    }
    $problems = $ocrCheck->readinessProblems();
    $model = $ollama->modelStatus();
    if ($problems !== [] || $model !== 'ready') {
        $say('Local OCR or model not ready (' . ($problems !== [] ? implode(', ', $problems) : 'model ' . $model) . '); nothing claimed.');
        return ['not_ready', $model === 'missing' || $problems !== [] ? SA_PULL_EXIT_NOT_CONFIGURED : SA_PULL_EXIT_TEMPORARY];
    }
    try {
        [$status, $health] = $client->call(['op' => 'health']);
        if ($status !== 200 || ($health['processor'] ?? null) !== 'remote') {
            $say('The website has not selected the remote processor (' . preg_replace('/[^a-z]/', '', (string) ($health['processor'] ?? '?')) . '); nothing claimed.');
            return ['refused', SA_PULL_EXIT_REFUSED];
        }
        if (($health['maintenance'] ?? false) === true) {
            $say('The website is being updated (maintenance); nothing claimed, trying again later.');
            return ['paused', SA_PULL_EXIT_TEMPORARY];
        }
        [$status, $claimed] = $client->call(['op' => 'claim']);
    } catch (RuntimeException $exception) {
        $code = $exception->getMessage();
        $say('Website: ' . preg_replace('/[^a-z_]/', '', $code) . '; nothing claimed.'
            . ($code === 'remote_auth_failed' ? ' Check that this Mac sets its clock automatically (signatures allow 5 minutes of difference) and that its secret file matches the website.' : ''));
        return in_array($code, ['remote_auth_failed', 'remote_disabled'], true) ? ['refused', SA_PULL_EXIT_REFUSED] : ['unreachable', SA_PULL_EXIT_TEMPORARY];
    }
    if ($status !== 200) {
        $error = preg_replace('/[^a-z_]/', '', (string) ($claimed['error'] ?? $status));
        if ($status === 429 || in_array($error, ['maintenance', 'rate_limited', 'claim_lock_unavailable', 'database_unavailable'], true)) {
            $say("The website asked to wait ({$error}); nothing claimed, trying again later.");
            return ['paused', SA_PULL_EXIT_TEMPORARY];
        }
        $say("The website refused to hand out jobs ({$error}).");
        return ['refused', SA_PULL_EXIT_REFUSED];
    }
    $job = $claimed['job'] ?? null;
    if (!is_array($job)) {
        return ['idle', null];
    }
    $jobId = (int) ($job['id'] ?? 0);
    $token = (string) ($job['claim_token'] ?? '');
    // Wall clock: it keeps running while the machine sleeps, so the local deadline stays honest.
    $deadline = time() + (int) ($job['lease_seconds_remaining'] ?? 0) - SA_PULL_SEND_MARGIN_SECONDS;
    $started = microtime(true);
    $fail = static function (string $code) use ($client, $jobId, $token, $say): void {
        try {
            [$status, $answer] = $client->call(['op' => 'fail', 'job_id' => $jobId, 'claim_token' => $token, 'code' => $code]);
            $say("job {$jobId}: reported {$code} (" . preg_replace('/[^a-z_]/', '', (string) ($answer['status'] ?? $answer['result'] ?? $status)) . ').');
        } catch (RuntimeException) {
            $say("job {$jobId}: could not report {$code}; the lease will run out and the job will be retried.");
        }
    };
    $documents = [];
    try {
        foreach ((array) ($job['documents'] ?? []) as $document) {
            $bytes = $client->download($jobId, $token, (string) $document['key'], (int) $document['size'], (string) $document['sha256']);
            $documents[] = ['key' => (string) $document['key'], 'label' => (string) $document['label'], 'mime' => (string) $document['mime'], 'content_base64' => base64_encode($bytes)];
            unset($bytes);
        }
    } catch (RuntimeException) {
        $documents = [];
        $fail('remote_download_failed');
        return ['job_failed', null];
    }
    $say("job {$jobId}: claimed, " . count($documents) . ' document(s) downloaded.');
    $budget = min((int) ($job['time_budget_seconds'] ?? 0), $deadline - time() - 30);
    if ($budget < AiDocumentWorker::MIN_BUDGET_SECONDS) {
        $documents = [];
        $fail('worker_timeout');
        return ['job_failed', null];
    }
    try {
        $validated = AiDocumentWorker::validateJob(['version' => 1, 'job_id' => $jobId, 'declared' => $job['declared'] ?? null,
            'documents' => $documents, 'time_budget_seconds' => min(AiDocumentWorker::MAX_BUDGET_SECONDS, $budget)]);
        $documents = [];
        $result = $makeWorker($ollama->modelDigest())->run($validated);
        unset($validated);
    } catch (UnexpectedValueException) {
        $documents = [];
        $fail('worker_error');
        return ['job_failed', null];
    } catch (RuntimeException $exception) {
        $code = match ($exception->getMessage()) {
            'model_unavailable' => 'model_unavailable',
            'model_timeout' => 'model_timeout',
            'worker_not_configured' => 'worker_not_configured',
            default => 'worker_error',
        };
        $fail($code);
        return ['job_failed', null];
    }
    if (time() >= $deadline) {
        // The machine slept or the job ran long: the website would refuse it. Never send it.
        $say("job {$jobId}: the lease ran out locally; the result was discarded and the job will be retried.");
        return ['lease_lost', null];
    }
    // Delivery: retried with a growing pause while the website is unreachable, under maintenance or
    // rate limiting, but only while the lease still allows it (a repeat of a stored result is harmless).
    $answer = null;
    $status = 0;
    foreach (SA_PULL_DELIVERY_PAUSES as $pause) {
        try {
            [$status, $answer] = $client->call(['op' => 'complete', 'job_id' => $jobId, 'claim_token' => $token, 'result' => $result]);
            if ($status !== 429 && !($status === 503 && ($answer['error'] ?? '') === 'maintenance')) {
                break;
            }
            $reason = (string) ($answer['error'] ?? $status);
        } catch (RuntimeException $exception) {
            $reason = $exception->getMessage();
            $answer = null;
        }
        if ($pause === null || time() + $pause >= $deadline) {
            break;
        }
        $say("job {$jobId}: result not delivered yet (" . preg_replace('/[^a-z_0-9]/', '', $reason) . "); trying again in {$pause} s.");
        sleep($pause);
    }
    if ($answer === null || $status === 429 || ($status === 503 && ($answer['error'] ?? '') === 'maintenance')) {
        $say("job {$jobId}: the result could not be delivered before the lease ran out; the job will be retried.");
        return ['unreachable', null];
    }
    $say(sprintf('job %d: %s%s in %.1f s.', $jobId, preg_replace('/[^a-z_]/', '', (string) ($answer['result'] ?? $status)),
        isset($answer['outcome']) ? ' (' . preg_replace('/[^a-z_]/', '', (string) $answer['outcome']) . ')' : '', microtime(true) - $started));
    return [($answer['result'] ?? '') === 'stored' || ($answer['result'] ?? '') === 'already_stored' ? 'processed' : 'job_failed', null];
};

$processed = 0;
$delay = $pollSeconds;
while (true) {
    [$outcome, $exitCode] = $cycle();
    if ($outcome === 'processed') {
        $processed++;
    }
    if ($once) {
        exit($exitCode ?? 0);
    }
    if ($exitCode === SA_PULL_EXIT_NOT_CONFIGURED || $exitCode === SA_PULL_EXIT_REFUSED) {
        exit($exitCode);   // a setting must be fixed: stop instead of retrying blindly
    }
    if ($processed >= $maxJobs || time() >= $stopAt) {
        exit(0);
    }
    if ($outcome === 'processed') {
        $delay = $pollSeconds;
        continue;   // there may be more work
    }
    if ($outcome === 'job_failed' || $outcome === 'lease_lost') {
        // Pause after a failure instead of claiming again at once, so a run of failing jobs
        // cannot turn into a tight loop against the website.
        $delay = $pollSeconds;
        sleep(max(1, min($pollSeconds, $stopAt - time())));
        continue;
    }
    $delay = $outcome === 'idle' ? $pollSeconds : min(300, $delay * 2);
    sleep(max(1, min($delay, $stopAt - time())));
}
