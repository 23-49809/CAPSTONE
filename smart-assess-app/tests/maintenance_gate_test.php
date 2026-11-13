<?php
declare(strict_types=1);

/*
 * Deployment maintenance gate (web/bootstrap.php sa_maintenance_active, web/api.php):
 *  - while the flag file exists next to the served pages, every POST action that
 *    changes data answers 503 with a JSON "maintenance" message and Retry-After,
 *    and nothing is saved (no rows, no uploaded files);
 *  - the read-only actions, signing out, GET requests and pages keep working;
 *  - CSRF and sign-in checks still apply; the gate never lets anything through;
 *  - concurrent requests behave the same;
 *  - fail closed: a stale (over 15 minutes) or future-dated flag still blocks, and is
 *    logged for recovery; only removing the flag ends the gate;
 *  - the browser keeps retrying the autosave and shows the message.
 *
 * Runs the real web/ code over HTTP against a copy in a temporary folder; the
 * SMS and Gmail senders are stubs that refuse. All data is made up. Uses ONLY a
 * database whose name ends in "_test".
 *
 *   SMART_ASSESS_TEST_DB=smart_gate_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=... SMART_ASSESS_DB_PASS='...' php tests/maintenance_gate_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dbName = (string) getenv('SMART_ASSESS_TEST_DB');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $dbName) || !extension_loaded('curl') || !extension_loaded('gd')) {
    fwrite(STDERR, "Refusing to run: set SMART_ASSESS_TEST_DB to a database whose name ends in _test (curl and GD required).\n");
    exit(2);
}
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = (string) getenv('SMART_ASSESS_DB_PASS');
$project = dirname(__DIR__, 2);
// The Terms version the release under test accepts, read from its own source (no hard-coded date).
preg_match("/^const SA_TERMS_VERSION = '([0-9]{4}-[0-9]{2}-[0-9]{2})';/m", (string) file_get_contents("$project/smart-assess-app/web/terms-acceptance.php"), $tv);
$termsVersion = $tv[1] ?? '';
if ($termsVersion === '') {
    fwrite(STDERR, "Could not read SA_TERMS_VERSION from web/terms-acceptance.php.\n");
    exit(2);
}
$message = 'SMART ASSESS is being updated. Please try again in a minute. Nothing you entered has been lost.';
$allowed = ['draft_load', 'draft_file', 'lookup_request_owner', 'notification_status', 'generate_ai_message', 'client_logout', 'internal_logout'];

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 400)];
};

// ================= Source checks =================
$apiSource = (string) file_get_contents("$project/smart-assess-app/web/api.php");
preg_match('/\$result = match \(\$action\) \{(.*?)default => throw/s', $apiSource, $m);
preg_match_all("/^\s+'([a-z_]+)' =>/m", $m[1] ?? '', $mm);
$postActions = array_values(array_unique(array_merge($mm[1] ?? [], ['draft_file'])));
$check('the API has the expected number of POST actions (37 in the dispatcher + draft_file = 38)', count($postActions) === 38, $postActions);
$check('every action the gate allows exists in the API', array_diff($allowed, $postActions) === [], array_diff($allowed, $postActions));
$gateAt = strpos($apiSource, 'if (sa_maintenance_active()');
$csrfAt = strpos($apiSource, "sa_verify_csrf(isset(\$data['csrf'])");
$check('the gate runs after the CSRF check and before any action', $gateAt !== false && $csrfAt !== false && $csrfAt < $gateAt
    && $gateAt < strpos($apiSource, "if (\$action === 'draft_file')"));
preg_match("/if \(sa_maintenance_active\(\) && !in_array\(\\\$action, \[(.*?)\], true\)\)/s", $apiSource, $am);
preg_match_all("/'([a-z_]+)'/", $am[1] ?? '', $amm);
$check('the allowed list in api.php is exactly the reviewed list', ($amm[1] ?? []) === $allowed, $amm[1] ?? []);
$page = (string) file_get_contents("$project/smart-assess.html");
$check('browser: draft requests keep the server\'s error code', str_contains($page, "err.code = json.code || '';"));
$check('browser: during maintenance the autosave keeps retrying instead of going local-only',
    str_contains($page, "if(err.status===503 && err.code==='maintenance'){ setDraftStatus('paused'); draftRetryLater(); return; }")
    && strpos($page, "err.code==='maintenance'") < strpos($page, "if(err.status===503){ setDraftStatus('local'); return; }"));
$check('browser: the autosave status explains the pause', str_contains($page, "if(draft.status==='paused') return 'SMART ASSESS is being updated."));
$bridge = (string) file_get_contents("$project/smart-assess-app/web/assets/php-bridge.js");
$check('bridge: a refused submission keeps the server\'s code, closes the confirmation popup and uses the calm tone for maintenance',
    str_contains($bridge, "failure.code = result.code || '';") && str_contains($bridge, "document.querySelector('[data-action=\"cancel-submit\"]')")
    && str_contains($bridge, "error && error.code === 'maintenance' ? 'pending' : undefined"));
$check('public page styles the bridge\'s messages (they were unstyled before)', (bool) preg_match('/^\s*\.login-error\{[^}]*background:var\(--danger-bg\)[^}]*padding:12px 16px/m', $page));

// ================= Copy of the application and a test database =================
$work = sys_get_temp_dir() . '/sa-gate-' . bin2hex(random_bytes(4));
foreach (['smart-assess-app/logs', 'smart-assess-app/private_uploads/requests', 'smart-assess-app/private_uploads/drafts', 'empty-gmail'] as $d) {
    mkdir("$work/$d", 0700, true);
}
$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        @mkdir($to, 0700, true);
        foreach (scandir($from) ?: [] as $e) {
            if ($e !== '.' && $e !== '..' && $e !== 'uploads') {
                $copy("$from/$e", "$to/$e");
            }
        }
        return;
    }
    copy($from, $to);
};
foreach (['web', 'app'] as $part) {
    $copy("$project/smart-assess-app/$part", "$work/smart-assess-app/$part");
}
copy("$project/smart-assess.html", "$work/smart-assess.html");
copy("$project/smart-assess-internal.html", "$work/smart-assess-internal.html");
file_put_contents("$work/smart-assess-app/gmail-mailer.php", "<?php\nreturn static function (array \$d): void { throw new RuntimeException('[mailer] disabled'); };\n");
file_put_contents("$work/smart-assess-app/iprog-sms.php", "<?php\nfinal class IprogSmsException extends RuntimeException { public const REJECTED = 'rejected'; public function outcome(): string { return self::REJECTED; } }\n"
    . "final class IprogSmsService { public static function fromEnvironment(): self { throw new RuntimeException('disabled'); } }\n");
$flag = "$work/.smart-assess-maintenance";   // dirname(web, 2), as on the server

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int) $dbPort, $dbName), $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['ai_verification_jobs', 'terms_acceptances', 'request_draft_files', 'request_drafts', 'request_draft_rate', 'roles', 'users', 'clients', 'requests',
    'request_documents', 'document_validation', 'request_status_log', 'audit_log', 'announcements', 'settings', 'notifications', 'document_types',
    'transfer_types', 'user_sessions'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS {$t}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach ([
    "CREATE TABLE roles (id INT PRIMARY KEY, name VARCHAR(40) NOT NULL)",
    "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, role_id INT NOT NULL, name VARCHAR(150) NOT NULL, username VARCHAR(80) NOT NULL UNIQUE,
        email VARCHAR(150) NULL, contact_number VARCHAR(20) NULL, position_title VARCHAR(80) NULL, password_hash VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active', archived_at DATETIME NULL, deleted_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE clients (id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(150) NOT NULL,
        contact_number VARCHAR(20) NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, reference_no VARCHAR(20) NOT NULL, flow VARCHAR(20) NOT NULL,
        first_name VARCHAR(80) NOT NULL, middle_name VARCHAR(80) NULL, last_name VARCHAR(80) NOT NULL, contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL,
        address_line VARCHAR(255) NOT NULL DEFAULT '', province VARCHAR(80) NULL, city VARCHAR(80) NULL, zip_code VARCHAR(10) NOT NULL DEFAULT '',
        document_type VARCHAR(120) NULL, transfer_type VARCHAR(120) NULL, purpose VARCHAR(200) NULL, arp_number VARCHAR(60) NOT NULL DEFAULT '',
        property_address VARCHAR(255) NULL, barangay VARCHAR(100) NOT NULL DEFAULT '', is_owner TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'Received', requirement_complete TINYINT(1) NOT NULL DEFAULT 0, advisory TEXT NULL,
        processing_started_at DATETIME NULL, processing_completed_at DATETIME NULL, processing_duration_seconds INT NULL,
        processing_staff_id INT NULL, processing_staff_name VARCHAR(150) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE request_documents (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, doc_key VARCHAR(40) NOT NULL, label VARCHAR(150) NOT NULL,
        original_name VARCHAR(255) NULL, stored_path VARCHAR(255) NULL, mime_type VARCHAR(80) NULL, file_size INT NULL, file_status VARCHAR(20) NOT NULL DEFAULT 'missing')",
    "CREATE TABLE document_validation (id INT AUTO_INCREMENT PRIMARY KEY, request_document_id INT NOT NULL, validation_status VARCHAR(20) NULL, remarks TEXT NULL, validated_at DATETIME NULL)",
    "CREATE TABLE request_status_log (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, status VARCHAR(30) NOT NULL, actor VARCHAR(10) NOT NULL DEFAULT 'staff',
        sms_body TEXT NOT NULL, sms_category VARCHAR(60) NULL, delivery_status VARCHAR(20) NULL, sms_sent_by_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE audit_log (id INT AUTO_INCREMENT PRIMARY KEY, actor_type VARCHAR(10) NOT NULL, actor_id INT NULL, actor_name VARCHAR(150) NOT NULL,
        action VARCHAR(120) NOT NULL, target VARCHAR(150) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE announcements (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, author VARCHAR(150) NOT NULL DEFAULT '',
        audience VARCHAR(20) NOT NULL DEFAULT 'both', start_date DATE NULL, end_date DATE NULL, image_url VARCHAR(255) NULL, status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        scheduled_at DATETIME NULL, published_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL)",
    "CREATE TABLE document_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1)",
    "CREATE TABLE transfer_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1)",
    "CREATE TABLE user_sessions (id INT AUTO_INCREMENT PRIMARY KEY, user_type VARCHAR(10) NOT NULL, user_id INT NOT NULL, ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL, login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_at DATETIME NULL)",
    "CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, request_id INT NULL, type VARCHAR(40) NOT NULL, message TEXT NOT NULL,
        channel ENUM('system','sms','email') NOT NULL DEFAULT 'system', delivery_status ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
        read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
] as $sql) {
    $pdo->exec($sql);
}
foreach (['20261015_terms_acceptances.sql', '20261015_request_drafts.sql'] as $migration) {
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents("$project/smart-assess-app/database/migrations/$migration"));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $st) {
        $pdo->exec($st);
    }
}
$password = 'Gate-Test-' . bin2hex(random_bytes(4));
$pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'CLIENT'), (2, 'STAFF'), (3, 'ADMIN'), (4, 'DEPARTMENT_HEAD')");
$pdo->prepare('INSERT INTO users (role_id, name, username, email, password_hash) VALUES (2, ?, ?, ?, ?)')
    ->execute(['Gate Staff', 'gate.staff', 'gate.staff@office.invalid', password_hash($password, PASSWORD_DEFAULT)]);
$pdo->exec("INSERT INTO requests (reference_no, flow, first_name, last_name, contact_number, document_type, status)
    VALUES ('DR-2026-GATE1', 'docreq', 'Gate', 'Applicant', '+639170004444', 'Certified True Copy of Tax Declaration', 'Received')");

// ================= Server =================
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$work/smart-assess-app/web"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$work/server-errors.log", 'w']], $pipes, "$work/smart-assess-app/web",
    ['PATH' => '/usr/bin:/bin', 'DB_HOST' => $dbHost, 'DB_PORT' => $dbPort, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'SMART_ASSESS_DB_PASS' => $dbPass,
        'SMART_ASSESS_GMAIL_DIR' => "$work/empty-gmail", 'PHP_CLI_SERVER_WORKERS' => '4']);
$jars = [];
register_shutdown_function(static function () use ($server, $work, &$jars): void {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($work));
    foreach ($jars as $j) {
        @unlink($j);
    }
});
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}

/** One browser with its own cookies and CSRF token. */
$browser = static function () use ($port, &$jars): object {
    $jar = (string) tempnam(sys_get_temp_dir(), 'sa-gate');
    $jars[] = $jar;
    return new class ($port, $jar) {
        public string $csrf = '';
        public function __construct(public readonly int $port, public readonly string $jar)
        {
        }
        public function handle(string $path, mixed $post = null, array $headers = []): CurlHandle
        {
            $c = curl_init("http://127.0.0.1:{$this->port}$path");
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
                CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']), CURLOPT_TIMEOUT => 60]);
            if ($post !== null) {
                curl_setopt($c, CURLOPT_POST, true);
                curl_setopt($c, CURLOPT_POSTFIELDS, $post);
            }
            return $c;
        }
        /** @return array{0:int,1:string,2:string} status, body, headers */
        public function parse(CurlHandle $c, string $raw): array
        {
            $size = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
            $body = substr($raw, $size);
            if (preg_match('/SMART_ASSESS_CONFIG=(\{.*?\});/', $body, $m)) {
                $this->csrf = (string) (json_decode($m[1], true)['csrf'] ?? $this->csrf);
            }
            return [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), $body, substr($raw, 0, $size)];
        }
        public function http(string $path, mixed $post = null, array $headers = []): array
        {
            $c = $this->handle($path, $post, $headers);
            return $this->parse($c, (string) curl_exec($c));
        }
        public function api(string $action, array $data = [], ?string $csrf = null): array
        {
            return $this->http('/api.php?action=' . $action, (string) json_encode(['action' => $action, 'csrf' => $csrf ?? $this->csrf] + $data),
                ['Content-Type: application/json']);
        }
    };
};
$isMaintenance = static fn (array $r): bool => $r[0] === 503 && (json_decode($r[1], true)['code'] ?? '') === 'maintenance';
$counts = static function () use ($pdo): array {
    $out = [];
    foreach (['terms_acceptances', 'requests', 'request_documents', 'request_drafts', 'request_draft_files', 'notifications', 'users', 'announcements', 'request_status_log'] as $t) {
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    return $out;
};
$files = static fn (): array => array_values(array_diff(array_merge(scandir("$work/smart-assess-app/private_uploads/requests") ?: [],
    scandir("$work/smart-assess-app/private_uploads/drafts") ?: []), ['.', '..']));

// ================= Before the gate: normal behaviour, and sessions prepared =================
$applicant = $browser();
$applicant->http('/index.php');
[$status] = $applicant->api('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $termsVersion]);
$check('no flag: changes are accepted (Terms acceptance 200)', $status === 200, $status);
[, $body] = $applicant->api('draft_save', ['flow' => 'docreq', 'step' => 2, 'fields' => ['firstName' => 'Gate']]);
$draftCode = str_replace('-', '', (string) (json_decode($body, true)['data']['code'] ?? ''));
$staff = $browser();
$staff->http('/internal.php');
[$status] = $staff->api('internal_login', ['username' => 'gate.staff', 'password' => $password]);
$staff->http('/internal.php');
$check('setup: a saved application and a signed-in Staff session', strlen($draftCode) === 20 && $status === 200, [$draftCode, $status]);

// ================= Gate on =================
touch($flag);
$before = $counts();
$blocked = [];
$wrongly = [];
foreach ($postActions as $action) {
    if (in_array($action, ['internal_logout', 'client_logout'], true)) {
        continue;   // tried at the end, so the Staff session stays signed in
    }
    $r = $staff->api($action, ['draft_token' => $draftCode, 'reference' => 'DR-2026-GATE1', 'requestRef' => 'DR-2026-GATE1', 'id' => 'DR-2026-GATE1',
        'flow' => 'docreq', 'agreed' => true, 'terms_version' => $termsVersion, 'message' => 'gate test', 'status' => 'Processing',
        'transition' => 'ann-publish-now', 'publishMode' => 'draft']);
    if (in_array($action, $allowed, true)) {
        if ($isMaintenance($r)) {
            $wrongly[] = $action;
        }
    } elseif ($isMaintenance($r)) {
        $blocked[] = $action;
        if (!str_contains($r[2], 'Retry-After: 60') || (json_decode($r[1], true)['error'] ?? '') !== $message || (json_decode($r[1], true)['ok'] ?? null) !== false) {
            $wrongly[] = "$action (response)";
        }
    } else {
        $wrongly[] = "$action (not blocked: HTTP {$r[0]})";
    }
}
$expectedBlocked = array_values(array_diff($postActions, $allowed));
$check(sprintf('every one of the %d data-changing POST actions answers 503 "maintenance" with Retry-After: 60', count($expectedBlocked)),
    count($blocked) === count($expectedBlocked) && $wrongly === [], ['blocked' => count($blocked), 'problems' => $wrongly]);
$check('the read-only actions still run (none answered "maintenance")', array_intersect($wrongly, $allowed) === []);
$aliasProblems = [];
foreach (['create_account', 'edit_account', 'update_profile', 'save_announcement', 'archive_account', 'restore_account', 'delete_account',
    'toggle_account', 'delete_announcement', 'announcement_transition'] as $alias) {
    $r = $staff->api($alias, ['id' => 1, 'transition' => 'ann-publish-now', 'publishMode' => 'draft']);
    if (!$isMaintenance($r)) {
        $aliasProblems[] = "$alias: HTTP {$r[0]}";
    }
}
$check('the bridge\'s short action names (create_account, announcement_transition, ...) are blocked too', $aliasProblems === [], $aliasProblems);
$r = $staff->api('lookup_request_owner', ['reference' => 'DR-2026-GATE1']);
$check('Staff can still look up a request owner (read-only) during maintenance', $r[0] === 200 && (json_decode($r[1], true)['ok'] ?? false) === true, [$r[0], substr($r[1], 0, 160)]);
$r = $applicant->api('draft_load', ['draft_token' => $draftCode]);
$check('an applicant can still open a saved application during maintenance', $r[0] === 200, [$r[0], substr($r[1], 0, 160)]);

$png = "$work/id.png";
$img = imagecreatetruecolor(20, 20);
imagepng($img, $png);
$r = $applicant->http('/api.php?action=submit_request', ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $applicant->csrf, 'flow' => 'docreq',
    'first_name' => 'Gate', 'last_name' => 'Upload', 'contact_number' => '+639170005555', 'address_line' => '1 St', 'zip_code' => '4202',
    'arp_number' => '14-0007-05555', 'barangay' => 'Anilao', 'document_type' => 'Certified True Copy of Tax Declaration']),
    'file_ownerId' => new CURLFile($png, 'image/png', 'id.png')]);
$check('a submission with an uploaded file is refused and no file is stored', $isMaintenance($r) && $files() === [], [$r[0], $files()]);
$r = $applicant->http('/api.php?action=draft_upload', ['payload' => json_encode(['action' => 'draft_upload', 'csrf' => $applicant->csrf,
    'draft_token' => $draftCode, 'doc_key' => 'ownerId']), 'file' => new CURLFile($png, 'image/png', 'id.png')]);
$check('a saved-application upload is refused and no file is stored', $isMaintenance($r) && $files() === [], [$r[0], $files()]);
$check('nothing was written to the database while the gate was on', $counts() === $before, [$before, $counts()]);

$r = $staff->api('update_request_status', ['id' => 'DR-2026-GATE1', 'status' => 'Processing'], 'not-the-token');
$check('CSRF still applies first: a wrong token gets 403, not 503', $r[0] === 403, $r[0]);
$r = $staff->api('lookup_request_owner', ['reference' => 'DR-2026-GATE1'], 'not-the-token');
$check('CSRF still applies to allowed actions: 403', $r[0] === 403, $r[0]);
$anonymous = $browser();
$anonymous->http('/index.php');
$r = $anonymous->api('lookup_request_owner', ['reference' => 'DR-2026-GATE1']);
$check('sign-in still applies to allowed actions: anonymous lookup gets 401', $r[0] === 401, $r[0]);
$r = $anonymous->api('internal_login', ['username' => 'gate.staff', 'password' => $password]);
$check('signing in is paused during maintenance (503, no session created)', $isMaintenance($r), $r[0]);

$gets = [];
foreach (['/index.php', '/internal', '/internal.php', '/terms.php', '/privacy-policy.php', '/api.php?action=public_bootstrap',
    '/api.php?action=track&ref=DR-2026-GATE1'] as $path) {
    $gets[$path] = $anonymous->http($path)[0];
}
$check('pages and GET requests keep working (200)', count(array_filter($gets, static fn (int $s): bool => $s === 200 || $s === 302)) === count($gets)
    && $gets['/index.php'] === 200 && $gets['/api.php?action=track&ref=DR-2026-GATE1'] === 200, $gets);
$r = $staff->http('/api.php?action=internal_bootstrap');
$check('Staff can still load their pages and data (internal_bootstrap 200)', $r[0] === 200, $r[0]);

// Concurrent requests while the gate is on.
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 12; $i++) {
    $b = $i % 2 === 0 ? $applicant : $staff;
    $h = $b->handle('/api.php?action=terms_accept', (string) json_encode(['action' => 'terms_accept', 'csrf' => $b->csrf, 'flow' => 'docreq', 'agreed' => true,
        'terms_version' => $termsVersion]), ['Content-Type: application/json']);
    $handles[] = [$b, $h, 'post'];
    curl_multi_add_handle($multi, $h);
    $g = $anonymous->handle('/index.php');
    $handles[] = [$anonymous, $g, 'get'];
    curl_multi_add_handle($multi, $g);
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 1.0);
} while ($running > 0);
$posts = $getsOk = 0;
foreach ($handles as [$b, $h, $kind]) {
    $r = $b->parse($h, (string) curl_multi_getcontent($h));
    if ($kind === 'post' && $isMaintenance($r)) {
        $posts++;
    }
    if ($kind === 'get' && $r[0] === 200) {
        $getsOk++;
    }
}
$check('12 concurrent changes are all refused and 12 concurrent page views all succeed', $posts === 12 && $getsOk === 12 && $counts() === $before,
    [$posts, $getsOk]);

$r = $staff->api('internal_logout');
$check('signing out still works during maintenance', $r[0] === 200, $r[0]);
$r = $anonymous->api('client_logout');
$check('client sign-out is not blocked by the gate (its own sign-in check answers instead)', !$isMaintenance($r) && $r[0] === 401, $r[0]);

// ================= Fail closed: stale, future-dated and removed flags =================
$fresh = $browser();
$fresh->http('/index.php');
$accept = static fn () => $fresh->api('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $termsVersion]);
touch($flag, time() - 901);
$check('a flag just over 15 minutes old still blocks (fail closed)', $isMaintenance($accept()));
touch($flag, time() - 7 * 86400);
$check('a flag a week old (e.g. after kill -9 or power loss) still blocks', $isMaintenance($accept()));
touch($flag, time() + 3600);
$check('a flag dated in the future still blocks', $isMaintenance($accept()));
file_put_contents($flag, '');
touch($flag, time() - 3600);
$check('an empty flag file still blocks', $isMaintenance($accept()));
$check('nothing was written while the old flag blocked', $counts() === $before || (int) $pdo->query('SELECT COUNT(*) FROM terms_acceptances')->fetchColumn() === $before['terms_acceptances'],
    $counts());
$serverLog = (string) @file_get_contents("$work/server-errors.log");
$check('a stale flag is logged with the recovery instruction', str_contains($serverLog, 'a deployment may have been interrupted')
    && str_contains($serverLog, 'smart-assess-maintenance.sh status'), substr($serverLog, 0, 300));
unlink($flag);
[$status] = $accept();
$check('only removing the flag ends the gate: changes are accepted again', $status === 200, $status);
$log = (string) @file_get_contents("$work/smart-assess-app/logs/api-errors.log");
$check('maintenance refusals are not written to the API error log (no log flooding)', !str_contains($log, '"status":503'), substr($log, 0, 200));

$failed = 0;
foreach ($results as [$ok, $name, $detail]) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $ok ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
