<?php
declare(strict_types=1);

/*
 * Repeatable fake-transport tests for a staff notification sent for one client's
 * request, using the real sa_send_notification(), sa_lookup_request_owner() and
 * sa_notification_history():
 *  - email and SMS go only to the selected request's own contact details;
 *  - records link to the correct request and client, including guest requests;
 *  - nothing in the submitted form can change a destination;
 *  - repeats are refused on each channel, and failures do not block a retry;
 *  - results and history report each channel accurately, with masked details.
 *
 * Never sends a real SMS or email and uses no credits: the real iprog-sms.php is
 * never loaded (a copy points at a fake IPROG on 127.0.0.1 with a fake token),
 * and email goes through a fake mailer. All contact details are made up.
 * Uses ONLY a dedicated test database (name must end in "_test"); it drops and
 * recreates the tables it needs there.
 *
 *   SMART_ASSESS_TEST_DB=smart_assess_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=root SMART_ASSESS_DB_PASS='...' php tests/staff_notification_test.php
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
$work = sys_get_temp_dir() . '/sa-staff-notify-test-' . bin2hex(random_bytes(4));
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
    . ' if (trim((string) @file_get_contents(' . var_export($work . '/gmail/case.txt', true) . ')) === "email_refused") {'
    . ' throw new RuntimeException("[mailer] Gmail refused the message (HTTP 400)."); }'
    . ' $real($d, static function (string $raw) use ($capture): void { $capture(); }); };');
$GLOBALS['SA_TEST_MAILER'] = $fakeMailer;

require $app . '/web/bootstrap.php';
$pdo = sa_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['audit_log', 'request_draft_rate', 'notifications', 'request_status_log', 'requests', 'users', 'clients'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE clients (id INT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, password_hash VARCHAR(255) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Received', reference_no VARCHAR(20) NOT NULL, flow VARCHAR(20) NOT NULL DEFAULT 'docreq',
    contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL, first_name VARCHAR(80) NOT NULL DEFAULT 'Juana', middle_name VARCHAR(80) NULL, last_name VARCHAR(80) NOT NULL DEFAULT 'Test', is_owner TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB");
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
          'sa_sms_unconfirmed_reason', 'sa_sms_unconfirmed_ids', 'sa_sms_authorize_override', 'sa_email_config_dir', 'sa_email_configured', 'sa_email_deliver', 'sa_email_status', 'sa_send_notification', 'sa_lookup_request_owner'] as $function) {
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
$newRequest = static function (string $reference, string $first, string $last, string $phone, ?string $email, ?int $clientId) use ($pdo): int {
    $pdo->prepare('INSERT INTO requests (client_id, reference_no, contact_number, email, first_name, last_name) VALUES (:client, :r, :c, :e, :f, :l)')
        ->execute(['client' => $clientId, 'r' => $reference, 'c' => $phone, 'e' => $email, 'f' => $first, 'l' => $last]);
    return (int) $pdo->lastInsertId();
};
/** Calls the real send; returns [http status, error code or '', data or error text]. */
$send = static function (string $reference, string $message, array $extra = ['send_sms' => '1']): array {
    try {
        $data = sa_send_notification(['request_ref' => $reference, 'message' => $message, 'channel_choice' => '1'] + $extra);
        return [200, '', $data];
    } catch (SaHttpException $exception) {
        return [$exception->statusCode, (string) $exception->errorCode, $exception->getMessage()];
    } catch (PDOException $exception) {
        return [500, 'database', ''];
    }
};
$rows = static function (string $reference) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT n.request_id, n.client_id, n.channel, n.type, n.delivery_status FROM notifications n JOIN requests r ON r.id = n.request_id WHERE r.reference_no = :r ORDER BY n.id');
    $stmt->execute(['r' => $reference]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$lastSms = static function () use ($work): string {
    $lines = is_file($work . '/iprog/hits.log') ? file($work . '/iprog/hits.log', FILE_IGNORE_NEW_LINES) : [];
    return $lines === [] ? '' : (string) json_decode((string) end($lines), true)['phone'];
};
$lastMail = static function () use ($work): array {
    $lines = is_file($work . '/mail.log') ? file($work . '/mail.log', FILE_IGNORE_NEW_LINES) : [];
    return $lines === [] ? [] : (array) json_decode((string) end($lines), true);
};
$normalized = static fn (string $phone): string => preg_replace('/\D+/', '', $phone);
$both = ['send_sms' => '1', 'send_email' => '1'];

$pdo->exec("INSERT INTO clients (id, first_name, last_name) VALUES (11, 'Account', 'HolderOne'), (12, 'Account', 'HolderTwo')");
$phoneA = '+639170000101'; $mailA = 'alma.request@example.invalid';
$phoneB = '+639170000202'; $mailB = 'berto.request@example.invalid';
$phoneG = '+639170000303'; $mailG = 'guest.request@example.invalid';
$idA = $newRequest('DR-2026-NTA01', 'Alma', 'Request', $phoneA, $mailA, 11);
$idB = $newRequest('LT-2026-NTB01', 'Berto', 'Request', $phoneB, $mailB, 12);
$idG = $newRequest('DR-2026-NTG01', 'Greta', 'Guest', $phoneG, $mailG, null);
$idN = $newRequest('DR-2026-NTN01', 'Nina', 'Noemail', '+639170000404', null, null);
$case('accepted');

// ---- 1, 2, 3, 6: the right destination, linked to the right request and client ----
$s = $hits(); $m = $mails();
[$status, , $data] = $send('DR-2026-NTA01', 'Update for Alma', $both);
$rowsA = $rows('DR-2026-NTA01');
$check('email goes to the email on the opened request', $status === 200 && $mails() === $m + 1
    && $lastMail()['email'] === $mailA && $lastMail()['reference'] === 'DR-2026-NTA01', [$status, $lastMail()]);
$check('SMS goes to the number on the opened request', $hits() === $s + 1 && $normalized($lastSms()) === $normalized($phoneA), $lastSms());
$check('both records link to that request and its client', count($rowsA) === 2
    && array_column($rowsA, 'request_id') === [$idA, $idA] && array_column($rowsA, 'client_id') === [11, 11]
    && array_column($rowsA, 'channel') === ['sms', 'email'], $rowsA);
$check('nothing is recorded against another client', $rows('LT-2026-NTB01') === [] && $rows('DR-2026-NTG01') === []);
$check('the result names the request and each sent channel', $data['reference_no'] === 'DR-2026-NTA01'
    && $data['channels'] === ['sms' => 'sent', 'email' => 'sent'] && $data['delivery_status'] === 'sent'
    && str_contains($data['feedback'], 'Request DR-2026-NTA01.') && str_contains($data['feedback'], 'SMS to …0101: accepted by IPROG')
    && str_contains($data['feedback'], 'Email to a•••@example.invalid: accepted by Gmail'), $data);
$check('the result shows no full number or address', !str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), $mailA)
    && !str_contains(json_encode($data), '9170000101'), $data);

[$status] = $send('LT-2026-NTB01', 'Update for Alma', $both);
$check('the next client gets their own details, and the same wording is not a duplicate across clients', $status === 200
    && $normalized($lastSms()) === $normalized($phoneB) && $lastMail()['email'] === $mailB
    && array_column($rows('LT-2026-NTB01'), 'client_id') === [12, 12], [$status, $lastSms(), $lastMail()]);
$check('that send added nothing to the first client', count($rows('DR-2026-NTA01')) === 2);

[$status] = $send('DR-2026-NTG01', 'Update for Greta', $both);
$rowsG = $rows('DR-2026-NTG01');
$check('a request with no client account is linked to the request, with no client', $status === 200
    && array_column($rowsG, 'request_id') === [$idG, $idG] && array_column($rowsG, 'client_id') === [null, null]
    && $normalized($lastSms()) === $normalized($phoneG) && $lastMail()['email'] === $mailG, $rowsG);

[$status] = $send('DR-2026-NTA01', 'Forged destinations', $both + [
    'email' => $mailB, 'contact_number' => $phoneB, 'contactNumber' => $phoneB, 'phone' => $phoneB, 'phone_number' => $phoneB,
    'to' => $mailB, 'recipient' => $mailB, 'client_id' => 12, 'first_name' => 'Berto',
]);
$forged = $rows('DR-2026-NTA01');
$check('destinations in the submitted form are ignored', $status === 200 && $normalized($lastSms()) === $normalized($phoneA)
    && $lastMail()['email'] === $mailA && (int) end($forged)['client_id'] === 11, [$status, $lastSms(), $lastMail()]);

$s = $hits(); $m = $mails();
[$status] = $send('LT-2026-NTB01', 'Mismatch one', $both + ['request_id' => $idA]);
$check('a request ID and reference that disagree send nothing', $status === 422 && $hits() === $s && $mails() === $m, $status);
try {
    sa_send_notification(['request_id' => $idA, 'requestRef' => 'LT-2026-NTB01', 'message' => 'Mismatch two', 'channel_choice' => '1'] + $both);
    $status = 200;
} catch (SaHttpException $exception) {
    $status = $exception->statusCode;
}
$check('the same applies to the form field name', $status === 422 && $hits() === $s && $mails() === $m, $status);
[$status] = $send('DR-2026-NTA01', 'Matching ID', $both + ['request_id' => $idA]);
$check('a matching ID and reference still send', $status === 200 && $hits() === $s + 1 && $mails() === $m + 1, $status);
$s = $hits(); $m = $mails();
[$status] = $send('DR-2026-NOPE', 'Nobody', $both);
$check('an unknown reference sends nothing', $status === 422 && $hits() === $s && $mails() === $m, $status);
[$status] = $send('DR-2026-NTN01', 'No email here', $both);
$check('a request with no email sends neither channel when both are chosen, and never borrows an address', $status === 422
    && $hits() === $s && $mails() === $m, $status);
[$status] = $send('DR-2026-NTN01', 'SMS only here', ['send_sms' => '1']);
$check('that request can still be sent SMS alone', $status === 200 && $mails() === $m && $hits() === $s + 1, $status);

// ---- 4: duplicates ----
$s = $hits(); $m = $mails();
foreach ([[$both, 'both channels'], [['send_sms' => '1'], 'SMS only'], [['send_email' => '1'], 'email only']] as [$channels, $label]) {
    [$status] = $send('DR-2026-NTA01', 'Update for Alma', $channels);
    $check('repeat of the same message refused: ' . $label, $status === 409 && $hits() === $s && $mails() === $m, $status);
}
$GLOBALS['SA_TEST_USER'] = ['id' => 2, 'name' => 'Staff Two'];
[$status] = $send('DR-2026-NTA01', 'Update for Alma', $both);
$check('a different staff member cannot repeat it either', $status === 409 && $hits() === $s && $mails() === $m, $status);
$GLOBALS['SA_TEST_USER'] = ['id' => 1, 'name' => 'Staff One'];
$other = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . $dbName, getenv('DB_USER'), (string) getenv('SMART_ASSESS_DB_PASS'));
$other->query("SELECT GET_LOCK('sa_notify_{$idA}', 1)")->fetchColumn();
[$status] = $send('DR-2026-NTA01', 'While another send is running', $both);
$check('a send already running for this request blocks a second one', $status === 409 && $hits() === $s && $mails() === $m, $status);
$other->query("SELECT RELEASE_LOCK('sa_notify_{$idA}')");
$other = null;

// ---- 5: accurate results when a channel fails ----
$case('invalid_token');
$s = $hits(); $m = $mails();
[$status, , $data] = $send('LT-2026-NTB01', 'SMS refused, email fine', $both);
$check('SMS refused and email accepted are each reported', $status === 200 && $data['channels'] === ['sms' => 'failed', 'email' => 'sent']
    && $data['delivery_status'] === 'failed' && str_contains($data['feedback'], 'SMS to …0202: NOT sent')
    && str_contains($data['feedback'], 'Email to b•••@example.invalid: accepted'), $data);
$case('accepted');
[$status] = $send('LT-2026-NTB01', 'SMS refused, email fine', $both);
$check('retrying with both is refused, because the email already went', $status === 409 && $mails() === $m + 1, $status);
[$status, , $data] = $send('LT-2026-NTB01', 'SMS refused, email fine', ['send_sms' => '1']);
$check('the refused SMS alone can be retried', $status === 200 && $data['channels'] === ['sms' => 'sent'], [$status, $data]);

file_put_contents($work . '/gmail/case.txt', 'email_refused');
$s = $hits(); $m = $mails();
[$status, , $data] = $send('DR-2026-NTG01', 'SMS fine, email refused', $both);
$check('SMS accepted and email refused are each reported', $status === 200 && $data['channels'] === ['sms' => 'sent', 'email' => 'failed']
    && str_contains($data['feedback'], 'accepted by IPROG') && str_contains($data['feedback'], 'NOT sent. Gmail refused'), $data);
$case('accepted');
[$status] = $send('DR-2026-NTG01', 'SMS fine, email refused', ['send_sms' => '1']);
$check('the accepted SMS cannot be repeated after that', $status === 409 && $hits() === $s + 1, $status);
[$status] = $send('DR-2026-NTG01', 'SMS fine, email refused', ['send_email' => '1']);
$check('the refused email alone can be retried', $status === 200 && $mails() === $m + 1, $status);

$idF = $newRequest('DR-2026-NTF01', 'Fay', 'Failure', '+639170000505', 'fay.request@example.invalid', null);
$pdo->exec("CREATE TRIGGER block_email BEFORE INSERT ON notifications FOR EACH ROW BEGIN IF NEW.channel = 'email' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test'; END IF; END");
$s = $hits(); $m = $mails();
[$status, , $data] = $send('DR-2026-NTF01', 'Email cannot be recorded', $both);
$check('if the email cannot be recorded, the SMS result still reaches staff', $status === 200
    && $data['channels'] === ['sms' => 'sent', 'email' => 'failed'] && $hits() === $s + 1 && $mails() === $m
    && str_contains($data['feedback'], 'could not be recorded, so it was not sent'), [$status, $data]);
$check('that email left no record', array_column($rows('DR-2026-NTF01'), 'channel') === ['sms'], $rows('DR-2026-NTF01'));
$s = $hits(); $m = $mails();
[$status] = $send('DR-2026-NTF01', 'Email first cannot be recorded', ['send_email' => '1']);
$check('if the only channel cannot be recorded, nothing is sent', $status === 500 && $hits() === $s && $mails() === $m, $status);
$pdo->exec('DROP TRIGGER block_email');

$idU = $newRequest('DR-2026-NTU01', 'Uma', 'Unsaved', '+639170000606', 'uma.request@example.invalid', null);
$pdo->exec("CREATE TRIGGER block_update BEFORE UPDATE ON notifications FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test'; END");
[$status, , $data] = $send('DR-2026-NTU01', 'Outcome cannot be saved', ['send_sms' => '1']);
$pdo->exec('DROP TRIGGER block_update');
$logStatus = $pdo->query("SELECT delivery_status FROM request_status_log WHERE request_id = {$idU}")->fetchColumn();
$check('an unsaved outcome leaves history and the duplicate check in agreement', $status === 200 && $data['channels'] === ['sms' => 'pending']
    && $logStatus === 'pending' && $rows('DR-2026-NTU01')[0]['delivery_status'] === 'pending', [$status, $data, $logStatus]);
$s = $hits();
[$status, $code] = $send('DR-2026-NTU01', 'Different wording', ['send_sms' => '1']);
$check('and that SMS then blocks a further send', $status === 409 && $code === 'sms_unconfirmed' && $hits() === $s, [$status, $code]);

// ---- 5: history ----
$pdo->prepare("INSERT INTO request_status_log (request_id, status, actor, sms_body) VALUES (:id, 'Processing', 'staff', 'Status changed')")->execute(['id' => $idA]);
$history = sa_notification_history($pdo, true);
$forRef = static fn (string $reference): array => array_values(array_filter($history, static fn (array $row): bool => $row['reference_no'] === $reference));
$historyA = $forRef('DR-2026-NTA01');
$check('history uses the name on the request, not the account holder', $historyA !== []
    && array_unique(array_column($historyA, 'client_name')) === ['Alma Request'], array_column($historyA, 'client_name'));
$check('a guest request has a name in history', array_unique(array_column($forRef('DR-2026-NTG01'), 'client_name')) === ['Greta Guest']);
$bySentChannel = [];
foreach ($historyA as $row) {
    if ($row['delivery_status'] === 'sent') {
        $bySentChannel[$row['actor']] = $row['destination'];
    }
}
$check('history shows where each message went, masked', $bySentChannel === ['email' => 'a•••@example.invalid', 'staff' => '…0101'], $bySentChannel);
$statusRows = array_values(array_filter($historyA, static fn (array $row): bool => $row['delivery_status'] === null));
$check('a status entry shows no destination', count($statusRows) === 1 && $statusRows[0]['destination'] === '', $statusRows);
$encoded = json_encode($history, JSON_UNESCAPED_UNICODE);
$check('history holds no full number or address', !preg_match('/\d{10}/', $encoded) && !str_contains($encoded, '.request@')
    && !array_key_exists('recipient_phone', $history[0]) && !array_key_exists('recipient_email', $history[0]));
$failed = array_values(array_filter($forRef('LT-2026-NTB01'), static fn (array $row): bool => $row['delivery_status'] === 'failed'));
$check('a refused message is recorded as failed, against the right client', count($failed) === 1 && $failed[0]['actor'] === 'staff'
    && $failed[0]['client_name'] === 'Berto Request' && $failed[0]['sent_by_name'] === 'Staff One', $failed);
$check('a mask copes with missing details', sa_mask_phone('') === 'no number on file' && sa_mask_email('') === 'no email on file');

// ---- 6: the recipient staff see before sending ----
$ownerA = sa_lookup_request_owner(['reference' => 'DR-2026-NTA01']);
$ownerB = sa_lookup_request_owner(['reference' => 'LT-2026-NTB01']);
$check('the recipient shown is the one that request sends to', $ownerA['contactNumber'] === $phoneA && $ownerA['email'] === $mailA
    && $ownerA['recipientName'] === 'Alma Request' && $ownerB['contactNumber'] === $phoneB && $ownerB['email'] === $mailB, [$ownerA, $ownerB]);
$ownerN = sa_lookup_request_owner(['reference' => 'DR-2026-NTN01']);
$check('a request with no email shows none', $ownerN['email'] === null, $ownerN);

// ---- Roles and logs ----
$GLOBALS['SA_TEST_USER'] = null;
$s = $hits(); $m = $mails();
[$status] = $send('DR-2026-NTA01', 'Signed out', $both);
$check('signed out cannot send', $status === 401 && $hits() === $s && $mails() === $m, $status);
foreach (['sa_send_notification', 'sa_lookup_request_owner'] as $function) {
    $check($function . ' stays limited to Staff', str_contains($extractFrom($api, $function), "sa_internal_or_fail(['STAFF'])"));
}
$bootstrap = (string) file_get_contents($app . '/web/bootstrap.php');
$check('only Staff receive destinations in history', str_contains($bootstrap, 'sa_notification_history($pdo, $roleId === 2)')
    && array_unique(array_column(sa_notification_history($pdo, false), 'destination')) === ['']);
$logged = is_file($errorLog) ? (string) file_get_contents($errorLog) : '';
$check('logs hold no token, number, address or message text', !str_contains($logged, 'TEST-TOKEN-ONLY') && !preg_match('/9170000\d{3}/', $logged)
    && !str_contains($logged, '@example.invalid') && !str_contains($logged, 'Update for Alma'), $logged);

$failures = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failures += $passed ? 0 : 1;
}
echo (count($results) - $failures) . ' / ' . count($results) . " passed\n";
exit($failures === 0 ? 0 : 1);
