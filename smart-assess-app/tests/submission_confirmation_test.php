<?php
declare(strict_types=1);

/*
 * Automatic submission confirmations and SMS delivery tracking.
 *
 * Covers:
 *  - sa_confirmation_queue() (web/api.php): what is queued, for which channels, with which text;
 *  - SubmissionConfirmation::run() (app/services): each confirmation sent exactly once, outcomes,
 *    IPROG message ID and status stored, history row updated, catch-up of older queued rows;
 *  - the IPROG route setting SMART_ASSESS_SMS_PROVIDER (sms_provider sent only when valid);
 *  - sa_sms_deliver() returns IPROG's message ID and status for staff messages;
 *  - source checks: queued inside the submission transaction, sender started after commit.
 *
 * Never sends a real SMS or email and uses no credits: IPROG is a fake server on 127.0.0.1
 * (a copy of iprog-sms.php points at it, with a fake token) and email goes to a fake mailer.
 *
 *   SMART_ASSESS_TEST_DB=<name>_test DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=... \
 *   SMART_ASSESS_DB_PASS='...' php tests/submission_confirmation_test.php
 */

$dbName = (string) getenv('SMART_ASSESS_TEST_DB');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $dbName)) {
    fwrite(STDERR, "Refusing to run: set SMART_ASSESS_TEST_DB to a dedicated database whose name ends in _test.\n");
    exit(2);
}
$app = dirname(__DIR__);
$work = sys_get_temp_dir() . '/sa-confirm-test-' . bin2hex(random_bytes(4));
mkdir($work . '/gmail', 0700, true);
mkdir($work . '/iprog', 0700, true);
ini_set('log_errors', '1');
ini_set('error_log', $work . '/php-errors.log');

putenv('HOME');
unset($_SERVER['HOME'], $_ENV['HOME']);
file_put_contents($work . '/gmail/gmail-token.json', '{}');
file_put_contents($work . '/gmail/google-oauth-client.json', '{}');
putenv('SMART_ASSESS_GMAIL_DIR=' . $work . '/gmail');
putenv('IPROG_API_TOKEN=TEST-TOKEN-ONLY');
putenv('SMART_ASSESS_SMS_ENABLED=1');
putenv('SMART_ASSESS_SMS_PROVIDER');
putenv('SMART_ASSESS_SUBMISSION_CONFIRMATION');
putenv('DB_NAME=' . $dbName);
foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USER' => 'root'] as $key => $default) {
    putenv($key . '=' . (getenv($key) ?: $default));
}
if (getenv('SMART_ASSESS_DB_PASS') === false) {
    putenv('SMART_ASSESS_DB_PASS=');
}

// ---- Fake IPROG: answers according to case.txt and records every send (fields only, never shown) ----
file_put_contents($work . '/iprog/case.txt', 'accepted');
file_put_contents($work . '/iprog/stub.php', <<<'STUB'
<?php
$case = trim((string) @file_get_contents(__DIR__ . '/case.txt'));
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo isset($_GET['message_id'])
        ? json_encode(['status' => 200, 'message_status' => $case === 'accepted_then_failed' ? 'failed' : 'completed'])
        : '{"status":"success","message":"Account found.","data":{"load_balance":42}}';
    return;
}
file_put_contents(__DIR__ . '/hits.log', json_encode([
    'phone' => $_POST['phone_number'] ?? '', 'message' => $_POST['message'] ?? '',
    'has_route' => array_key_exists('sms_provider', $_POST), 'route' => $_POST['sms_provider'] ?? null,
]) . "\n", FILE_APPEND);
[$http, $body] = match ($case) {
    'http_500' => [500, 'server error'],
    'invalid_token' => [200, '{"status":500,"message":"Invalid Token"}'],
    default => [200, '{"status":200,"message":"SMS successfully queued for delivery.","message_id":"iSms-CONF1"}'],
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
$source = (string) file_get_contents($app . '/iprog-sms.php');
foreach (['sms_messages/status', 'account/sms_credits', 'sms_messages'] as $path) {
    $replaced = 0;
    $source = str_replace('https://www.iprogsms.com/api/v1/' . $path, 'http://127.0.0.1:' . $port . '/stub.php', $source, $replaced);
    if ($replaced !== 1) {
        fwrite(STDERR, "Could not redirect the IPROG copy to the fake server; refusing to continue.\n");
        exit(2);
    }
}
$iprogCopy = $work . '/iprog-sms-test-copy.php';
file_put_contents($iprogCopy, $source);

// ---- Fake mailer: records which fields it got; behaves according to gmail/case.txt ----
file_put_contents($work . '/gmail/case.txt', 'accepted');
$fakeMailer = $work . '/fake-mailer.php';
file_put_contents($fakeMailer, '<?php return static function (array $d): void {'
    . ' if (!empty($d["check_only"])) return;'
    . ' $case = trim((string) @file_get_contents(' . var_export($work . '/gmail/case.txt', true) . '));'
    . ' file_put_contents(' . var_export($work . '/mail.log', true) . ', json_encode(["keys" => array_keys($d), "reference" => $d["reference_no"] ?? "", "has_email" => filter_var($d["email"] ?? "", FILTER_VALIDATE_EMAIL) !== false]) . "\n", FILE_APPEND);'
    . ' if ($case === "unconfirmed") throw new RuntimeException("[mailer] Gmail did not confirm the message (no reply from Google).");'
    . ' if ($case === "refused") throw new RuntimeException("[mailer] Gmail refused the message (HTTP 400).");'
    . ' };');

require $app . '/web/bootstrap.php';
require $iprogCopy;   // the class under its real name, pointing at the fake server
require $app . '/app/services/SubmissionConfirmation.php';
$pdo = sa_db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['notifications', 'request_status_log', 'requests'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Received', reference_no VARCHAR(20) NOT NULL, flow VARCHAR(20) NOT NULL DEFAULT 'docreq',
    contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL, first_name VARCHAR(80) NOT NULL DEFAULT 'Juana', last_name VARCHAR(80) NOT NULL DEFAULT 'Test') ENGINE=InnoDB");
$pdo->exec("CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, request_id INT NULL, type VARCHAR(40) NOT NULL, message TEXT NOT NULL,
    channel ENUM('system','sms','email') NOT NULL DEFAULT 'system', delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent', read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE request_status_log (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, status VARCHAR(30) NOT NULL, actor VARCHAR(10) NOT NULL, sms_body TEXT NOT NULL,
    sms_category VARCHAR(60) NULL, delivery_status VARCHAR(20) NULL, sms_sent_by_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};

// ---- The real migration, run twice (it must be safe to repeat) ----
$migration = (string) file_get_contents($app . '/database/migrations/20261113_notification_delivery_tracking.sql');
$runMigration = static function () use ($pdo, $migration): void {
    foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('/^--.*$/m', '', $migration)))) as $statement) {
        $result = $pdo->query($statement);   // EXECUTE of the 'SELECT 1' branch returns a result set
        if ($result !== false) {
            $result->fetchAll();
            $result->closeCursor();
        }
    }
};
$check('before the migration, the tracking columns do not exist (nothing would be queued)', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME IN ('provider_message_id','provider_status','status_log_id','send_state')")->fetchColumn() === 0);
$runMigration();
$runMigration();
$cols = $pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_COLUMN);
$check('the migration adds the four columns and can be run twice', array_diff(['provider_message_id', 'provider_status', 'status_log_id', 'send_state'], $cols) === [], $cols);
$indexes = array_unique($pdo->query('SHOW INDEX FROM notifications')->fetchAll(PDO::FETCH_COLUMN, 2));
$check('the migration adds the send-state and history-link indexes', in_array('idx_notifications_send_state', $indexes, true) && in_array('idx_notifications_status_log', $indexes, true), $indexes);

// ---- Load the real functions from web/api.php (paths pointed at the test copies) ----
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
preg_match("/^const SA_CONFIRMATION_SMS = .*?;$/m", $api, $smsConst);
preg_match("/^const SA_CONFIRMATION_CATEGORY = .*?;$/m", $api, $catConst);
$code = ($smsConst[0] ?? '') . "\n" . ($catConst[0] ?? '') . "\n";
foreach (['sa_sms_enabled', 'sa_sms_load_service', 'sa_sms_deliver', 'sa_sms_failure_reason', 'sa_sms_provider_detail', 'sa_sms_unconfirmed_reason',
          'sa_email_config_dir', 'sa_email_configured', 'sa_confirmation_queue'] as $function) {
    $code .= $extractFrom($api, $function) . "\n";
}
$code = str_replace("dirname(__DIR__) . '/iprog-sms.php'", var_export($iprogCopy, true), $code);
$code = str_replace("dirname(__DIR__) . '/gmail-mailer.php'", var_export($fakeMailer, true), $code);
file_put_contents($work . '/functions.php', "<?php\n" . $code);
require $work . '/functions.php';
$check('tracking is available after the migration', sa_notification_tracking_available());

// ---- Helpers ----
$case = static function (string $iprog, string $mail = 'accepted') use ($work): void {
    file_put_contents($work . '/iprog/case.txt', $iprog);
    file_put_contents($work . '/gmail/case.txt', $mail);
};
$hits = static fn (): array => is_file($work . '/iprog/hits.log') ? array_map(static fn (string $l): array => json_decode($l, true), file($work . '/iprog/hits.log', FILE_IGNORE_NEW_LINES)) : [];
$mails = static fn (): array => is_file($work . '/mail.log') ? array_map(static fn (string $l): array => json_decode($l, true), file($work . '/mail.log', FILE_IGNORE_NEW_LINES)) : [];
$submit = static function (string $reference, string $phone, ?string $email) use ($pdo): int {
    // What sa_submit_request() does: insert the request and queue its confirmations in one transaction.
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO requests (reference_no, contact_number, email) VALUES (?, ?, ?)')->execute([$reference, $phone, $email]);
    $id = (int) $pdo->lastInsertId();
    $queued = sa_confirmation_queue($pdo, $id, null, $reference, $phone, $email);
    $pdo->commit();
    return $id * 100 + $queued;
};
$run = static fn (?int $requestId): array => SubmissionConfirmation::run($pdo, $requestId, $iprogCopy, $fakeMailer, 0);
$rows = static function (int $requestId) use ($pdo): array {
    $stmt = $pdo->prepare("SELECT n.channel, n.delivery_status, n.send_state, n.provider_message_id, n.provider_status, n.message, l.actor, l.delivery_status AS log_status, l.sms_category
        FROM notifications n LEFT JOIN request_status_log l ON l.id = n.status_log_id WHERE n.request_id = ? AND n.type = 'submission_confirmation' ORDER BY CAST(n.channel AS CHAR)");   // email, then sms
    $stmt->execute([$requestId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

// ---- 1. Queueing ----
$r = $submit('DR-2026-CONF0001', '09171234567', 'client.one@example.invalid');
$id1 = intdiv($r, 100);
$check('a submission with a valid number and email queues two confirmations', $r % 100 === 2, $r);
$q = $rows($id1);
$check('both are queued (send_state queued, outcome pending), linked to history rows', count($q) === 2
    && $q[0]['send_state'] === 'queued' && $q[1]['send_state'] === 'queued' && $q[0]['delivery_status'] === 'pending' && $q[0]['log_status'] === 'pending', $q);
$check('history rows: actors auto_email / auto_sms, category "Submission Confirmation"', $q[0]['actor'] === 'auto_email' && $q[1]['actor'] === 'auto_sms'
    && $q[0]['sms_category'] === 'Submission Confirmation' && $q[1]['sms_category'] === 'Submission Confirmation', $q);
$smsText = (string) $q[1]['message'];
$check('the SMS text names the reference, has no web link, and fits one SMS part (<= 160 basic characters)', str_contains($smsText, 'DR-2026-CONF0001')
    && !preg_match('~https?://|www\.~i', $smsText) && strlen($smsText) <= 160 && !preg_match('/[^\x20-\x7E]/', $smsText), [strlen($smsText)]);
$check('nothing was sent while queueing', $hits() === [] && $mails() === []);
$r = $submit('DR-2026-CONF0002', '09171234568', null);
$check('no email address: only the SMS confirmation is queued', $r % 100 === 1 && $rows(intdiv($r, 100))[0]['channel'] === 'sms');
$r = $submit('DR-2026-CONF0003', '0212345678', 'client.three@example.invalid');
$check('a number that is not a Philippine mobile: only the email confirmation is queued', $r % 100 === 1 && $rows(intdiv($r, 100))[0]['channel'] === 'email');
putenv('SMART_ASSESS_SMS_ENABLED=0');
$r = $submit('DR-2026-CONF0004', '09171234569', null);
putenv('SMART_ASSESS_SMS_ENABLED=1');
$check('SMS turned off and no email: nothing is queued', $r % 100 === 0);
putenv('SMART_ASSESS_SUBMISSION_CONFIRMATION=0');
$r = $submit('DR-2026-CONF0005', '09171234570', 'client.five@example.invalid');
putenv('SMART_ASSESS_SUBMISSION_CONFIRMATION');
$check('SMART_ASSESS_SUBMISSION_CONFIRMATION=0 switches confirmations off', $r % 100 === 0);

// ---- 2. Sending: exactly once, outcomes and IPROG details stored ----
$case('accepted');
$s = $run($id1);
$q = $rows($id1);
$check('the sender sends both confirmations of the request (accepted)', $s['sent'] === 2 && $q[0]['delivery_status'] === 'sent' && $q[1]['delivery_status'] === 'sent', [$s, $q]);
$check('send_state is cleared and the history rows show the outcome', $q[0]['send_state'] === null && $q[1]['send_state'] === null && $q[0]['log_status'] === 'sent' && $q[1]['log_status'] === 'sent', $q);
$check('the SMS keeps IPROG\'s message ID and its reported status', $q[1]['provider_message_id'] === 'iSms-CONF1' && $q[1]['provider_status'] === 'completed', $q[1]);
$check('the email has no IPROG details', $q[0]['provider_message_id'] === null, $q[0]);
$h = $hits();
$check('exactly one SMS went to IPROG, with the queued text and the request\'s number', count($h) === 1 && $h[0]['phone'] === '639171234567' && $h[0]['message'] === $smsText, $h);
$m = $mails();
$check('exactly one email, the mailer\'s own "we received your request" form (no staff message text)', count($m) === 1 && !in_array('message', $m[0]['keys'], true)
    && in_array('flow', $m[0]['keys'], true) && $m[0]['reference'] === 'DR-2026-CONF0001' && $m[0]['has_email'], $m);
$check('without SMART_ASSESS_SMS_PROVIDER, no sms_provider is sent (IPROG default route)', $h[0]['has_route'] === false, $h[0]);
$run($id1);
$run(null);
$check('running the sender again sends nothing more for that request', count($hits()) === 1 && count($mails()) === 1);

// Other requests queued less than two minutes ago are left to their own run.
$other = intdiv($submit('DR-2026-CONF0006', '09171234571', null), 100);
$run($id1);
$check('a run for one request does not send the recent confirmations of other requests', $rows($other)[0]['send_state'] === 'queued' && count($hits()) === 1);

// ---- 3. Route setting ----
$pdo->exec("TRUNCATE notifications");
@unlink($work . '/iprog/hits.log');
putenv('SMART_ASSESS_SMS_PROVIDER=1');
$id = intdiv($submit('DR-2026-ROUTE001', '09171234572', null), 100);
$run($id);
$h = $hits();
$check('SMART_ASSESS_SMS_PROVIDER=1 sends sms_provider=1 with the SMS', count($h) === 1 && $h[0]['has_route'] === true && $h[0]['route'] === '1', $h);
putenv('SMART_ASSESS_SMS_PROVIDER=7');
$id = intdiv($submit('DR-2026-ROUTE002', '09171234573', null), 100);
$run($id);
$h = $hits();
$check('an invalid route (7) is ignored: no sms_provider is sent', count($h) === 2 && $h[1]['has_route'] === false, $h);
putenv('SMART_ASSESS_SMS_PROVIDER=2');
$check('the service reports the configured route', IprogSmsService::fromEnvironment()->route() === 2);
putenv('SMART_ASSESS_SMS_PROVIDER');
$check('unset: the service reports no route', IprogSmsService::fromEnvironment()->route() === null);

// ---- 4. Failures are recorded, never retried ----
$outcomeFor = static function (string $iprog, string $mail, ?string $email) use ($submit, $run, $rows): array {
    file_put_contents($GLOBALS['work'] . '/iprog/case.txt', $iprog);
    file_put_contents($GLOBALS['work'] . '/gmail/case.txt', $mail);
    $id = intdiv($submit('DR-2026-F' . substr(bin2hex(random_bytes(4)), 0, 7), '09171234574', $email), 100);
    $run($id);
    $out = [];
    foreach ($rows($id) as $row) {
        $out[$row['channel']] = $row['delivery_status'] . '/' . ($row['send_state'] ?? 'done');
    }
    return $out;
};
$GLOBALS['work'] = $work;
$check('IPROG refuses (invalid token): SMS recorded as failed', $outcomeFor('invalid_token', 'accepted', null) === ['sms' => 'failed/done']);
$check('IPROG server error: SMS recorded as unconfirmed (pending), not failed', $outcomeFor('http_500', 'accepted', null) === ['sms' => 'pending/done']);
$check('accepted, then IPROG reports failed: unconfirmed (pending), never "failed"', $outcomeFor('accepted_then_failed', 'accepted', null) === ['sms' => 'pending/done']);
$check('Gmail does not confirm: email recorded as unconfirmed (pending)', ($outcomeFor('accepted', 'unconfirmed', 'f1@example.invalid')['email'] ?? '') === 'pending/done');
$check('Gmail refuses: email recorded as failed', ($outcomeFor('accepted', 'refused', 'f2@example.invalid')['email'] ?? '') === 'failed/done');
$before = count($hits());
$run(null);
$check('failed and unconfirmed confirmations are never sent again by later runs', count($hits()) === $before);

// ---- 5. Claiming: a confirmation another run holds is never sent twice ----
$case('accepted');
$id = intdiv($submit('DR-2026-CLAIM001', '09171234575', null), 100);
$pdo->prepare("UPDATE notifications SET send_state = 'sending' WHERE request_id = ?")->execute([$id]);
$before = count($hits());
$run($id);
$check('a confirmation already claimed ("sending") is not sent again', count($hits()) === $before && $rows($id)[0]['send_state'] === 'sending');

// ---- 6. Catch-up of older queued confirmations ----
$id = intdiv($submit('DR-2026-LATE0001', '09171234576', null), 100);
$pdo->prepare("UPDATE notifications SET created_at = NOW() - INTERVAL 5 MINUTE WHERE request_id = ?")->execute([$id]);
$run(null);
$check('a confirmation queued over two minutes ago is sent by any later run (sender that could not start)', $rows($id)[0]['delivery_status'] === 'sent');

// ---- 7. Staff SMS: IPROG details come back from sa_sms_deliver ----
$case('accepted');
$provider = null;
$reason = null;
$outcome = sa_sms_deliver('09171234577', 'Staff test message', 'test', $reason, $provider);
$check('sa_sms_deliver returns IPROG\'s message ID and status for staff messages', $outcome === 'sent' && ($provider['message_id'] ?? null) === 'iSms-CONF1'
    && ($provider['status'] ?? null) === 'completed', [$outcome, $provider]);
$case('invalid_token');
$outcome = sa_sms_deliver('09171234577', 'Staff test message', 'test', $reason, $provider);
$check('a refused staff SMS returns no IPROG message ID', $outcome === 'failed' && ($provider['message_id'] ?? null) === null, [$outcome, $provider]);

// ---- 8. Source checks: wiring that a fake database cannot show ----
$submitFn = $extractFrom($api, 'sa_submit_request');
$queueAt = strpos($submitFn, 'sa_confirmation_queue(');
$commitAt = strrpos($submitFn, '$pdo->commit();');
$kickAt = strpos($submitFn, 'sa_confirmation_kick(');
$check('sa_submit_request queues confirmations inside its transaction (before commit), behind a savepoint', $queueAt !== false && $commitAt !== false && $queueAt < $commitAt
    && str_contains($submitFn, "SAVEPOINT sa_confirmation") && str_contains($submitFn, "ROLLBACK TO SAVEPOINT sa_confirmation"));
$check('the sender is started only after commit, and only when something was queued', $kickAt !== false && $kickAt > $commitAt && str_contains($submitFn, 'if ($confirmations > 0)'));
$kick = $extractFrom($api, 'sa_confirmation_kick');
$check('the sender runs in the background (returns at once) with its own log file, and never throws', str_contains($kick, " &'") && str_contains($kick, 'proc_open')
    && str_contains($kick, '/logs/confirmations.log') && str_contains($kick, 'catch (Throwable)'));
$cli = $app . '/bin/send-submission-confirmations.php';
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' --unknown 2>&1', $cliOut, $cliStatus);
$check('the sender script refuses unknown arguments (exit 2) before touching anything', $cliStatus === 2);
$check('the sender script is command-line only', str_contains((string) file_get_contents($cli), "PHP_SAPI !== 'cli'"));
$internal = (string) file_get_contents($app . '/web/internal.php');
$check('Staff history: automatic confirmations are labelled and auto_email counts as email', str_contains($internal, "'Automatic confirmation'") && str_contains($internal, "['email', 'auto_email']"));
$privacy = (string) file_get_contents($app . '/web/privacy-policy.php');
$terms = (string) file_get_contents($app . '/web/terms.php');
$page = (string) file_get_contents(dirname($app) . '/smart-assess.html');
$check('the Privacy Policy describes the automatic confirmation', str_contains($privacy, 'automatically sends you one confirmation with your reference number'));
$check('the Terms and the acceptance popup describe it too', str_contains($terms, 'you receive one confirmation with your reference number') && str_contains($page, 'you receive one confirmation with your reference number'));
$check('no page says any more that submitting sends no SMS or email', !str_contains($privacy, 'Submitting a request or changing its status does not send you an SMS or email'));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
printf("\n%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
