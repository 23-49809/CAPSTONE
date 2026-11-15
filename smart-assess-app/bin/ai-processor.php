<?php
declare(strict_types=1);

/*
 * Shows or selects the ONLY processor allowed to claim AI jobs (migration 20261116):
 *
 *   php bin/ai-processor.php --show
 *   php bin/ai-processor.php --status        (monitoring: live claims, remote limits, event counts; read only)
 *   php bin/ai-processor.php --set=off|aws|remote --by="operator name"
 *
 *   off    - nobody claims jobs
 *   aws    - the AWS dispatcher (bin/ai-verification-dispatch.php)
 *   remote - the remote pull worker (web/ai-remote.php + bin/ai-remote-pull.php)
 *
 * Changing it is refused while any job is being processed under a live lease, so the
 * old processor finishes (or its lease runs out) before the new one can claim; every
 * claim re-checks the setting in its own transaction. Selecting a processor does not
 * enable AI: jobs exist only for requests submitted after the Privacy Policy effective
 * date (SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT), which this command never changes.
 * It changes no job, request or notification. Needs the website's database settings
 * (DB_HOST, DB_PORT, DB_NAME, DB_USER set explicitly, and SMART_ASSESS_DB_PASS).
 *
 * Exit codes: 0 done (or unchanged); 1 refused (jobs in progress, or not installed); 2 usage or settings.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$app = dirname(__DIR__);
require_once $app . '/web/bootstrap.php';

foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--show' && $argument !== '--status' && !preg_match('/^--(set=(off|aws|remote)|by=[\x20-\x7e]{1,100})$/', $argument)) {
        fwrite(STDERR, "Usage: php bin/ai-processor.php --show | --status | --set=off|aws|remote --by=\"name\". Nothing was changed.\n");
        exit(2);
    }
}
$options = getopt('', ['show', 'status', 'set:', 'by:']);
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'] as $variable) {
    $value = getenv($variable);
    if (!is_string($value) || trim($value) === '') {
        fwrite(STDERR, "Not configured: {$variable} must be set explicitly. Nothing was changed.\n");
        exit(2);
    }
}
try {
    $pdo = sa_db();
    if (!AiVerification::available($pdo) || !AiVerification::processorSelectionInstalled($pdo)) {
        fwrite(STDERR, "Processor selection is not installed: run database/migrations/20261116_ai_remote_worker.sql first (with approval). Nothing was changed.\n");
        exit(1);
    }
    if (isset($options['status'])) {
        require_once $app . '/app/services/AiRemoteQueue.php';
        $now = time();
        echo 'Selected processor: ' . AiVerification::processor($pdo) . "\n";
        echo 'Jobs under a live claim: ' . AiRemoteQueue::activeClaims($pdo) . ' (remote allows at most ' . AiRemoteQueue::MAX_ACTIVE_CLAIMS . ")\n";
        echo 'Remote limits (this environment): ' . AiRemoteQueue::maxClaimsPerHour() . ' claims/hour, ' . AiRemoteQueue::maxClaimsPerDay() . ' claims/day, '
            . AiRemoteQueue::maxDownloadsPerHour() . " downloads/hour, each document once per claim\n";
        $until = AiRemoteQueue::enabledUntil($now);
        echo 'Remote window (this environment): ' . ($until === null ? 'closed (SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL unset, invalid, past or more than 31 days ahead)'
            : 'open until ' . gmdate('Y-m-d\TH:i:s\Z', $until)) . "\n";
        foreach (['last hour' => 3600, 'last 24 hours' => 86400] as $label => $seconds) {
            $summary = AiRemoteQueue::eventSummary($pdo, $now - $seconds);
            echo 'Remote events, ' . $label . ': ' . implode(', ', array_map(static fn (string $event, int $count): string => $event . ' ' . $count, array_keys($summary), $summary)) . "\n";
        }
        exit(0);
    }
    if (!isset($options['set'])) {
        echo 'Selected processor: ' . AiVerification::processor($pdo) . "\n";
        exit(0);
    }
    $by = trim((string) ($options['by'] ?? ''));
    if ($by === '') {
        fwrite(STDERR, "--set needs --by=\"operator name\" (recorded with the change). Nothing was changed.\n");
        exit(2);
    }
    $result = AiVerification::setProcessor($pdo, (string) $options['set'], $by);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . preg_replace('/[^\x20-\x7e]/', '?', $exception->getMessage()) . ". Nothing was changed.\n");
    exit(2);
}
if ($result['reason'] === 'jobs_in_progress') {
    fwrite(STDERR, "Refused: a job is being processed now. Wait until it finishes (or its lease runs out), then try again. Still: {$result['from']}.\n");
    exit(1);
}
echo $result['changed'] ? "Processor changed from {$result['from']} to {$result['to']}.\n" : "Processor is already {$result['to']}; nothing changed.\n";
exit(0);
