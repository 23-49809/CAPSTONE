<?php
declare(strict_types=1);

// Sends one real SMS (uses account credits). It runs only from the command
// line and only when explicitly confirmed:
//   php test-iprog-sms.php --send
// Including this file, running it without --send, or `php -l` sends nothing.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}
if (!in_array('--send', $argv ?? [], true)) {
    echo "Not sending. Run with --send to send one real test SMS.\n";
    exit(0);
}

require_once __DIR__ . '/iprog-sms.php';

try {
    $sms = IprogSmsService::fromEnvironment();

    $recipient = getenv('IPROG_TEST_PHONE');
    if ($recipient === false || trim($recipient) === '') {
        throw new RuntimeException(
            'IPROG_TEST_PHONE is missing. Configure your test recipient first.'
        );
    }

    $message = 'SMART ASSESS - Mabini Assessor\'s Office: This is an authorized test SMS. Please disregard this test message.';
    $result = $sms->send($recipient, $message);

    echo "IPROG accepted the SMS request.\n";
    echo "HTTP status: " . $result['http_status'] . "\n";
    echo "Provider status: " . $result['provider_status'] . "\n";
    echo "Message ID: " . ($result['message_id'] ?? 'Not provided') . "\n";
    echo "Check your phone to confirm delivery.\n";

} catch (Throwable $e) {
    echo "SMS test failed: " . $e->getMessage() . "\n";
    exit(1);
}
