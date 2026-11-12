<?php
declare(strict_types=1);

/*
 * Applicant contact privacy: only Staff (role 2) may receive applicant phone
 * numbers, email addresses, home addresses, client account names and uploaded
 * file names. Admin (3) and Department Head (4) keep their dashboards, reports
 * and aggregate figures, but no response made for them contains those details.
 *
 * Runs the real web/ code over HTTP (PHP's built-in server) against a copy in a
 * temporary folder, so nothing is written into the project. The copy's SMS and
 * Gmail senders are replaced with stubs that refuse, so no message can be sent.
 * All people and contact details below are made up.
 * Uses ONLY a dedicated test database (name must end in "_test"); it drops and
 * recreates the tables it needs there.
 *
 *   SMART_ASSESS_TEST_DB=smart_privacy_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=root SMART_ASSESS_DB_PASS='...' php tests/applicant_contact_privacy_test.php
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
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = (string) getenv('SMART_ASSESS_DB_PASS');

$project = dirname(__DIR__, 2);
$work = sys_get_temp_dir() . '/sa-contact-privacy-' . bin2hex(random_bytes(4));
mkdir($work . '/smart-assess-app/logs', 0700, true);
mkdir($work . '/smart-assess-app/private_uploads/requests', 0700, true);
mkdir($work . '/empty-gmail', 0700, true);

$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        @mkdir($to, 0700, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'uploads') {
                $copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
        return;
    }
    copy($from, $to);
};
$copy($project . '/smart-assess-app/web', $work . '/smart-assess-app/web');
$copy($project . '/smart-assess-app/app', $work . '/smart-assess-app/app');
copy($project . '/smart-assess-internal.html', $work . '/smart-assess-internal.html');
copy($project . '/smart-assess.html', $work . '/smart-assess.html');
// Senders that can never send: these replace the real ones in the copy only.
file_put_contents($work . '/smart-assess-app/gmail-mailer.php',
    "<?php\nreturn static function (array \$d): void { throw new RuntimeException('[mailer] Test stub: email is disabled.'); };\n");
file_put_contents($work . '/smart-assess-app/iprog-sms.php',
    "<?php\nfinal class IprogSmsException extends RuntimeException { public const REJECTED = 'rejected'; public function outcome(): string { return self::REJECTED; } }\n"
    . "final class IprogSmsService { public static function fromEnvironment(): self { throw new RuntimeException('Test stub: SMS is disabled.'); } }\n");

// ---- Test database ----
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int) $dbPort, $dbName), $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$tables = ['roles', 'users', 'clients', 'requests', 'request_documents', 'document_validation', 'request_status_log',
    'audit_log', 'announcements', 'settings', 'user_sessions', 'notifications', 'document_types', 'transfer_types'];
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$schema = [
    "CREATE TABLE roles (id INT PRIMARY KEY, name VARCHAR(40) NOT NULL)",
    "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, role_id INT NOT NULL, name VARCHAR(150) NOT NULL, username VARCHAR(80) NOT NULL UNIQUE,
        email VARCHAR(150) NULL, contact_number VARCHAR(20) NULL, position_title VARCHAR(80) NULL, password_hash VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active', archived_at DATETIME NULL, deleted_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE clients (id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(150) NOT NULL,
        contact_number VARCHAR(20) NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, reference_no VARCHAR(20) NOT NULL, flow VARCHAR(20) NOT NULL,
        first_name VARCHAR(80) NOT NULL, middle_name VARCHAR(80) NULL, last_name VARCHAR(80) NOT NULL, contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL,
        address_line VARCHAR(200) NOT NULL DEFAULT '', province VARCHAR(80) NULL, city VARCHAR(80) NULL, zip_code VARCHAR(10) NOT NULL DEFAULT '',
        document_type VARCHAR(120) NULL, transfer_type VARCHAR(120) NULL, purpose VARCHAR(200) NOT NULL DEFAULT '', arp_number VARCHAR(60) NOT NULL DEFAULT '',
        property_address VARCHAR(200) NOT NULL DEFAULT '', barangay VARCHAR(80) NOT NULL DEFAULT '', is_owner TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'Received', requirement_complete TINYINT(1) NOT NULL DEFAULT 0, advisory TEXT NULL,
        processing_started_at DATETIME NULL, processing_completed_at DATETIME NULL, processing_duration_seconds INT NULL,
        processing_staff_id INT NULL, processing_staff_name VARCHAR(150) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE request_documents (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, doc_key VARCHAR(40) NOT NULL, label VARCHAR(120) NOT NULL,
        original_name VARCHAR(255) NULL, stored_path VARCHAR(255) NULL, mime_type VARCHAR(80) NULL, file_size INT NULL, file_status VARCHAR(20) NOT NULL DEFAULT 'missing')",
    "CREATE TABLE document_validation (id INT AUTO_INCREMENT PRIMARY KEY, request_document_id INT NOT NULL, validation_status VARCHAR(20) NULL,
        remarks TEXT NULL, validated_at DATETIME NULL)",
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
];
foreach ($schema as $statement) {
    $pdo->exec($statement);
}

// ---- Made-up people and the exact values that must never reach Admin or Head ----
$password = 'Privacy-Test-Only-' . bin2hex(random_bytes(4));
$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'CLIENT'), (2, 'STAFF'), (3, 'ADMIN'), (4, 'DEPARTMENT_HEAD')");
$insertUser = $pdo->prepare('INSERT INTO users (role_id, name, username, email, contact_number, position_title, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insertUser->execute([2, 'Privacy Staff', 'privacy.staff', 'privacy.staff@office.invalid', '+639990000002', 'Staff', $hash]);
$insertUser->execute([3, 'Privacy Admin', 'privacy.admin', 'privacy.admin@office.invalid', '+639990000003', 'Administrator', $hash]);
$insertUser->execute([4, 'Privacy Head', 'privacy.head', 'privacy.head@office.invalid', '+639990000004', 'Department Head', $hash]);
$staffId = (int) $pdo->query("SELECT id FROM users WHERE username = 'privacy.staff'")->fetchColumn();
$pdo->prepare('INSERT INTO clients (id, first_name, last_name, email, contact_number, password_hash) VALUES (701, ?, ?, ?, ?, ?)')
    ->execute(['Kalinda', 'Accountholder', 'kalinda.account@example.invalid', '+639175550701', $hash]);
$secrets = [
    'applicant email' => 'marisol.applicant@example.invalid',
    'applicant phone' => '9175550123',
    'second applicant email' => 'tomas.applicant@example.invalid',
    'second applicant phone' => '9175550456',
    'home address' => '77 Privacyfield Street',
    'city' => 'Secretcity',
    'province' => 'Hiddenprovince',
    'zip code' => '4299',
    'client account name' => 'Accountholder',
    'client account email' => 'kalinda.account@example.invalid',
    'uploaded file name' => 'marisol-private-id-scan.png',
    'old audit email' => 'legacy.client@example.invalid',
    'old audit phone' => '9178889999',
];
$insertRequest = $pdo->prepare(
    'INSERT INTO requests (client_id, reference_no, flow, first_name, middle_name, last_name, contact_number, email, address_line, province, city, zip_code,
        document_type, transfer_type, purpose, arp_number, property_address, barangay, status, processing_staff_id, processing_staff_name,
        processing_started_at, processing_completed_at, processing_duration_seconds)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertRequest->execute([701, 'DR-2026-PRIV1', 'docreq', 'Marisol', 'Reyes', 'Applicantone', '+63' . $secrets['applicant phone'], $secrets['applicant email'],
    $secrets['home address'], $secrets['province'], $secrets['city'], $secrets['zip code'], 'Certified True Copy', null, 'Loan', 'ARP-0001', 'Lot 5 Poblacion',
    'Poblacion', 'Approved', $staffId, 'Privacy Staff', '2026-11-01 09:00:00', '2026-11-01 10:00:00', 3600]);
$insertRequest->execute([null, 'LT-2026-PRIV2', 'landtransfer', 'Tomas', null, 'Applicanttwo', '+63' . $secrets['second applicant phone'], $secrets['second applicant email'],
    '12 Other Lane', 'Batangas', 'Mabini', '4202', null, 'Sale', 'Transfer', 'ARP-0002', 'Lot 9 Anilao', 'Anilao', 'Received', null, null, null, null, null]);
$requestOne = (int) $pdo->query("SELECT id FROM requests WHERE reference_no = 'DR-2026-PRIV1'")->fetchColumn();
$pdo->prepare("INSERT INTO request_documents (request_id, doc_key, label, original_name, stored_path, mime_type, file_size, file_status) VALUES (?, 'ownerId', 'Valid ID', ?, NULL, 'image/png', 1000, 'missing')")
    ->execute([$requestOne, $secrets['uploaded file name']]);
$documentId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO request_status_log (request_id, status, actor, sms_body, delivery_status, sms_sent_by_id) VALUES (?, 'Approved', 'staff', 'Your request is ready.', 'sent', ?)")
    ->execute([$requestOne, $staffId]);
// An older client login entry: the client's name as actor, email as target, phone in the action.
$pdo->prepare("INSERT INTO audit_log (actor_type, actor_id, actor_name, action, target) VALUES ('client', 701, 'Kalinda Accountholder', ?, ?)")
    ->execute(['Client login from 0' . $secrets['old audit phone'], $secrets['old audit email']]);
$pdo->exec("INSERT INTO announcements (title, body, author, audience, status, published_at, start_date) VALUES ('Office hours', 'Open 8 to 5.', 'Privacy Head', 'both', 'Published', NOW(), CURDATE())");

// ---- The copy, served locally ----
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$environment = [
    'PATH' => '/usr/bin:/bin', 'DB_HOST' => $dbHost, 'DB_PORT' => $dbPort, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser,
    'SMART_ASSESS_DB_PASS' => $dbPass, 'SMART_ASSESS_GMAIL_DIR' => $work . '/empty-gmail',
];
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $work . '/smart-assess-app/web'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work . '/smart-assess-app/web', $environment);
register_shutdown_function(static function () use ($server, $work): void {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($work));
});
$base = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 50 && @file_get_contents($base . '/internal.php') === false; $i++) {
    usleep(100000);
}

/** A browser-like session: keeps its cookie and CSRF token. */
final class Session
{
    private string $cookie = '';
    public string $csrf = '';

    public function __construct(private readonly string $base)
    {
    }

    /** @return array{0:int,1:string} */
    public function request(string $method, string $path, ?array $json = null): array
    {
        $headers = ['Accept: application/json, text/html'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        $options = ['method' => $method, 'ignore_errors' => true, 'header' => '', 'timeout' => 20];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $options['content'] = json_encode($json + ['csrf' => $this->csrf]);
        }
        $options['header'] = implode("\r\n", $headers);
        $body = (string) @file_get_contents($this->base . $path, false, stream_context_create(['http' => $options]));
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
            if (preg_match('/^Set-Cookie:\s*([^=]+=[^;]+)/i', $line, $m)) {
                $this->cookie = $m[1];
            }
        }
        if (preg_match('/SMART_ASSESS_CONFIG=(\{.*?\});/', $body, $m)) {
            $this->csrf = (string) (json_decode($m[1], true)['csrf'] ?? $this->csrf);
        }
        return [$status, $body];
    }

    public function login(string $username, string $password): int
    {
        $this->request('GET', '/internal.php');
        [$status] = $this->request('POST', '/api.php?action=internal_login', ['action' => 'internal_login', 'username' => $username, 'password' => $password]);
        $this->request('GET', '/internal.php');
        return $status;
    }

    public function api(string $action, array $data = []): array
    {
        return $this->request('POST', '/api.php?action=' . $action, ['action' => $action] + $data);
    }
}

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};
/** Names of the secrets present in $text, in any common phone format. */
$leaks = static function (string $text, array $names) use ($secrets): array {
    $decoded = html_entity_decode(stripcslashes($text));
    $digitsOnly = (string) preg_replace('/\D+/', '', $decoded);
    $found = [];
    foreach ($names as $name) {
        $value = $secrets[$name];
        $isPhone = str_contains($name, 'phone');
        if (stripos($decoded, $value) !== false || ($isPhone && str_contains($digitsOnly, $value))) {
            $found[] = $name;
        }
    }
    return $found;
};
$restricted = array_keys($secrets);
$pageData = static function (string $html): array {
    return preg_match('/window\.SMART_ASSESS_PAGE=(\{.*?\});?<\/script>/s', $html, $m) ? (array) json_decode($m[1], true) : [];
};

// ================= Staff: still has what the work needs =================
$staff = new Session($base);
$check('Staff can sign in', $staff->login('privacy.staff', $password) === 200);
[$status, $html] = $staff->request('GET', '/internal.php?view=document-requests');
$page = $pageData($html);
$staffRecord = $page['docRequests'][0] ?? [];
$check('Staff page includes the applicant\'s phone, email and home address', $status === 200
    && ($staffRecord['details']['contact'] ?? '') === '+63' . $secrets['applicant phone']
    && ($staffRecord['details']['email'] ?? '') === $secrets['applicant email']
    && ($staffRecord['details']['addressLine'] ?? '') === $secrets['home address'], $staffRecord['details'] ?? $status);
$check('Staff page includes uploaded file details', ($staffRecord['files'][0]['name'] ?? '') === $secrets['uploaded file name']);
$check('Staff page includes notification history', count($page['notifs'] ?? []) === 1);
[$status, $body] = $staff->request('GET', '/api.php?action=internal_bootstrap');
$check('Staff direct bootstrap request includes contact details', $status === 200
    && $leaks($body, ['applicant email', 'applicant phone', 'home address']) === ['applicant email', 'applicant phone', 'home address']);
[$status, $body] = $staff->api('lookup_request_owner', ['reference' => 'DR-2026-PRIV1']);
$lookup = json_decode($body, true)['data'] ?? [];
$check('Staff recipient lookup returns the applicant\'s phone and email', $status === 200
    && ($lookup['contactNumber'] ?? '') === '+63' . $secrets['applicant phone'] && ($lookup['email'] ?? '') === $secrets['applicant email'], [$status, $body]);
[$status, $body] = $staff->api('send_notification', ['requestRef' => 'DR-2026-PRIV1', 'message' => 'Privacy test', 'send_sms' => '1', 'channel_choice' => '1']);
$check('Staff notification sending is still allowed past the role check (stopped here only because SMS is off in the test)',
    $status === 503 && str_contains($body, 'SMS sending is currently turned off'), [$status, $body]);
[$status] = $staff->request('GET', '/request-file.php?doc=' . $documentId . '&ref=DR-2026-PRIV1');
$check('Staff are not refused an uploaded file by role (the test file has no stored copy)', $status !== 403, $status);

// ================= Admin and Department Head: no contact details anywhere =================
foreach (['Admin' => 'privacy.admin', 'Department Head' => 'privacy.head'] as $role => $username) {
    $session = new Session($base);
    $check("{$role} can sign in", $session->login($username, $password) === 200);
    $responses = [];
    foreach (['dashboard', 'reports', 'document-requests', 'notifications', 'staff-accounts'] as $view) {
        $responses["page {$view}"] = $session->request('GET', '/internal.php?view=' . $view);
    }
    $responses['direct bootstrap'] = $session->request('GET', '/api.php?action=internal_bootstrap');
    $responses['bootstrap with forged role'] = $session->request('GET', '/api.php?action=internal_bootstrap&role_id=2&role=STAFF');
    $responses['monthly report'] = $session->request('GET', '/api.php?action=head_monthly_report');
    $responses['recipient lookup'] = $session->api('lookup_request_owner', ['reference' => 'DR-2026-PRIV1']);
    $responses['recipient lookup with forged role'] = $session->api('lookup_request_owner', ['reference' => 'DR-2026-PRIV1', 'role_id' => 2, 'role' => 'STAFF']);
    $responses['send notification'] = $session->api('send_notification', ['requestRef' => 'DR-2026-PRIV1', 'message' => 'Not allowed', 'send_sms' => '1', 'channel_choice' => '1']);
    $responses['notification service status'] = $session->api('notification_status');
    $responses['status update'] = $session->api('update_request_status', ['id' => 'DR-2026-PRIV1', 'status' => 'Rejected']);
    $responses['uploaded file'] = $session->request('GET', '/request-file.php?doc=' . $documentId . '&ref=DR-2026-PRIV1');
    foreach ($responses as $label => [$status, $body]) {
        $found = $leaks($body, $restricted);
        $check("{$role} {$label}: no applicant contact details", $found === [], [$status, $found]);
    }
    foreach (['recipient lookup', 'recipient lookup with forged role', 'send notification', 'notification service status', 'status update', 'uploaded file'] as $label) {
        $check("{$role} {$label}: refused", $responses[$label][0] === 403, $responses[$label][0]);
    }
    $page = $pageData($responses['page dashboard'][1]);
    $records = array_merge($page['docRequests'] ?? [], $page['landRequests'] ?? []);
    $check("{$role} page has request records without details, files or raw rows", count($records) === 2
        && array_filter($records, static fn (array $r): bool => isset($r['details']) || isset($r['files'])) === []
        && !array_key_exists('requests', $page) && !array_key_exists('documents', $page) && !array_key_exists('notifications', $page), array_keys($page));
    $check("{$role} page keeps what dashboards and reports use: applicant name, status, type, barangay",
        ($records[0]['applicant'] ?? '') !== '' && in_array('Approved', array_column($records, 'status'), true)
        && in_array('Poblacion', array_column($records, 'barangay'), true) && in_array('Certified True Copy', array_column($records, 'docType'), true));
    $stats = $page['adminProcessingStats'] ?? [];
    $check("{$role} aggregate processing figures are unchanged", ($stats['documents']['total'] ?? null) === 1 && ($stats['documents']['completed'] ?? null) === 1
        && ($stats['landTransfers']['total'] ?? null) === 1 && ($stats['documents']['averageDurationSeconds'] ?? null) === 3600, $stats);
    $reportIds = array_column($page['reports'] ?? [], 'id');
    $check("{$role} report summaries are present", $reportIds === ['RPT-DOC', 'RPT-LAND', 'RPT-REQ', 'RPT-SUM']
        && ($page['reports'][3]['stats']['barangays'] ?? null) === 2 && ($page['reports'][2]['stats']['checked'] ?? null) === 1, $page['reports'] ?? null);
    $check("{$role} has no notification history", ($page['notifs'] ?? null) === []);
    $bootstrap = json_decode($responses['direct bootstrap'][1], true)['data'] ?? [];
    $columns = array_keys($bootstrap['requests'][0] ?? []);
    $check("{$role} direct bootstrap rows have no contact columns at all", $columns !== []
        && array_intersect($columns, ['email', 'contact_number', 'address_line', 'city', 'province', 'zip_code', 'client_first_name', 'client_last_name']) === []
        && !array_key_exists('original_name', $bootstrap['documents'][0] ?? []) && ($bootstrap['notifications'] ?? null) === [], $columns);
    if ($role === 'Department Head') {
        [$status, $body] = $responses['monthly report'];
        $report = json_decode($body, true)['data'] ?? [];
        $check('Department Head monthly report still works', $status === 200 && isset($report['cards'], $report['staffPerformance'], $report['documentSummary']), [$status, substr($body, 0, 200)]);
        $check('Department Head announcements still load', count($page['announcements'] ?? []) >= 1);
        $headReports = $bootstrap['reports'] ?? [];
        $check('Department Head direct report figures still work, without contact columns', ($headReports['total_requests'] ?? null) === 2
            && array_intersect(array_keys($headReports['recent_requests'][0] ?? []), ['email', 'contact_number', 'address_line']) === []);
    } else {
        $check('Admin monthly report stays Head-only', $responses['monthly report'][0] === 403);
        $check('Admin still sees staff accounts', count($page['accounts'] ?? []) === 3);
        $auditEntries = $bootstrap['audit_logs'] ?? [];
        $oldEntry = array_values(array_filter($auditEntries, static fn (array $e): bool => $e['actor_type'] === 'client'))[0] ?? [];
        $check('Admin audit log still loads, with contact details in old entries hidden', count($auditEntries) >= 1
            && ($oldEntry['target'] ?? '') === '[email hidden]' && str_contains((string) ($oldEntry['action'] ?? ''), '[phone hidden]'), $oldEntry);
        $check('Admin direct bootstrap shows a client audit entry as "Client account #701", not the client\'s name',
            ($oldEntry['actor_name'] ?? '') === 'Client account #701' && (int) ($oldEntry['actor_id'] ?? 0) === 701, $oldEntry);
    }
}

// ================= Signed out =================
$anonymous = new Session($base);
$anonymous->request('GET', '/internal.php');
[$status, $body] = $anonymous->request('GET', '/api.php?action=internal_bootstrap');
$check('signed out: direct bootstrap refused with nothing leaked', $status === 401 && $leaks($body, $restricted) === [], $status);
[$status, $body] = $anonymous->api('lookup_request_owner', ['reference' => 'DR-2026-PRIV1']);
$check('signed out: recipient lookup refused with nothing leaked', $status === 401 && $leaks($body, $restricted) === [], $status);
[$status, $body] = $anonymous->request('GET', '/api.php?action=track&ref=DR-2026-PRIV1');
$check('public tracking returns no contact details', $leaks($body, $restricted) === [], [$status, $body]);

// ================= Logs and audit =================
$client = new Session($base);
$client->request('GET', '/index.php');
[$status] = $client->api('client_login', ['email' => $secrets['client account email'], 'password' => $password]);
$target = (string) $pdo->query("SELECT target FROM audit_log WHERE action = 'Client login' ORDER BY id DESC LIMIT 1")->fetchColumn();
$check('a client login is audited by account number, not email', $status === 200 && $target === 'Client account #701', [$status, $target]);
$apiSource = (string) file_get_contents($project . '/smart-assess-app/web/api.php');
$check('database error text is never written to the API error log', str_contains($apiSource,
    "'error' => \$exception instanceof PDOException ? 'Database error' : \$exception->getMessage(),"));
$log = (string) @file_get_contents($work . '/smart-assess-app/logs/api-errors.log');
$check('the API error log holds no applicant contact details', $log !== '' && $leaks($log, $restricted) === [], $leaks($log, $restricted));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
