<?php
declare(strict_types=1);

/*
 * Sends queued AI document checks to the private AI worker and stores the
 * validated results. Command line only (run by a systemd timer or cron on the
 * PHP server); there is no web route for it.
 *
 *   php bin/ai-verification-dispatch.php [--limit=N] [--max-seconds=N]
 *
 * Needs the same database settings as the web app (DB_HOST, DB_PORT, DB_NAME
 * and DB_USER must be set explicitly, so it never falls back to a default
 * database), plus SMART_ASSESS_AI_WORKER_URL and SMART_ASSESS_AI_WORKER_SECRET.
 * In production it runs from deploy/systemd/smart-assess-ai-dispatch.timer.
 *
 * Each run: if no job is due, it exits at once. Otherwise it asks the worker's
 * health endpoint first; if the worker is not ready or not configured, it
 * exits without claiming anything, so waiting jobs keep all their attempts.
 * After a worker outage, crash, busy answer or configuration error during the
 * run, it stops (the job is retried later without using an attempt).
 *
 * Only requests submitted on or after SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT
 * (the confirmed Privacy Policy effective date) are processed; without a
 * valid value the dispatcher refuses to run (exit code 2).
 *
 * If any waiting job was queued by the website under a different effective date
 * than this dispatcher's, it claims nothing and exits with code 3.
 *
 * Operator recovery: --retire-mismatched --website-date=VALUE [--confirm=CODE] (see below and the docs).
 *
 * Exit codes: 0 done (or nothing to do); 1 --retire-mismatched refused an outdated or wrong
 * confirmation code (3 when --website-date differs from this dispatcher's date); 2 this server's dispatcher settings
 * are missing (including the Privacy Policy effective date); 3 a setting on the worker side must be fixed (shared secret or
 * clock, worker settings, TLS certificate or CA, web-server limits or URL,
 * leftover files the worker cannot remove, a stuck job); 75 the worker is not ready, became
 * unavailable or crashed (temporary; jobs wait).
 *
 * Prints only job IDs, statuses and outcomes: never names, numbers or text.
 * It never changes a request's status.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$appRoot = dirname(__DIR__);
require_once $appRoot . '/web/bootstrap.php';
require_once $appRoot . '/app/services/AiVerification.php';
require_once $appRoot . '/app/services/AiWorkerClient.php';

$options = getopt('', ['limit:', 'max-seconds:', 'retire-mismatched', 'confirm:', 'website-date:']);
$retireMode = isset($options['retire-mismatched']);
$limit = isset($options['limit']) && ctype_digit((string) $options['limit']) ? max(1, (int) $options['limit']) : 20;
$maxSeconds = isset($options['max-seconds']) && ctype_digit((string) $options['max-seconds']) ? max(1, (int) $options['max-seconds']) : 3000;

const SA_DISPATCH_EXIT_NOT_CONFIGURED = 2;
const SA_DISPATCH_EXIT_WORKER_CONFIGURATION = 3;
const SA_DISPATCH_EXIT_WORKER_NOT_READY = 75;   // EX_TEMPFAIL
const SA_DISPATCH_EXIT_NOT_CONFIRMED = 1;        // --retire-mismatched: the confirmation code does not match

if ((isset($options['confirm']) || isset($options['website-date'])) && !$retireMode) {
    fwrite(STDERR, "--confirm and --website-date are only used with --retire-mismatched.\n");
    exit(SA_DISPATCH_EXIT_NOT_CONFIGURED);
}

foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'] as $variable) {
    $value = getenv($variable);
    if (!is_string($value) || trim($value) === '') {
        fwrite(STDERR, "Not configured: {$variable} must be set explicitly for the dispatcher.\n");
        exit(SA_DISPATCH_EXIT_NOT_CONFIGURED);
    }
}

try {
    $pdo = sa_db();
    if (!AiVerification::available($pdo)) {
        fwrite(STDERR, "The ai_verification_jobs table does not exist. Run database/migrations/20261111_ai_document_verification.sql first.\n");
        exit(SA_DISPATCH_EXIT_NOT_CONFIGURED);
    }
    if (AiVerification::privacyEffectiveFrom() === null) {
        // Fail closed: without the confirmed Privacy Policy effective date no request may be checked,
        // and no job may be retired.
        throw new RuntimeException(AiVerification::PRIVACY_EFFECTIVE_VARIABLE . ' is not set to a valid date and time with an offset'
            . ' (YYYY-MM-DDTHH:MM:SS+08:00), so no AI check may run. Set it to the confirmed Privacy Policy effective date.');
    }
    $client = $retireMode ? null : AiWorkerClient::fromEnvironment();   // the recovery never contacts the worker
} catch (Throwable $exception) {
    fwrite(STDERR, 'Not configured: ' . $exception->getMessage() . "\n");
    exit(SA_DISPATCH_EXIT_NOT_CONFIGURED);
}

/*
 * Operator recovery (see docs, "Recovering from a corrected effective date"):
 *   --retire-mismatched --website-date=VALUE                read-only preview
 *   --retire-mismatched --website-date=VALUE --confirm=CODE marks exactly the previewed jobs failed
 * VALUE is SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT copied by the operator from the website's
 * /etc/smart-assess/apache.env. It must equal this dispatcher's value; otherwise nothing is
 * shown as retirable and nothing is changed (exit 3). Never run by the timer. Prints job IDs only.
 */
if ($retireMode) {
    $effectiveFrom = (int) AiVerification::privacyEffectiveFrom();
    $show = static fn (?int $epoch): string => $epoch === null ? 'none recorded'
        : (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('+08:00'))->format('Y-m-d\TH:i:sP');
    $websiteValue = isset($options['website-date']) && is_string($options['website-date']) ? trim($options['website-date']) : '';
    if ($websiteValue === '') {
        fwrite(STDERR, "--retire-mismatched needs --website-date=VALUE: the exact " . AiVerification::PRIVACY_EFFECTIVE_VARIABLE
            . " value from the website's /etc/smart-assess/apache.env. Nothing was changed.\n");
        exit(SA_DISPATCH_EXIT_NOT_CONFIGURED);
    }
    $websiteFrom = AiVerification::privacyEffectiveFrom($websiteValue);
    if ($websiteFrom === null) {
        fwrite(STDERR, "--website-date is not a valid date and time with an offset (YYYY-MM-DDTHH:MM:SS+08:00). Nothing was changed.\n");
        exit(SA_DISPATCH_EXIT_NOT_CONFIGURED);
    }
    echo "==================== AI effective dates ====================\n";
    echo 'Website    (--website-date, from apache.env):    ' . $show($websiteFrom) . "\n";
    echo 'Dispatcher (' . AiVerification::PRIVACY_EFFECTIVE_VARIABLE . ', ai-dispatch.env): ' . $show($effectiveFrom) . "\n";
    echo "Do not continue unless you have checked BOTH settings files yourself and both hold the\n";
    echo "same, confirmed value. If the dispatcher's value is the wrong one, fix it instead: this\n";
    echo "command would otherwise stop correctly dated checks.\n";
    echo "============================================================\n";
    if ($websiteFrom !== $effectiveFrom) {
        fwrite(STDERR, "REFUSED: the website date and the dispatcher date differ. Correct the wrong setting first (only with approval);"
            . " no job is retired while they differ. Nothing was changed.\n");
        exit(SA_DISPATCH_EXIT_WORKER_CONFIGURATION);
    }
    if (!isset($options['confirm'])) {
        $jobs = AiVerification::mismatchedJobs($pdo, $effectiveFrom);
        $retire = array_values(array_filter($jobs, static fn (array $job): bool => !$job['leased']));
        $leased = count($jobs) - count($retire);
        $recorded = [];
        foreach ($jobs as $job) {
            $recorded[$show($job['recorded'])] = ($recorded[$show($job['recorded'])] ?? 0) + 1;
        }
        foreach ($recorded as $date => $count) {
            printf("Recorded on %d waiting job(s): %s\n", $count, $date);
        }
        foreach ($jobs as $job) {
            printf("job %d: %s, recorded %s%s\n", $job['id'], $job['status'], $show($job['recorded']), $job['leased'] ? ' (being processed now: left alone)' : '');
        }
        printf("%d job(s) would be marked failed (%s); %d still being processed would be left alone.\n", count($retire), AiVerification::RETIRED_EFFECTIVE_DATE_CHANGED, $leased);
        if ($retire === [] && $leased > 0) {
            echo "No job can be retired now: {$leased} job(s) are still being processed. Repeat this preview and the confirmation after their leases end"
                . " (at most about " . (int) ceil(AiWorkerClient::DEFAULT_TIMEOUT_SECONDS / 60 + 2) . " minutes with the default timeout). Nothing was changed.\n";
            exit(0);
        }
        if ($retire === []) {
            echo "Nothing to do. Nothing was changed.\n";
            exit(0);
        }
        echo "Nothing was changed. Request statuses and history are never changed by this.\n";
        echo "To mark exactly these jobs failed, after the checks above, run (as one command):\n";
        echo '  sudo systemd-run --uid=www-data --gid=www-data --pipe --wait --collect --property=EnvironmentFile=/etc/smart-assess/ai-dispatch.env '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg((string) realpath(__FILE__)) . ' --retire-mismatched --website-date=' . escapeshellarg($websiteValue)
            . ' --confirm=' . AiVerification::retirementCode($jobs, $effectiveFrom, $websiteFrom) . "\n";
        exit(0);
    }
    // systemd sets INVOCATION_ID for each systemd-run unit; it links this run to the journal and to sudo's log.
    $invocation = getenv('INVOCATION_ID');
    $result = AiVerification::retireMismatched($pdo, $effectiveFrom, $websiteFrom, trim((string) $options['confirm']), is_string($invocation) ? $invocation : null);
    if (!$result['done']) {
        if ($result['reason'] === 'dates_differ') {
            fwrite(STDERR, "REFUSED: the website date and the dispatcher date differ. Nothing was changed.\n");
            exit(SA_DISPATCH_EXIT_WORKER_CONFIGURATION);
        }
        fwrite(STDERR, "The confirmation code does not match the jobs waiting now (they changed since the preview, or the code is wrong). Nothing was changed. Run the preview again.\n");
        exit(SA_DISPATCH_EXIT_NOT_CONFIRMED);
    }
    printf("Marked %d job(s) failed (%s) at %s; %d job(s) still being processed were left alone. Jobs were kept; request statuses and history were not changed.%s\n",
        $result['retired'], AiVerification::RETIRED_EFFECTIVE_DATE_CHANGED, $show(time()), $result['leased'],
        $result['leased'] > 0 ? ' Repeat the preview and confirmation after their leases end.' : '');
    exit(0);
}

// The website records on each job the effective date it used. If any waiting job
// carries a different date (or none), the two servers are configured differently:
// claim nothing, change nothing, and report it until the settings agree.
$effectiveFrom = (int) AiVerification::privacyEffectiveFrom();
$mismatches = AiVerification::effectiveDateMismatches($pdo, $effectiveFrom);
if ($mismatches !== []) {
    $show = static fn (?int $epoch): string => $epoch === null ? 'none recorded'
        : (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('+08:00'))->format('Y-m-d\TH:i:sP');
    fwrite(STDERR, 'The website and the dispatcher use different ' . AiVerification::PRIVACY_EFFECTIVE_VARIABLE . ' values: this dispatcher has '
        . $show($effectiveFrom) . ', but waiting jobs were queued with ' . implode(', ', array_map($show, $mismatches))
        . '. Set the same confirmed value on both servers. No job was claimed or changed.'
        . ' Only after both settings are verified and identical, preview the jobs queued under an old value with --retire-mismatched --website-date=... (see docs).' . "\n");
    echo "0 job(s) processed.\n";
    exit(SA_DISPATCH_EXIT_WORKER_CONFIGURATION);
}

if (!AiVerification::hasDueJob($pdo)) {
    echo "0 job(s) processed.\n";
    exit(0);
}
// A failed health check claims nothing, so waiting jobs keep all their attempts.
// A passed one is no guarantee: errors after claiming follow the retry rules.
$health = $client->health();
if ($health !== AiWorkerClient::HEALTH_READY) {
    $configuration = [
        AiWorkerClient::HEALTH_NOT_CONFIGURED => 'The AI worker reports that its settings are not valid, or the worker URL does not reach the worker (see the worker\'s log).',
        AiWorkerClient::HEALTH_CLEANUP_FAILED => 'The AI worker cannot remove files left by an interrupted job (see the worker\'s log); it takes no jobs until they are removed.',
        AiWorkerClient::HEALTH_TLS_FAILED => 'The secure connection to the AI worker failed: check its certificate and SMART_ASSESS_AI_WORKER_CA_FILE.',
        AiWorkerClient::HEALTH_STUCK => 'A job has held the AI worker\'s lock longer than any job may run: restart php-fpm on the worker.',
    ];
    if (isset($configuration[$health])) {
        fwrite(STDERR, $configuration[$health] . " No job was claimed; queued jobs wait.\n");
        echo "0 job(s) processed.\n";
        exit(SA_DISPATCH_EXIT_WORKER_CONFIGURATION);
    }
    fwrite(STDERR, "The AI worker is not ready (health check: {$health}). No job was claimed; queued jobs wait for the next run.\n");
    echo "0 job(s) processed.\n";
    exit(SA_DISPATCH_EXIT_WORKER_NOT_READY);
}

$started = time();
$processed = 0;
$exitCode = 0;
while ($processed < $limit && time() - $started < $maxSeconds) {
    $summary = AiVerification::processNext($pdo, $appRoot, static fn (array $job): array => $client->analyze($job), $client->leaseSeconds());
    if ($summary === null) {
        break;
    }
    $processed++;
    printf("job %d: %s%s%s\n", $summary['job_id'], $summary['status'], $summary['outcome'] !== null ? ' (' . $summary['outcome'] . ')' : '',
        $summary['error'] !== null ? ' [' . $summary['error'] . ', ' . $summary['retry'] . ']' : '');
    if ($summary['retry'] === AiVerification::RETRY_CONFIGURATION) {
        fwrite(STDERR, ($summary['error'] === 'worker_auth_failed'
            ? 'The AI worker refused the shared secret. Check SMART_ASSESS_AI_WORKER_SECRET on both servers and their clocks.'
            : 'A setting on the AI worker side must be fixed (' . $summary['error'] . ').') . " Stopping; jobs wait.\n");
        $exitCode = SA_DISPATCH_EXIT_WORKER_CONFIGURATION;
        break;
    }
    if ($summary['retry'] === AiVerification::RETRY_TRANSIENT || $summary['retry'] === AiVerification::RETRY_CRASH) {
        fwrite(STDERR, 'The AI worker became unavailable (' . $summary['error'] . "). Stopping; jobs wait for the next run.\n");
        $exitCode = SA_DISPATCH_EXIT_WORKER_NOT_READY;
        break;
    }
}
printf("%d job(s) processed.\n", $processed);
exit($exitCode);
