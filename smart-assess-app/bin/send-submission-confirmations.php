<?php
declare(strict_types=1);

/*
 * Sends queued automatic confirmations of new submissions (SMS, and email when
 * given). Command line only; started in the background by web/api.php right after
 * a submission (sa_confirmation_kick()), with the web server's environment:
 * DB_*, SMART_ASSESS_DB_PASS, IPROG_API_TOKEN, SMART_ASSESS_SMS_ENABLED,
 * SMART_ASSESS_SMS_PROVIDER, SMART_ASSESS_GMAIL_DIR.
 *
 *   php bin/send-submission-confirmations.php [--request=ID]
 *
 * --request=ID sends that request's confirmations; any run also sends
 * confirmations queued more than two minutes ago (a run that could not start).
 * Each confirmation is sent at most once. See app/services/SubmissionConfirmation.php.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$requestId = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--request=([1-9]\d{0,9})$/', $argument, $match)) {
        $requestId = (int) $match[1];
    } else {
        fwrite(STDERR, "Usage: php bin/send-submission-confirmations.php [--request=ID]\n");
        exit(2);
    }
}

$app = dirname(__DIR__);
require $app . '/web/bootstrap.php';
require_once $app . '/app/services/SubmissionConfirmation.php';

try {
    $pdo = sa_db();
    if (!sa_notification_tracking_available()) {
        exit(0);   // database update 20261113 not run: nothing can be queued
    }
    SubmissionConfirmation::run($pdo, $requestId, $app . '/iprog-sms.php', $app . '/gmail-mailer.php');
} catch (Throwable $exception) {
    error_log('[SMART_ASSESS_CONFIRM] Sender run failed: ' . get_class($exception) . '.');
    exit(1);
}
exit(0);
