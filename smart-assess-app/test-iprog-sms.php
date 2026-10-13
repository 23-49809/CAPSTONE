<?php
declare(strict_types=1);

require_once __DIR__ . '/iprog-sms.php';

try {
    $sms = IprogSmsService::fromEnvironment();

    // Replace with your own Philippine mobile number.
    $result = $sms->send(
        '09104127461',
        'SMART ASSESS - Mabini: This is a test SMS.'
    );

    echo "IPROG accepted the SMS request.\n";
    echo "HTTP status: " . $result['http_status'] . "\n";
    echo "Provider status: " . $result['provider_status'] . "\n";
    echo "Message ID: " . ($result['message_id'] ?? 'Not provided') . "\n";
    echo "Check your phone to confirm delivery.\n";

} catch (Throwable $e) {
    echo "SMS test failed: " . $e->getMessage() . "\n";
}
