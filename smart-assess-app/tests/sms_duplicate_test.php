<?php
declare(strict_types=1);

/*
 * Repeatable fake-transport tests for staff notifications, using the real
 * sa_send_notification() from web/api.php:
 *  - an unconfirmed SMS blocks further SMS for that request, whatever the
 *    wording and however long ago;
 *  - destinations and history records match the selected request/client;
 *  - an unconfirmed email cannot be retried with different wording;
 *  - only a recognised refusal counts as "not sent" and may be retried;
 *  - the only way past a block is the audited, password-checked override.
 *
 * Never sends a real SMS or email and uses no credits: the real iprog-sms.php is
 * never loaded (a copy points at a fake IPROG on 127.0.0.1 with a fake token),
 * and email goes through a fake mailer.
 * Uses ONLY a dedicated test database (name must end in "_test"); it drops and
 * recreates the tables it needs there.
 *
 *   SMART_ASSESS_TEST_DB=smart_assess_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=root SMART_ASSESS_DB_PASS='...' php tests/sms_duplicate_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise. Takes about two minutes.
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
$work = sys_get_temp_dir() . '/sa-sms-dup-test-' . bin2hex(random_bytes(4));
mkdir($work . '/gmail', 0700, true);
mkdir($work . '/iprog', 0700, true);
$errorLog = $work . '/php-errors.log';
ini_set('log_errors', '1');
ini_set('error_log', $errorLog);

putenv('HOME');
unset($_SERVER['HOME'], $_ENV['HOME']);
file_put_contents($work . '/gmail/gmail-token.json', '{}');
file_put_contents($work . '/gmail/google-oauth-client.json', '{}');
putenv('SMART_ASSESS_GMAIL_DIR=' . $work . '/gmail');
putenv('IPROG_API_TOKEN=TEST-TOKEN-ONLY');
putenv('SMART_ASSESS_SMS_ENABLED=1');
putenv('DB_NAME=' . $dbName);
foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USER' => 'root'] as $key => $default) {
    putenv($key . '=' . (getenv($key) ?: $default));
}
if (getenv('SMART_ASSESS_DB_PASS') === false) {
    putenv('SMART_ASSESS_DB_PASS=');
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';

// Fake IPROG: answers according to case.txt and records each send.
file_put_contents($work . '/iprog/case.txt', 'accepted');
file_put_contents($work . '/gmail/case.txt', 'accepted');
file_put_contents($work . '/iprog/stub.php', <<<'STUB'
<?php
$case = trim((string) @file_get_contents(__DIR__ . '/case.txt'));
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo isset($_GET['message_id'])
        ? json_encode(['status' => 200, 'message_status' => $case === 'accepted_then_failed' ? 'failed' : 'pending'])
        : '{"status":"success","message":"Account found.","data":{"load_balance":42}}';
    return;
}
file_put_contents(__DIR__ . '/hits.log', json_encode(['phone' => $_POST['phone_number'] ?? '']) . "\n", FILE_APPEND);
if ($case === 'timeout') {
    sleep(17);
}
[$http, $body] = match ($case) {
    'undocumented' => [200, '{"status":"success","message":"queued"}'],
    'http_500' => [500, 'server error'],
    'not_json' => [200, 'not json'],
    'body_error_unknown' => [200, '{"status":500,"message":"Temporary provider problem"}'],
    'http_400_unknown' => [400, '{"status":"error","message":"Rejected for review"}'],
    'invalid_token' => [200, '{"status":500,"message":"Invalid Token"}'],
    'smart_tnt' => [422, '{"status":"error","message":"Smart/TNT networks do not accept shared sender names."}'],
    'no_credits' => [200, '{"status":500,"message":"Insufficient credits"}'],
    default => [200, '{"status":200,"message":"queued","message_id":"iSms-DUP1"}'],
};
http_response_code($http);
echo $body;
STUB);
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

$iprogFor = static function (string $target) use ($app): string {
    $source = (string) file_get_contents($app . '/iprog-sms.php');
    foreach (['sms_messages/status', 'account/sms_credits', 'sms_messages'] as $path) {
        $replaced = 0;
        $source = str_replace('https://www.iprogsms.com/api/v1/' . $path, 'http://127.0.0.1:' . $target . '/stub.php', $source, $replaced);
        if ($replaced !== 1) {
            fwrite(STDERR, "Could not redirect the IPROG copy to the fake server; refusing to continue.\n");
            exit(2);
        }
    }
    return $source;
};
$iprogCopy = $work . '/iprog-sms-test-copy.php';
file_put_contents($iprogCopy, $iprogFor((string) $port));

$fakeMailer = $work . '/fake-mailer.php';
file_put_contents($fakeMailer, '<?php $real = require ' . var_export($app . '/gmail-mailer.php', true) . ';'
    . ' return static function (array $d) use ($real): void {'
    . ' if (!empty($d["check_only"])) return;'
    . ' $capture = static function () use ($d): void { file_put_contents('
    . var_export($work . '/mail.log', true)
    . ', json_encode(["email" => $d["email"] ?? "", "reference" => $d["reference_no"] ?? ""]) . "\n", FILE_APPEND); };'
    . ' if (trim((string) @file_get_contents(' . var_export($work . '/gmail/case.txt', true) . ')) === "unconfirmed") {'
    . ' $capture(); throw new RuntimeException("[mailer] Gmail did not confirm the message (no reply from Google)."); }'
    . ' $real($d, static function (string $raw) use ($capture): void { $capture(); }); };');
$GLOBALS['SA_TEST_MAILER'] = $fakeMailer;

require $app . '/web/bootstrap.php';
$pdo = sa_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['audit_log', 'request_draft_rate', 'notifications', 'request_status_log', 'requests', 'users'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, password_hash VARCHAR(255) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Received', reference_no VARCHAR(20) NOT NULL, flow VARCHAR(20) NOT NULL DEFAULT 'docreq',
    contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL, first_name VARCHAR(80) NOT NULL DEFAULT 'Juana', last_name VARCHAR(80) NOT NULL DEFAULT 'Test') ENGINE=InnoDB");
$pdo->exec("CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, request_id INT NULL, type VARCHAR(40) NOT NULL, message TEXT NOT NULL,
    channel ENUM('system','sms','email') NOT NULL DEFAULT 'system', delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE request_status_log (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, status VARCHAR(30) NOT NULL, actor VARCHAR(10) NOT NULL DEFAULT 'staff', sms_body TEXT NOT NULL,
    sms_category VARCHAR(60) NULL, delivery_status VARCHAR(20) NULL, sms_sent_by_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE audit_log (id INT AUTO_INCREMENT PRIMARY KEY, actor_type VARCHAR(10) NOT NULL, actor_id INT NULL, actor_name VARCHAR(150) NOT NULL, action VARCHAR(120) NOT NULL, target VARCHAR(150) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE request_draft_rate (id INT AUTO_INCREMENT PRIMARY KEY, client_hash CHAR(64) NOT NULL, bucket VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

$password = 'Test-Override-Pass-1';
$insertUser = $pdo->prepare('INSERT INTO users (name, password_hash) VALUES (:n, :h)');
foreach (['Staff One', 'Staff Two'] as $name) {
    $insertUser->execute(['n' => $name, 'h' => password_hash($password, PASSWORD_DEFAULT)]);
}
$GLOBALS['SA_TEST_USER'] = ['id' => 1, 'name' => 'Staff One'];

// ---- Load the real functions ----
$extractFrom = static function (string $source, string $name): string {
    $start = strpos($source, "function {$name}(");
    if ($start === false) {
        throw new RuntimeException("{$name} not found");
    }
    $depth = 0;
    $code = '';
    foreach (array_slice(token_get_all('<?php ' . substr($source, $start)), 1) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $code .= $text;
        if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($text === '}' && --$depth === 0) {
            return $code;
        }
    }
    throw new RuntimeException("Could not read {$name}");
};
$api = (string) file_get_contents($app . '/web/api.php');
$drafts = (string) file_get_contents($app . '/web/drafts.php');
preg_match('/^const SA_NOTIFICATION_CATEGORIES = \[.*?\];$/ms', $api, $categories);
$code = $categories[0] . "\n";
foreach (['sa_field_label', 'sa_text', 'sa_notification_category', 'sa_audit', 'sa_sms_enabled', 'sa_sms_load_service', 'sa_sms_deliver', 'sa_sms_failure_reason', 'sa_sms_provider_detail',
          'sa_sms_unconfirmed_reason', 'sa_sms_unconfirmed_ids', 'sa_sms_authorize_override', 'sa_email_config_dir', 'sa_email_configured', 'sa_email_deliver', 'sa_email_status', 'sa_send_notification'] as $function) {
    $code .= $extractFrom($api, $function) . "\n";
}
foreach (['sa_draft_client_hash', 'sa_draft_rate_check', 'sa_draft_rate_hit'] as $function) {
    $code .= $extractFrom($drafts, $function) . "\n";
}
$code .= <<<'STUBS'
function sa_drafts_enabled(): bool { return true; }
function sa_internal_or_fail(array $roles): array
{
    if ($GLOBALS['SA_TEST_USER'] === null) {
        throw new SaHttpException(401);
    }
    return $GLOBALS['SA_TEST_USER'];
}
STUBS;
$code = str_replace("dirname(__DIR__) . '/iprog-sms.php'", "\$GLOBALS['SA_TEST_IPROG']", $code);
$code = str_replace("dirname(__DIR__) . '/gmail-mailer.php'", "\$GLOBALS['SA_TEST_MAILER']", $code);
$GLOBALS['SA_TEST_IPROG'] = $iprogCopy;
file_put_contents($work . '/functions.php', "<?php\n" . $code);
require $work . '/functions.php';

// ---- Helpers ----
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : json_encode($detail)];
};
// The fake IPROG and the fake mailer each read their own case file.
$case = static function (string $name) use ($work): void {
    file_put_contents($work . '/iprog/case.txt', $name);
    file_put_contents($work . '/gmail/case.txt', $name);
};
$hits = static fn (): int => is_file($work . '/iprog/hits.log') ? count(file($work . '/iprog/hits.log')) : 0;
$mails = static fn (): int => is_file($work . '/mail.log') ? count(file($work . '/mail.log')) : 0;
$newRequest = static function (
    string $email = 'juana.test@example.invalid',
    string $phone = '+639171234567',
    ?int $clientId = null
) use ($pdo): string {
    static $n = 0;
    $reference = sprintf('DR-2026-DUP%05d', ++$n);
    $pdo->prepare('INSERT INTO requests (client_id, reference_no, contact_number, email) VALUES (:client, :r, :c, :e)')
        ->execute(['client' => $clientId, 'r' => $reference, 'c' => $phone, 'e' => $email]);
    return $reference;
};
/** Calls the real send; returns [http status, error code or '', data or error text]. */
$send = static function (string $reference, string $message, array $extra = ['send_sms' => '1']): array {
    try {
        $data = sa_send_notification(['request_ref' => $reference, 'message' => $message, 'channel_choice' => '1'] + $extra);
        return [200, '', $data];
    } catch (SaHttpException $exception) {
        return [$exception->statusCode, (string) $exception->errorCode, $exception->getMessage()];
    }
};
$rows = static function (string $reference) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT n.request_id, n.client_id, n.channel, n.type, n.delivery_status FROM notifications n JOIN requests r ON r.id = n.request_id WHERE r.reference_no = :r ORDER BY n.id');
    $stmt->execute(['r' => $reference]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$sentSmsRecipients = static function () use ($work): array {
    $file = $work . '/iprog/hits.log';
    return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
};
$sentEmailRecipients = static function () use ($work): array {
    $file = $work . '/mail.log';
    return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
};
$age = static function (string $reference, string $interval) use ($pdo): void {
    $pdo->prepare("UPDATE notifications n JOIN requests r ON r.id = n.request_id SET n.created_at = NOW() - INTERVAL {$interval} WHERE r.reference_no = :r")->execute(['r' => $reference]);
};
$override = static fn (array $changes = []) => $changes + [
    'send_sms' => '1', 'override_unconfirmed' => true, 'override_reason' => 'Client confirmed nothing arrived by phone.',
    'override_acknowledged' => true, 'override_password' => $password,
];

// ================= Recipient routing, request/client linkage and email outcomes =================
$case('accepted');
$clientOne = 4101;
$clientTwo = 4102;
$referenceOne = $newRequest('client.one@example.invalid', '+639171112222', $clientOne);
$referenceTwo = $newRequest('client.two@example.invalid', '+639171113333', $clientTwo);
$requestOneQuery = $pdo->prepare('SELECT id FROM requests WHERE reference_no = :r');
$requestOneQuery->execute(['r' => $referenceOne]);
$requestIdOne = (int) $requestOneQuery->fetchColumn();
$smsBefore = $hits();
$mailBefore = $mails();
[$status, , $data] = $send($referenceOne, 'First client specific notification', [
    'send_sms' => '1',
    'send_email' => '1',
    'email' => 'client.two@example.invalid',
    'contact_number' => '+639171113333',
]);
$check('both channels send successfully for the selected request', $status === 200 && $data['channels'] === ['sms' => 'sent', 'email' => 'sent'], [$status, $data]);
$requestRows = $rows($referenceOne);
$check('both notification records link to the selected request and its client', count($requestRows) === 2
    && array_reduce($requestRows, static fn (bool $ok, array $row): bool => $ok
        && (int) $row['request_id'] === $requestIdOne
        && (int) $row['client_id'] === $clientOne, true), $requestRows);
$smsSent = $sentSmsRecipients();
$emailSent = $sentEmailRecipients();
$check('SMS and email use only the selected request registered contacts', $hits() === $smsBefore + 1 && $mails() === $mailBefore + 1
    && end($smsSent)['phone'] === '639171112222'
    && end($emailSent) === ['email' => 'client.one@example.invalid', 'reference' => $referenceOne],
    [end($smsSent), end($emailSent)]);
$statusLog = $pdo->prepare('SELECT actor, delivery_status FROM request_status_log WHERE request_id = :id ORDER BY id');
$statusLog->execute(['id' => $requestIdOne]);
$historyRows = $statusLog->fetchAll(PDO::FETCH_ASSOC);
$check('per-channel delivery results are recorded in request history', count($historyRows) === 2
    && $historyRows[0]['actor'] === 'staff' && $historyRows[0]['delivery_status'] === 'sent'
    && $historyRows[1]['actor'] === 'email' && $historyRows[1]['delivery_status'] === 'sent', $historyRows);
[$status, , $data] = $send($referenceOne, 'First client specific notification', ['send_email' => '1']);
$check('repeat email to the same request is refused without another send', $status === 409
    && $hits() === $smsBefore + 1 && $mails() === $mailBefore + 1, [$status, $data]);
$otherIdQuery = $pdo->prepare('SELECT id FROM requests WHERE reference_no = :r');
$otherIdQuery->execute(['r' => $referenceTwo]);
$requestIdTwo = (int) $otherIdQuery->fetchColumn();
$beforeOther = $hits();
[$status, , $data] = $send($referenceTwo, 'Second client specific SMS');
$otherRows = $rows($referenceTwo);
$otherSmsSent = $sentSmsRecipients();
$check('another client request uses its own contact and remains independently sendable', $status === 200
    && $hits() === $beforeOther + 1 && end($otherSmsSent)['phone'] === '639171113333'
    && count($otherRows) === 1 && (int) $otherRows[0]['request_id'] === $requestIdTwo
    && (int) $otherRows[0]['client_id'] === $clientTwo, [$status, $otherRows, end($otherSmsSent)]);

$emailPendingReference = $newRequest('pending.client@example.invalid', '+639171114444', 4103);
$case('unconfirmed');
$beforePendingEmail = $mails();
[$status, , $data] = $send($emailPendingReference, 'Email outcome may be unclear', ['send_email' => '1']);
$pendingEmailRows = $rows($emailPendingReference);
$check('unconfirmed email is reported accurately and saved as pending', $status === 200
    && $data['channels'] === ['email' => 'pending'] && $data['delivery_status'] === 'pending'
    && str_contains($data['feedback'], 'outcome unconfirmed') && count($pendingEmailRows) === 1
    && $pendingEmailRows[0]['delivery_status'] === 'pending', [$status, $data, $pendingEmailRows]);
$case('accepted');
$beforeSmsBlocked = $hits();
[$status, $code] = $send($emailPendingReference, 'Different wording must not retry an uncertain email', ['send_sms' => '1', 'send_email' => '1']);
$check('pending email blocks a retry with changed wording before either channel sends', $status === 409
    && $code === 'email_unconfirmed' && $mails() === $beforePendingEmail + 1 && $hits() === $beforeSmsBlocked, [$status, $code]);
$age($emailPendingReference, '400 DAY');
[$status, $code] = $send($emailPendingReference, 'An old pending email must still block a retry', ['send_email' => '1']);
$check('an old pending email still blocks a possible duplicate', $status === 409
    && $code === 'email_unconfirmed' && $mails() === $beforePendingEmail + 1, [$status, $code]);
$beforeSmsOnly = $hits();
[$status, , $data] = $send($emailPendingReference, 'SMS remains available separately', ['send_sms' => '1']);
$check('pending email does not prevent an explicit SMS-only send', $status === 200
    && $data['channels'] === ['sms' => 'sent'] && $hits() === $beforeSmsOnly + 1 && $mails() === $beforePendingEmail + 1, [$status, $data]);

// ================= Every unconfirmed outcome blocks, permanently =================
foreach (['undocumented', 'http_500', 'not_json', 'body_error_unknown', 'http_400_unknown', 'accepted_then_failed', 'timeout'] as $outcome) {
    $case($outcome);
    $reference = $newRequest();
    $before = $hits();
    [$status, , $data] = $send($reference, 'First message for ' . $reference);
    $check("{$outcome}: recorded as unconfirmed, one provider call", $status === 200 && $data['delivery_status'] === 'pending' && $hits() === $before + 1, [$status, $data]);
    $check("{$outcome}: staff told it may have reached the client, never \"nothing was sent\"", is_array($data) && str_contains($data['feedback'], 'may have reached the client') && !str_contains($data['feedback'], 'Nothing was sent'), $data);
    $case('accepted');
    $after = $hits();
    foreach ([
        'same text' => 'First message for ' . $reference,
        'different capitals' => strtoupper('First message for ' . $reference),
        'reworded' => 'A completely different message',
    ] as $label => $text) {
        [$status, $code] = $send($reference, $text);
        $check("{$outcome}: blocked, {$label}", $status === 409 && $code === 'sms_unconfirmed' && $hits() === $after, [$status, $code]);
    }
    if ($outcome === 'undocumented') {
        foreach (['11 MINUTE', '1 DAY', '400 DAY'] as $interval) {
            $age($reference, $interval);
            [$status, $code] = $send($reference, 'First message for ' . $reference);
            $check("blocked after {$interval}, same text", $status === 409 && $code === 'sms_unconfirmed' && $hits() === $after, [$status, $code]);
            [$status, $code] = $send($reference, 'Reworded after ' . $interval);
            $check("blocked after {$interval}, reworded", $status === 409 && $code === 'sms_unconfirmed' && $hits() === $after, [$status, $code]);
        }
        $GLOBALS['SA_TEST_USER'] = ['id' => 2, 'name' => 'Staff Two'];
        [$status, $code] = $send($reference, 'From another staff member');
        $check('blocked for a different staff account (new sign-in)', $status === 409 && $code === 'sms_unconfirmed' && $hits() === $after);
        $GLOBALS['SA_TEST_USER'] = ['id' => 1, 'name' => 'Staff One'];
    }
}

$reference = $newRequest();
$pdo->prepare("INSERT INTO notifications (request_id, type, message, channel, delivery_status) SELECT id, 'staff_message', 'Crashed mid-send', 'sms', 'pending' FROM requests WHERE reference_no = :r")->execute(['r' => $reference]);
[$status, $code] = $send($reference, 'Another message');
$check('a send that crashed before recording an outcome also blocks', $status === 409 && $code === 'sms_unconfirmed');

$case('undocumented');
$reference = $newRequest();
$send($reference, 'Unconfirmed first');
$case('accepted');
$hitsBefore = $hits();
$mailsBefore = $mails();
[$status, $code] = $send($reference, 'Both ticked', ['send_sms' => '1', 'send_email' => '1']);
$check('SMS and email ticked while blocked: nothing goes out on either channel', $status === 409 && $code === 'sms_unconfirmed' && $hits() === $hitsBefore && $mails() === $mailsBefore);
[$status, , $data] = $send($reference, 'Email is still possible', ['send_email' => '1']);
$check('email alone is still allowed while SMS is blocked', $status === 200 && $data['channels'] === ['email' => 'sent'] && $mails() === $mailsBefore + 1 && $hits() === $hitsBefore, [$status, $data]);
$other = $newRequest();
[$status] = $send($other, 'A different client');
$check('the block covers only that request', $status === 200 && $hits() === $hitsBefore + 1);

// ================= The override is deliberate, checked and audited =================
$case('undocumented');
$reference = $newRequest();
$send($reference, 'Needs an override');
$case('accepted');
$hitsBefore = $hits();
$pdo->exec('DELETE FROM request_draft_rate');
$auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
[$status, $code] = $send($reference, 'I checked the IPROG account and it looks fine', ['send_sms' => '1', 'checked_iprog' => '1']);
$check('saying IPROG was checked does not unlock sending', $status === 409 && $code === 'sms_unconfirmed' && $hits() === $hitsBefore);
foreach ([
    'no reason' => ['override_reason' => ''],
    'a too-short reason' => ['override_reason' => 'because'],
    'a wrong reference' => ['override_reference' => 'DR-2026-OTHER'],
    'risk not accepted' => ['override_acknowledged' => false],
] as $label => $changes) {
    [$status] = $send($reference, 'Override attempt', $override($changes + ['override_reference' => $reference]));
    $check("override refused with {$label}", $status === 422 && $hits() === $hitsBefore, $status);
}
[$status] = $send($reference, 'Override attempt', $override(['override_password' => 'wrong-password', 'override_reference' => $reference]));
$check('override refused with a wrong password, nothing sent, refusal audited', $status === 403 && $hits() === $hitsBefore
    && str_contains((string) $pdo->query('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(), 'refused'), $status);
$check('refused overrides leave the block in place', count(sa_sms_unconfirmed_ids($pdo, (int) $pdo->query("SELECT id FROM requests WHERE reference_no = '{$reference}'")->fetchColumn())) === 1);
[$status] = $send($reference, 'Override attempt', $override(['override_reference' => $reference]));
$check('rate limit: too many override attempts are refused even with the right details', $status === 429 && $hits() === $hitsBefore, $status);
$pdo->exec('DELETE FROM request_draft_rate');

[$status, , $data] = $send($reference, 'Sent after override', $override(['override_reference' => strtolower($reference)]));
$check('valid override sends exactly one SMS', $status === 200 && $data['channels'] === ['sms' => 'sent'] && $hits() === $hitsBefore + 1, [$status, $data]);
$check('the old attempt stays unconfirmed in the records, its hold released', $rows($reference)[0]['delivery_status'] === 'pending' && $rows($reference)[0]['type'] === 'staff_message_overridden', $rows($reference));
$audit = $pdo->query("SELECT actor_id, actor_name, action, target FROM audit_log WHERE action LIKE 'SMS duplicate-risk override:%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check('override audited with who, why and which record', (int) $audit['actor_id'] === 1 && str_contains($audit['action'], 'SMS duplicate-risk override: Client confirmed') && str_contains($audit['target'], $reference), $audit);
$history = $pdo->query("SELECT sms_body FROM request_status_log WHERE sms_body LIKE 'Duplicate-risk SMS override%' ORDER BY id DESC LIMIT 1")->fetchColumn();
$check('override shown in the request history with the reason', is_string($history) && str_contains($history, 'Staff One') && str_contains($history, 'Client confirmed'), $history);
[$status, $code] = $send($reference, 'Sent after override');
$check('the 10-minute repeat check still applies after an override', $status === 409 && $code === '' && $hits() === $hitsBefore + 1, [$status, $code]);
$pdo->exec('DELETE FROM request_draft_rate');
[$status, $code] = $send($reference, 'Nothing to override', $override(['override_reference' => $reference]));
$check('an override with nothing unconfirmed is refused', $status === 409 && $code === '' && $hits() === $hitsBefore + 1, [$status, $code]);

$case('undocumented');
$reference = $newRequest();
$send($reference, 'First attempt');
$hitsBefore = $hits();
$pdo->exec('DELETE FROM request_draft_rate');
[$status, , $data] = $send($reference, 'Override that is also unclear', $override(['override_reference' => $reference]));
$check('an override that also comes back unclear blocks again', $status === 200 && $data['delivery_status'] === 'pending' && $hits() === $hitsBefore + 1);
$case('accepted');
[$status, $code] = $send($reference, 'Third attempt');
$check('... and needs its own override', $status === 409 && $code === 'sms_unconfirmed' && $hits() === $hitsBefore + 1);

// ================= Only a clear refusal may be retried =================
foreach (['invalid_token' => 'API token', 'smart_tnt' => 'Smart or TNT', 'no_credits' => 'credits'] as $outcome => $word) {
    $case($outcome);
    $reference = $newRequest();
    [$status, , $data] = $send($reference, 'Refused message');
    $check("{$outcome}: \"failed\", says nothing was sent and why", $status === 200 && $data['delivery_status'] === 'failed' && str_contains($data['feedback'], $word) && str_contains($data['feedback'], 'Nothing was sent'), $data);
    $case('accepted');
    [$status, , $data] = $send($reference, 'Refused message');
    $check("{$outcome}: the same message may be retried", $status === 200 && $data['channels'] === ['sms' => 'sent'], [$status, $data]);
}
$closed = stream_socket_server('tcp://127.0.0.1:0');
$closedPort = (int) substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
fclose($closed);
$unreachable = $work . '/iprog-unreachable.php';
file_put_contents($unreachable, str_replace('IprogSms', 'IprogDown', $iprogFor((string) $closedPort)));
require $unreachable;
try {
    IprogDownService::fromEnvironment()->send('+639171234567', 'Test');
    $check('connection refused before reaching IPROG counts as not sent', false, 'no exception');
} catch (IprogDownException $exception) {
    $check('connection refused before reaching IPROG counts as not sent', $exception->outcome() === IprogDownException::REJECTED);
}

// ================= Accepted is not delivered =================
$case('accepted');
$reference = $newRequest();
[$status, , $data] = $send($reference, 'Accepted message');
$check('accepted: staff told it is queued, not delivered', $status === 200 && str_contains($data['feedback'], 'queued') && !preg_match('/delivered|reached the phone\.$/i', str_replace('does not confirm it reached the phone', '', $data['feedback'])), $data);
[$status, $code, $text] = $send($reference, 'Accepted message');
$check('accepted: repeat within 10 minutes refused, and not worded as delivered', $status === 409 && $code === '' && str_contains((string) $text, 'accepted for SMS'), [$status, $text]);
$before = $hits();
[$status] = $send($reference, 'A new, different message');
$check('accepted: a genuinely new message may be sent', $status === 200 && $hits() === $before + 1);
$page = (string) file_get_contents($app . '/web/internal.php');
$check('history: an SMS IPROG accepted shows as Accepted, an email as Sent', str_contains($page, "\$isEmail = in_array(\$actor, ['email', 'auto_email'], true);")
    && str_contains($page, "'sent' => \$isEmail ? 'Sent' : 'Accepted'"));
$staffPage = (string) file_get_contents(dirname($app) . '/smart-assess-internal.html');
$check('history: the Accepted label says it does not confirm delivery', str_contains($staffPage, "Accepted: 'Accepted by IPROG for sending. This does not confirm it reached the phone.'"));

// ================= Simultaneous clicks =================
$other = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), $dbName), (string) getenv('DB_USER'), (string) getenv('SMART_ASSESS_DB_PASS'));
$reference = $newRequest();
$id = (int) $pdo->query("SELECT id FROM requests WHERE reference_no = '{$reference}'")->fetchColumn();
$other->query("SELECT GET_LOCK('sa_notify_{$id}', 1)")->fetchColumn();
$before = $hits();
[$status] = $send($reference, 'While another send is running');
$check('a send already running for the request makes the next one wait, then refuses it', $status === 409 && $hits() === $before, $status);
$other->query("SELECT RELEASE_LOCK('sa_notify_{$id}')");

// ================= Other messages do not block, and sending stays explicit =================
$reference = $newRequest();
$pdo->prepare("INSERT INTO notifications (request_id, type, message, channel, delivery_status) SELECT id, 'sms_status_update', 'Old automatic SMS', 'sms', 'pending' FROM requests WHERE reference_no = :r")->execute(['r' => $reference]);
[$status] = $send($reference, 'After an old automatic SMS');
$check('an old automatic SMS (not a staff send) does not block', $status === 200);
foreach (['sa_submit_request', 'sa_update_request_status', 'sa_start_request_processing', 'sa_complete_request_processing'] as $function) {
    // Submissions only queue their confirmation (sent afterwards by bin/send-submission-confirmations.php);
    // status changes and processing never send anything. Nothing here sends inline.
    $check($function === 'sa_submit_request' ? 'sa_submit_request sends nothing inline (it only queues the confirmation)' : "no automatic SMS/email in {$function}",
        !preg_match('/sa_sms_deliver|sa_email_deliver|sa_send_notification|gmail-mailer|IprogSmsService/', $extractFrom($api, $function)));
}
$GLOBALS['SA_TEST_USER'] = null;
$before = $hits();
[$status] = $send($reference, 'Signed out');
$check('signed out cannot send', $status === 401 && $hits() === $before, $status);

// ================= Logs =================
$log = (string) @file_get_contents($errorLog);
$check('logs: no token, password, phone number, address or message text', !preg_match('/TEST-TOKEN-ONLY|Test-Override-Pass|9171234567|@example\.invalid|First message|Client confirmed/', $log), $log);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . substr($detail, 0, 260) : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
