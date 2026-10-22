<?php
declare(strict_types=1);

/*
 * Repeatable tests for client notifications: email (Gmail) and SMS (IPROG),
 * using the real code from gmail-mailer.php and web/api.php. SMS and email are
 * sent only when staff send a notification; submissions and status changes
 * never send either (checked below).
 *
 * Never sends a real email or SMS and never uses SMS credits:
 *  - HOME is cleared and SMART_ASSESS_GMAIL_DIR points at an empty temporary
 *    folder, so the real mailer can never find Gmail credentials; successful
 *    sends go through a fake mailer that only records the message.
 *  - The real iprog-sms.php is never loaded. A copy whose only change is the
 *    API address (a fake IPROG on 127.0.0.1) is used, with a fake token.
 * Uses ONLY a dedicated test database (name must end in "_test"); it drops and
 * recreates just the `notifications` table there.
 *
 *   SMART_ASSESS_TEST_DB=smart_assess_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=root SMART_ASSESS_DB_PASS='...' php tests/notifications_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dbName = (string) getenv('SMART_ASSESS_TEST_DB');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $dbName)) {
    fwrite(STDERR, "Refusing to run: set SMART_ASSESS_TEST_DB to a dedicated database whose name ends in _test.\n");
    exit(2);
}
$app = dirname(__DIR__);
$work = sys_get_temp_dir() . '/sa-notify-test-' . bin2hex(random_bytes(4));
mkdir($work . '/empty-gmail-dir', 0700, true);
mkdir($work . '/iprog', 0700, true);
$errorLog = $work . '/php-errors.log';
ini_set('log_errors', '1');
ini_set('error_log', $errorLog);

// ---- No path to real credentials or the real IPROG endpoint ----
putenv('HOME');
unset($_SERVER['HOME'], $_ENV['HOME']);
putenv('SMART_ASSESS_GMAIL_DIR=' . $work . '/empty-gmail-dir');
putenv('IPROG_API_TOKEN');
putenv('SMART_ASSESS_SMS_ENABLED');
putenv('DB_NAME=' . $dbName);
foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USER' => 'root'] as $key => $default) {
    putenv($key . '=' . (getenv($key) ?: $default));
}
if (getenv('SMART_ASSESS_DB_PASS') === false) {
    putenv('SMART_ASSESS_DB_PASS=');
}

// Fake IPROG: answers according to case.txt and records what it received.
file_put_contents($work . '/iprog/case.txt', 'ok');
file_put_contents($work . '/iprog/stub.php', <<<'PHP'
<?php
$case = trim((string) @file_get_contents(__DIR__ . '/case.txt'));
file_put_contents(__DIR__ . '/hits.log', json_encode([
    'phone' => $_POST['phone_number'] ?? '', 'message' => $_POST['message'] ?? '',
    'token' => $_POST['api_token'] ?? '', 'fields' => array_keys($_POST),
]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
[$http, $body] = match ($case) {
    'http_401' => [401, '{"status":"error","message":"Unauthorized"}'],
    'invalid_token' => [200, '{"status":500,"message":"Invalid Token"}'],
    'undocumented' => [200, '{"status":"success","message":"queued"}'],
    default => [200, '{"status":200,"message":"Your SMS message has been successfully added to the queue and will be processed shortly.","message_id":"iSms-TEST1"}'],
};
http_response_code($http);
echo $body;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$stubServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $work . '/iprog'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($stubServer, $work): void {
    proc_terminate($stubServer);
    exec('rm -rf ' . escapeshellarg($work));
});
for ($i = 0; $i < 50 && @file_get_contents('http://127.0.0.1:' . $port . '/stub.php') === false; $i++) {
    usleep(100000);
}
@unlink($work . '/iprog/hits.log');

// Copy of iprog-sms.php pointed at the fake IPROG (the only change).
$iprogCopy = $work . '/iprog-sms-test-copy.php';
$iprogSource = (string) file_get_contents($app . '/iprog-sms.php');
$replaced = 0;
$iprogSource = str_replace('https://www.iprogsms.com/api/v1/sms_messages', 'http://127.0.0.1:' . $port . '/stub.php', $iprogSource, $replaced);
if ($replaced !== 1) {
    fwrite(STDERR, "Could not redirect the IPROG copy to the fake server; refusing to continue.\n");
    exit(2);
}
file_put_contents($iprogCopy, $iprogSource);

// Fake mailer for the success path: real message building, recorded instead of sent.
$fakeMailer = $work . '/fake-mailer.php';
file_put_contents($fakeMailer, '<?php $real = require ' . var_export($app . '/gmail-mailer.php', true) . ';'
    . ' return static function (array $d) use ($real): void { $real($d, static function (string $raw): void {'
    . ' file_put_contents(' . var_export($work . '/mail.log', true) . ', $raw . "\n", FILE_APPEND); }); };');
$GLOBALS['SA_TEST_MAILER'] = $fakeMailer;

// ---- Load the real notification functions from web/api.php ----
require $app . '/web/bootstrap.php';
$pdo = sa_db();
$pdo->exec('DROP TABLE IF EXISTS notifications');
$pdo->exec("CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, request_id INT NULL, type VARCHAR(40) NOT NULL, message TEXT NOT NULL,
    channel ENUM('system','sms','email') NOT NULL DEFAULT 'system', delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
    read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$api = (string) file_get_contents($app . '/web/api.php');
$extract = static function (string $name) use ($api): string {
    $start = strpos($api, "function {$name}(");
    if ($start === false) {
        throw new RuntimeException("{$name} not found in api.php");
    }
    $depth = 0;
    $tokens = token_get_all('<?php ' . substr($api, $start));
    $code = '';
    foreach (array_slice($tokens, 1) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $code .= $text;
        if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
            if ($depth === 0) {
                return $code;
            }
        }
    }
    throw new RuntimeException("Could not read {$name}");
};
$code = '';
foreach (['sa_sms_enabled', 'sa_sms_load_service', 'sa_sms_deliver', 'sa_email_config_dir', 'sa_email_configured',
          'sa_email_deliver'] as $function) {
    $code .= $extract($function) . "\n";
}
$code = str_replace("dirname(__DIR__) . '/iprog-sms.php'", var_export($iprogCopy, true), $code);
$code = str_replace("dirname(__DIR__) . '/gmail-mailer.php'", "\$GLOBALS['SA_TEST_MAILER']", $code);
file_put_contents($work . '/notification-functions.php', "<?php\n" . $code);
require $work . '/notification-functions.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : json_encode($detail)];
};
$rows = static function () use ($pdo): array {
    return $pdo->query('SELECT channel, type, delivery_status, message FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
};
$hits = static function () use ($work): array {
    $file = $work . '/iprog/hits.log';
    return is_file($file) ? array_map(static fn ($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
};
$reset = static function () use ($pdo, $work): void {
    $pdo->exec('DELETE FROM notifications');
    @unlink($work . '/iprog/hits.log');
    @unlink($work . '/mail.log');
};
$mailer = require $app . '/gmail-mailer.php';
$details = ['email' => 'juana.test@example.invalid', 'first_name' => 'Juana', 'last_name' => 'Dela Cruz', 'flow' => 'docreq', 'reference_no' => 'DR-2026-TEST0001'];
$decode = static function (string $raw): array {
    $mime = base64_decode(strtr($raw, '-_', '+/') . str_repeat('=', (4 - strlen($raw) % 4) % 4));
    [$head, $body] = explode("\r\n\r\n", $mime, 2);
    return [$head, base64_decode(str_replace("\r\n", '', $body))];
};

// ================= Email =================
$captured = null;
$mailer($details, static function (string $raw) use (&$captured): void { $captured = $raw; });
[$head, $body] = $decode((string) $captured);
$check('email: recipient and subject', str_contains($head, "To: juana.test@example.invalid\r\n") && str_contains($head, 'Subject: SMART ASSESS: We received your Document Request (DR-2026-TEST0001)'), $head);
$check('email: plain UTF-8 text', str_contains($head, 'Content-Type: text/plain; charset=UTF-8'));
$check('email: name, reference, tracking link, claim note', str_contains($body, 'Dear Juana Dela Cruz,') && str_contains($body, 'Reference Number: DR-2026-TEST0001')
    && str_contains($body, 'https://smartassessmabini.com/?track=DR-2026-TEST0001') && str_contains($body, 'claim it in person'), $body);
$captured = null;
$mailer(['flow' => 'landtransfer', 'reference_no' => 'LT-2026-TEST0002'] + $details, static function (string $raw) use (&$captured): void { $captured = $raw; });
$check('email: land transfer wording', str_contains($decode((string) $captured)[0], 'We received your Land Transfer request (LT-2026-TEST0002)'));
$headerLines = static fn (string $h): array => array_map(static fn ($l) => strtok($l, ':'), explode("\r\n", $h));
$captured = null;
$mailer(['first_name' => "Juana\r\nBcc: attacker@example.invalid"] + $details, static function (string $raw) use (&$captured): void { $captured = $raw; });
[$head] = $decode((string) $captured);
$check('email: line breaks in a name cannot add headers', $headerLines($head) === ['To', 'Subject', 'MIME-Version', 'Content-Type', 'Content-Transfer-Encoding'], $head);
foreach (['email with a line break' => ['email' => "a@example.invalid\r\nBcc: b@example.invalid"], 'invalid email' => ['email' => 'nope'],
          'invalid reference' => ['reference_no' => "DR\r\nX"], 'unknown flow' => ['flow' => 'other']] as $label => $override) {
    try {
        $mailer($override + $details, static function (): void {});
        $check("email: {$label} rejected", false, 'accepted');
    } catch (RuntimeException $e) {
        $check("email: {$label} rejected", str_starts_with($e->getMessage(), '[mailer] '), $e->getMessage());
    }
}
try {
    $mailer($details);
    $check('email: missing credentials folder fails safely', false, 'no exception');
} catch (RuntimeException $e) {
    $check('email: missing credentials folder fails safely', $e->getMessage() === '[mailer] Gmail dependencies, OAuth client, or private token are unavailable.', $e->getMessage());
}
putenv('SMART_ASSESS_GMAIL_DIR');
try {
    $mailer($details);
    $check('email: no folder setting and no HOME fails with setup hint', false, 'no exception');
} catch (RuntimeException $e) {
    $check('email: no folder setting and no HOME fails with setup hint', str_contains($e->getMessage(), 'set SMART_ASSESS_GMAIL_DIR'), $e->getMessage());
}
putenv('SMART_ASSESS_GMAIL_DIR=' . $work . '/empty-gmail-dir');

// Staff message mode (the Notifications page).
$staff = ['email' => 'juana.test@example.invalid', 'first_name' => 'Juana', 'last_name' => 'Dela Cruz', 'reference_no' => 'DR-2026-TEST0001',
          'message' => "Your request was not approved.\nPlease submit the missing Tax Clearance."];
$captured = null;
$mailer($staff, static function (string $raw) use (&$captured): void { $captured = $raw; });
[$head, $body] = $decode((string) $captured);
$check('staff email: recipient and subject', str_contains($head, "To: juana.test@example.invalid\r\n") && str_contains($head, 'Subject: SMART ASSESS: Update on your request (DR-2026-TEST0001)'), $head);
$check('staff email: greeting, staff text with its line break, reference, tracking link', str_contains($body, 'Dear Juana Dela Cruz,')
    && str_contains($body, "Your request was not approved.\r\nPlease submit the missing Tax Clearance.") && str_contains($body, 'Reference Number: DR-2026-TEST0001')
    && str_contains($body, 'https://smartassessmabini.com/?track=DR-2026-TEST0001'), $body);
$captured = null;
$mailer(['message' => "Hello\r\nBcc: attacker@example.invalid"] + $staff, static function (string $raw) use (&$captured): void { $captured = $raw; });
$check('staff email: line breaks in the message cannot add headers', $headerLines($decode((string) $captured)[0]) === ['To', 'Subject', 'MIME-Version', 'Content-Type', 'Content-Transfer-Encoding']);
foreach (['empty message' => ['message' => "  \n "], 'invalid email' => ['email' => 'nope'], 'invalid reference' => ['reference_no' => "DR\r\nX"]] as $label => $override) {
    try {
        $mailer($override + $staff, static function (): void {});
        $check("staff email: {$label} rejected", false, 'accepted');
    } catch (RuntimeException $e) {
        $check("staff email: {$label} rejected", $e->getMessage() === '[mailer] Invalid notification email details.', $e->getMessage());
    }
}

$check('email setup: empty settings folder -> not configured', sa_email_configured() === false);
file_put_contents($work . '/empty-gmail-dir/gmail-token.json', '{}');
file_put_contents($work . '/empty-gmail-dir/google-oauth-client.json', '{}');
$check('email setup: token and client files present -> configured', sa_email_configured() === true);
unlink($work . '/empty-gmail-dir/gmail-token.json');
unlink($work . '/empty-gmail-dir/google-oauth-client.json');

$reset();
$check('email deliver: Gmail accepted -> "sent", nothing logged with the address', sa_email_deliver($staff, 'request ID 11; staff_message email') === 'sent' && count(file($work . '/mail.log')) === 1);
$GLOBALS['SA_TEST_MAILER'] = $app . '/gmail-mailer.php';
$check('email deliver: no credentials -> "failed", never "sent"', sa_email_deliver($staff, 'request ID 12; staff_message email') === 'failed');
$GLOBALS['SA_TEST_MAILER'] = $work . '/no-such-mailer.php';
$check('email deliver: missing mailer file -> "failed" (no fatal error)', sa_email_deliver($staff, 'request ID 13; staff_message email') === 'failed');
$GLOBALS['SA_TEST_MAILER'] = $fakeMailer;

// ================= No automatic sending =================
foreach (['sa_submit_request', 'sa_update_request_status', 'sa_start_request_processing', 'sa_complete_request_processing'] as $function) {
    $source = $extract($function);
    $check("no automatic SMS/email in {$function}", !preg_match('/sa_sms_deliver|sa_email_deliver|sa_send_notification|gmail-mailer|IprogSmsService/', $source));
}
$check('only sa_send_notification sends', substr_count($api, 'sa_sms_deliver(') === 2 && substr_count($api, 'sa_email_deliver(') === 2
    && str_contains($extract('sa_send_notification'), 'sa_sms_deliver(') && str_contains($extract('sa_send_notification'), 'sa_email_deliver('));

// ================= SMS =================
$message = 'SMART ASSESS test message for DR-2026-ABCDEF12.';
putenv('SMART_ASSESS_SMS_ENABLED=1');
$reset();
$check('sms token missing: "failed", IPROG not contacted', sa_sms_deliver('+639171234567', $message, 'request ID 21') === 'failed' && $hits() === []);
putenv('IPROG_API_TOKEN=TEST-TOKEN-ONLY');
$reset();
$outcome = sa_sms_deliver('+639171234567', $message, 'request ID 21');
$sent = $hits();
$check('sms accepted: "sent"', $outcome === 'sent', $outcome);
$check('sms accepted: one request to IPROG, normalised number, exact text', count($sent) === 1 && $sent[0]['phone'] === '639171234567' && $sent[0]['message'] === $message, $sent);
$check('sms token: sent only as the api_token field', ($sent[0]['token'] ?? '') === 'TEST-TOKEN-ONLY' && ($sent[0]['fields'] ?? []) === ['api_token', 'phone_number', 'message'], $sent[0]['fields'] ?? []);
foreach (['http_401' => 'failed', 'invalid_token' => 'failed', 'undocumented' => 'pending'] as $case => $expected) {
    file_put_contents($work . '/iprog/case.txt', $case);
    $reset();
    $outcome = sa_sms_deliver('+639171234567', $message, 'request ID 21');
    $check("sms IPROG reply '{$case}' -> \"{$expected}\" (one attempt, no retry)", $outcome === $expected && count($hits()) === 1, $outcome);
}
file_put_contents($work . '/iprog/case.txt', 'ok');
$reset();
$check('sms landline number: "failed", IPROG not contacted', sa_sms_deliver('+632123456789', $message, 'request ID 21') === 'failed' && $hits() === []);

// ================= Logs =================
$log = (string) @file_get_contents($errorLog);
$check('logs: no token, phone number, email address, or message text', !preg_match('/TEST-TOKEN-ONLY|639171234567|9171234567|@example\.invalid|We received your|missing Tax Clearance|SMART ASSESS test message/', $log), $log);
$check('logs: failures are explained', str_contains($log, '[SMART_ASSESS_EMAIL]') && str_contains($log, '[SMART_ASSESS_SMS]'), $log);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . substr($detail, 0, 220) : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
