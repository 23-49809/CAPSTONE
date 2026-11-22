<?php
declare(strict_types=1);

/*
 * Staff assignment, end to end, on a throwaway database (fictional people and files only).
 *
 *  - The Staff member whose "Process Request" succeeds becomes the request's handler, by the
 *    signed-in account's ID (a Staff ID sent by the browser is ignored), stored in requests.
 *  - Viewing a request, its files or its contact details never assigns it.
 *  - Repeating Process Request or saving another status never adds a second assignment or a
 *    second history record; the handler stays through to the final status.
 *  - A second Staff member cannot take or change a request someone else holds, including when
 *    both click at the same moment (the row lock lets exactly one win).
 *  - Admin and Head still receive no applicant details, files or document results.
 *  - Document Review's inline previews (&preview=1) are not recorded as opening an original;
 *    "Mark as reviewed" still needs Staff to open each original themselves.
 *
 * Run (never against a real database; the name must end in _test):
 *   SMART_ASSESS_TEST_DB=sa_assignment_test DB_HOST=127.0.0.1 DB_PORT=3318 \
 *   DB_USER=... SMART_ASSESS_DB_PASS='...' php tests/staff_assignment_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dbName = (string) getenv('SMART_ASSESS_TEST_DB');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $dbName) || !extension_loaded('gd') || !extension_loaded('curl')) {
    fwrite(STDERR, "Refusing to run: set SMART_ASSESS_TEST_DB to a database whose name ends in _test (GD and curl are also required).\n");
    exit(2);
}
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = (string) getenv('SMART_ASSESS_DB_PASS');
$project = dirname(__DIR__, 2);
$termsVersion = '2026-11-13';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 500)];
};

// ================= A copy of the application and a test database =================
$work = sys_get_temp_dir() . '/sa-staff-assign-' . bin2hex(random_bytes(4));
foreach (['smart-assess-app/logs', 'smart-assess-app/private_uploads/requests', 'smart-assess-app/private_uploads/drafts', 'empty-gmail'] as $directory) {
    mkdir($work . '/' . $directory, 0700, true);
}
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
foreach (['web', 'app'] as $part) {
    $copy($project . '/smart-assess-app/' . $part, $work . '/smart-assess-app/' . $part);
}
copy($project . '/smart-assess.html', $work . '/smart-assess.html');
copy($project . '/smart-assess-internal.html', $work . '/smart-assess-internal.html');
// SMS and email would fail loudly if anything tried to send.
$sendLog = $work . '/send-attempts.log';
file_put_contents($work . '/smart-assess-app/gmail-mailer.php', "<?php\nfile_put_contents(" . var_export($sendLog, true) . ", \"email\\n\", FILE_APPEND);\nreturn static function (array \$d): void { throw new RuntimeException('[mailer] disabled'); };\n");
file_put_contents($work . '/smart-assess-app/iprog-sms.php', "<?php\nfinal class IprogSmsService { public static function fromEnvironment(): self { file_put_contents(" . var_export($sendLog, true) . ", \"sms\\n\", FILE_APPEND); throw new RuntimeException('disabled'); } }\n");

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int) $dbPort, $dbName), $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table, $type]) {
    $pdo->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . "`{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach ([
    "CREATE TABLE roles (id TINYINT PRIMARY KEY, name VARCHAR(30) NOT NULL UNIQUE) ENGINE=InnoDB",
    "INSERT INTO roles (id, name) VALUES (1,'CLIENT'), (2,'STAFF'), (3,'ADMIN'), (4,'DEPARTMENT_HEAD')",
    "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, role_id TINYINT NOT NULL, name VARCHAR(150) NOT NULL, username VARCHAR(80) NOT NULL UNIQUE,
        email VARCHAR(150) NULL, contact_number VARCHAR(20) NULL, position_title VARCHAR(80) NULL, password_hash VARCHAR(255) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', archived_at DATETIME NULL, archived_by INT NULL, restored_at DATETIME NULL,
        restored_by INT NULL, deleted_at DATETIME NULL, deleted_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (role_id) REFERENCES roles(id)) ENGINE=InnoDB",
    "CREATE TABLE user_sessions (id INT AUTO_INCREMENT PRIMARY KEY, user_type ENUM('client','staff','admin','head') NOT NULL, user_id INT NOT NULL,
        ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, actor_name VARCHAR(150) NULL, action VARCHAR(200) NOT NULL,
        request_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE clients (id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(150) NOT NULL,
        contact_number VARCHAR(20) NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active') ENGINE=InnoDB",
    "CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, reference_no VARCHAR(20) NOT NULL UNIQUE, flow VARCHAR(20) NOT NULL,
        first_name VARCHAR(80) NOT NULL, middle_name VARCHAR(80) NULL, last_name VARCHAR(80) NOT NULL, contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL,
        address_line VARCHAR(255) NOT NULL DEFAULT '', province VARCHAR(80) NULL, city VARCHAR(80) NULL, zip_code VARCHAR(10) NOT NULL DEFAULT '',
        document_type VARCHAR(120) NULL, transfer_type VARCHAR(120) NULL, purpose VARCHAR(200) NULL, arp_number VARCHAR(60) NOT NULL DEFAULT '',
        property_address VARCHAR(255) NULL, barangay VARCHAR(100) NOT NULL DEFAULT '', is_owner TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('Received','Processing','Approved','Rejected','Out for Release','Timed Out') NOT NULL DEFAULT 'Received',
        processing_started_at DATETIME NULL, processing_completed_at DATETIME NULL, processing_duration_seconds BIGINT UNSIGNED NULL,
        processing_staff_id BIGINT UNSIGNED NULL, processing_staff_name VARCHAR(150) NULL,
        requirement_complete TINYINT(1) NOT NULL DEFAULT 0, advisory TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE request_documents (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, doc_key VARCHAR(40) NOT NULL, label VARCHAR(150) NOT NULL,
        original_name VARCHAR(255) NULL, stored_path VARCHAR(255) NULL, mime_type VARCHAR(80) NULL, file_size INT NULL, file_status VARCHAR(20) NOT NULL DEFAULT 'missing') ENGINE=InnoDB",
    "CREATE TABLE document_validation (id INT AUTO_INCREMENT PRIMARY KEY, request_document_id INT NOT NULL, validation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        remarks TEXT NULL, validated_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE request_status_log (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, status VARCHAR(30) NOT NULL, actor VARCHAR(10) NOT NULL DEFAULT 'staff',
        sms_body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE audit_log (id INT AUTO_INCREMENT PRIMARY KEY, actor_type VARCHAR(10) NOT NULL, actor_id INT NULL, actor_name VARCHAR(150) NOT NULL,
        action VARCHAR(120) NOT NULL, target VARCHAR(150) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE announcements (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, author VARCHAR(150) NOT NULL DEFAULT '',
        audience VARCHAR(20) NOT NULL DEFAULT 'both', start_date DATE NULL, end_date DATE NULL, image_url VARCHAR(255) NULL, status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        scheduled_at DATETIME NULL, published_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB",
    "CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, request_id INT NULL, type VARCHAR(40) NOT NULL, message TEXT NOT NULL,
        channel VARCHAR(10) NOT NULL DEFAULT 'system', delivery_status VARCHAR(10) NOT NULL DEFAULT 'sent', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE document_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB",
    "CREATE TABLE transfer_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB",
] as $statement) {
    $pdo->exec($statement);
}
foreach (['20261015_terms_acceptances.sql', '20261015_request_drafts.sql', '20261111_ai_document_verification.sql', '20261114_request_document_views.sql'] as $migration) {
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($project . '/smart-assess-app/database/migrations/' . $migration));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}
const PW = 'Test-Only-Pass-456';
$hash = password_hash(PW, PASSWORD_DEFAULT);
$seed = $pdo->prepare('INSERT INTO users (role_id, name, username, password_hash) VALUES (?,?,?,?)');
$seed->execute([2, 'Test Staff', 'test.staff', $hash]);
$seed->execute([2, 'Other Staff', 'other.staff', $hash]);
$seed->execute([3, 'Test Admin', 'test.admin', $hash]);
$seed->execute([4, 'Test Head', 'test.head', $hash]);
$staffId = (int) $pdo->query("SELECT id FROM users WHERE username = 'test.staff'")->fetchColumn();
$otherId = (int) $pdo->query("SELECT id FROM users WHERE username = 'other.staff'")->fetchColumn();

$servers = [];
$jars = [];
register_shutdown_function(static function () use (&$servers, $work, &$jars): void {
    foreach ($servers as $server) {
        proc_terminate($server);
    }
    exec('rm -rf ' . escapeshellarg($work));
    foreach ($jars as $jar) {
        @unlink($jar);
    }
});
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$servers[] = proc_open(['/usr/bin/env', 'PATH=/usr/bin:/bin', 'DB_HOST=' . $dbHost, 'DB_PORT=' . $dbPort, 'DB_NAME=' . $dbName, 'DB_USER=' . $dbUser,
        'SMART_ASSESS_DB_PASS=' . $dbPass, 'SMART_ASSESS_GMAIL_DIR=' . $work . '/empty-gmail', 'SMART_ASSESS_SMS_ENABLED=1',
        // Several workers, so two Staff requests really run at the same time (the race below).
        'PHP_CLI_SERVER_WORKERS=4',
        PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port, '-t', $work . '/smart-assess-app/web'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work . '/smart-assess-app/web');
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}

/** One browser: its own cookie jar and CSRF token. */
$browser = static function (string $start = '/index.php') use ($port, &$jars): object {
    $jar = (string) tempnam(sys_get_temp_dir(), 'sa-cookies');
    $jars[] = $jar;
    $b = new class ($port, $jar) {
        public string $csrf = '';
        public array $headers = [];
        public function __construct(private readonly int $port, private readonly string $jar)
        {
        }
        /** @return array{0:int,1:string} */
        public function http(string $path, mixed $post = null, array $headers = []): array
        {
            $this->headers = [];
            $curl = curl_init('http://127.0.0.1:' . $this->port . $path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60,
                CURLOPT_HEADERFUNCTION => function ($c, string $line): int { $this->headers[] = trim($line); return strlen($line); }]);
            if ($post !== null) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
            }
            $body = (string) curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (preg_match('/SMART_ASSESS_CONFIG=(\{.*?\});/', $body, $m)) {
                $this->csrf = (string) (json_decode($m[1], true)['csrf'] ?? $this->csrf);
            }
            return [$status, $body];
        }
        /** @return array{0:int,1:array} */
        public function json(string $action, array $data): array
        {
            [$status, $body] = $this->http('/api.php?action=' . $action, (string) json_encode(['action' => $action, 'csrf' => $this->csrf] + $data),
                ['Content-Type: application/json', 'Accept: application/json']);
            return [$status, (array) json_decode($body, true)];
        }
        /** The tracking data the page was rendered with. */
        public function track(string $reference): array
        {
            [$status, $body] = $this->http('/index.php?track=' . rawurlencode($reference));
            preg_match('/SMART_ASSESS_PAGE=(\{.*?\});<\/script>/s', $body, $m);
            return [$status, (array) ((json_decode($m[1] ?? '{}', true)['track']['result']) ?? [])];
        }
        public function noStore(): bool
        {
            foreach ($this->headers as $h) {
                if (stripos($h, 'cache-control:') === 0 && stripos($h, 'no-store') !== false) {
                    return true;
                }
            }
            return false;
        }
    };
    $b->http($start);
    return $b;
};

$fixtures = sys_get_temp_dir() . '/sa-staff-assign-files-' . bin2hex(random_bytes(4));
mkdir($fixtures, 0700);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($fixtures)));
$image = imagecreatetruecolor(30, 20);
imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 60));
imagepng($image, $fixtures . '/id.png');

/** A client submits a request through the real draft + submit API; returns [browser, reference]. */
$submit = static function (string $flow, string $first, string $last, string $middle = '') use ($browser, $termsVersion, $fixtures, $check): array {
    $client = $browser();
    [, $accepted] = $client->json('terms_accept', ['flow' => $flow, 'agreed' => true, 'terms_version' => $termsVersion]);
    [, $draft] = $client->json('draft_save', ['flow' => $flow, 'step' => 3, 'fields' => ['firstName' => $first]]);
    $code = str_replace('-', '', (string) ($draft['data']['code'] ?? ''));
    $client->http('/api.php?action=draft_upload', ['payload' => json_encode(['action' => 'draft_upload', 'csrf' => $client->csrf,
        'draft_token' => $code, 'doc_key' => 'ownerId']), 'file' => new CURLFile($fixtures . '/id.png', 'image/png', 'id.png')]);
    $client->http('/api.php?action=draft_upload', ['payload' => json_encode(['action' => 'draft_upload', 'csrf' => $client->csrf,
        'draft_token' => $code, 'doc_key' => 'ownerIdBack']), 'file' => new CURLFile($fixtures . '/id.png', 'image/png', 'id-back.png')]);   // National ID only: front and back
    $fields = ['flow' => $flow, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'contact_number' => '+639170007' . random_int(100, 999),
        'address_line' => '1 Test Street', 'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '4202', 'arp_number' => '14-0007-0' . random_int(1000, 9999),
        'property_address' => 'Anilao', 'barangay' => 'Anilao', 'is_owner' => true]
        + ($flow === 'docreq' ? ['document_type' => 'Certified True Copy of Tax Declaration (CTC-TD)', 'purpose' => 'Personal Copy'] : ['transfer_type' => 'Deed of Absolute Sale']);
    [$status, $body] = $client->http('/api.php?action=submit_request', ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $client->csrf,
        'terms_token' => (string) ($accepted['data']['terms_token'] ?? ''), 'draft_token' => $code] + $fields)]);
    $reference = (string) (json_decode($body, true)['data']['reference_no'] ?? '');
    $check("client submits a {$flow} request", $status === 200 && $reference !== '', [$status, substr($body, 0, 300)]);
    return [$client, $reference];
};


/** Sign a person in on their own browser. */
$signIn = static function (string $username) use ($browser, $check): object {
    $b = $browser('/internal.php');
    [$code] = $b->json('internal_login', ['username' => $username, 'password' => PW]);
    $check("{$username} signs in", $code === 200, $code);
    $b->http('/internal.php');
    return $b;
};
/** The page data internal.php sends this browser. */
$pageData = static function (object $b, string $view = 'document-requests'): array {
    [, $html] = $b->http('/internal.php?view=' . $view);
    return preg_match('/window\.SMART_ASSESS_PAGE=(\{.*?\});?<\/script>/s', $html, $m) ? (array) json_decode($m[1], true) : [];
};
$row = static function (string $reference) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT id, status, processing_staff_id, processing_staff_name, processing_started_at, processing_completed_at FROM requests WHERE reference_no = ?');
    $stmt->execute([$reference]);
    return (array) $stmt->fetch();
};
/** How many history/audit records exist for one request. */
$records = static function (string $reference) use ($pdo): array {
    $id = (int) $pdo->query('SELECT id FROM requests WHERE reference_no = ' . $pdo->quote($reference))->fetchColumn();
    $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
    return [
        'processingLog' => $count("SELECT COUNT(*) FROM request_status_log WHERE request_id = {$id} AND status = 'Processing'"),
        'accepted' => $count("SELECT COUNT(*) FROM activity_logs WHERE request_id = {$id} AND action = 'Accepted request and started processing'"),
        'audit' => $count("SELECT COUNT(*) FROM audit_log WHERE target = " . $pdo->quote($reference) . " AND action = 'Request accepted and processing started'"),
    ];
};

// ================= Requests from clients =================
$refs = [];
foreach ([['Ana', 'Dimaculangan'], ['Ben', 'Macalindong'], ['Carla', 'Panganiban'], ['Dino', 'Ilagan'], ['Ella', 'Magsino'], ['Fe', 'Atienza'], ['Gil', 'Bautista']] as [$first, $last]) {
    [, $refs[]] = $submit('docreq', $first, $last);
}
[$r1, $r2, $r3, $r4, $r5, $r6, $r7] = $refs;

$staffA = $signIn('test.staff');
$staffB = $signIn('other.staff');

// ================= Viewing never assigns =================
$page = $pageData($staffA);
$record = null;
foreach ($page['docRequests'] ?? [] as $candidate) {
    if (($candidate['id'] ?? '') === $r1) {
        $record = $candidate;
    }
}
$check('Staff see the new request as unassigned', $record !== null && $record['processingStaffId'] === null && $record['processingStaffName'] === null, $record);
$docId = (int) ($record['files'][0]['docId'] ?? 0);
[$code] = $staffA->http('/request-file.php?doc=' . $docId . '&ref=' . rawurlencode($r1));
$check('Staff can open the uploaded file through request-file.php', $code === 200, $code);
$staffA->json('lookup_request_owner', ['reference' => $r1]);
$staffA->http('/api.php?action=internal_bootstrap');
$pageData($staffA, 'document-check');
$pageData($staffB);
$check('opening the request, its file, its contact details and the Document Review page does not assign it', $row($r1)['processing_staff_id'] === null
    && $row($r1)['status'] === 'Received' && $row($r1)['processing_started_at'] === null && $records($r1) === ['processingLog' => 0, 'accepted' => 0, 'audit' => 0], [$row($r1), $records($r1)]);

// ================= The first successful Process Request assigns, by the session's account =================
// A browser cannot choose who is assigned: Staff IDs and names in the request are ignored.
[$code, $res] = $staffA->json('start_processing', ['id' => $r1, 'processing_staff_id' => $otherId, 'staff_id' => $otherId, 'user_id' => $otherId,
    'processing_staff_name' => 'Other Staff', 'staff_name' => 'Other Staff']);
$check('Process Request succeeds and reports the signed-in Staff member as the handler', $code === 200 && ($res['data']['status'] ?? '') === 'Processing'
    && (int) ($res['data']['processing_staff_id'] ?? 0) === $staffId && ($res['data']['processing_staff_name'] ?? '') === 'Test Staff', [$code, $res]);
$fresh = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int) $dbPort, $dbName), $dbUser, $dbPass, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$stored = (array) $fresh->query('SELECT processing_staff_id, processing_staff_name, status FROM requests WHERE reference_no = ' . $fresh->quote($r1))->fetch();
$check('the assignment is stored in the database by Staff ID (not just the name), ignoring the IDs the browser sent', (int) $stored['processing_staff_id'] === $staffId
    && $stored['processing_staff_name'] === 'Test Staff' && $stored['status'] === 'Processing', $stored);
$check('one history, activity and audit record for the assignment', $records($r1) === ['processingLog' => 1, 'accepted' => 1, 'audit' => 1], $records($r1));
$seen = null;
foreach ($pageData($staffB)['docRequests'] ?? [] as $candidate) {
    if (($candidate['id'] ?? '') === $r1) {
        $seen = $candidate;
    }
}
$check('other Staff see who holds the request (ID and name) in the request details data', $seen !== null && (int) $seen['processingStaffId'] === $staffId && $seen['processingStaffName'] === 'Test Staff', $seen);

// ================= Repeating never duplicates =================
$startedAt = $row($r1)['processing_started_at'];
sleep(1);
$staffA->json('start_processing', ['id' => $r1]);
$staffA->json('start_processing', ['id' => $r1]);
[$code] = $staffA->json('update_request_status', ['id' => $r1, 'kind' => 'doc', 'status' => 'Processing']);
$check('pressing Process Request or saving Processing again changes nothing and adds no records', $code === 200 && $row($r1)['processing_started_at'] === $startedAt
    && (int) $row($r1)['processing_staff_id'] === $staffId && $records($r1) === ['processingLog' => 1, 'accepted' => 1, 'audit' => 1], [$row($r1), $records($r1)]);

// ================= A second Staff member cannot silently take over =================
[$code, $res] = $staffB->json('start_processing', ['id' => $r1]);
$check('a second Staff member\'s Process Request is refused (409) with a clear reason', $code === 409 && str_contains((string) ($res['error'] ?? ''), 'already been accepted by another staff member'), [$code, $res]);
[$codeStatus] = $staffB->json('update_request_status', ['id' => $r1, 'kind' => 'doc', 'status' => 'Rejected']);
[$codeDone] = $staffB->json('complete_processing', ['id' => $r1]);
[$codeProc] = $staffB->json('update_request_status', ['id' => $r1, 'kind' => 'doc', 'status' => 'Processing']);
$check('nor can they reject, complete or re-start it', $codeStatus === 409 && $codeDone === 409 && $codeProc === 409, [$codeStatus, $codeDone, $codeProc]);
$check('the handler and the records are unchanged after their attempts', (int) $row($r1)['processing_staff_id'] === $staffId && $row($r1)['status'] === 'Processing'
    && $records($r1) === ['processingLog' => 1, 'accepted' => 1, 'audit' => 1], [$row($r1), $records($r1)]);

// ================= Simultaneous Process Request: exactly one wins =================
$race = static function (string $reference, bool $bFirst) use ($staffA, $staffB, $port): array {
    $multi = curl_multi_init();
    $handles = [];
    foreach (($bFirst ? ['B' => $staffB, 'A' => $staffA] : ['A' => $staffA, 'B' => $staffB]) as $who => $b) {
        $reflect = new ReflectionProperty($b, 'jar');
        $jar = (string) $reflect->getValue($b);
        $h = curl_init('http://127.0.0.1:' . $port . '/api.php?action=start_processing');
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['action' => 'start_processing', 'csrf' => $b->csrf, 'id' => $reference])]);
        curl_multi_add_handle($multi, $h);
        $handles[$who] = $h;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
    } while ($running > 0);
    $out = [];
    foreach ($handles as $who => $h) {
        $out[$who] = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $h);
    }
    curl_multi_close($multi);
    return $out;
};
foreach ([$r2, $r3, $r4, $r5, $r6] as $round => $reference) {
    $codes = $race($reference, $round % 2 === 1);
    $won = array_keys(array_filter($codes, static fn (int $c): bool => $c === 200));
    $holder = (int) $row($reference)['processing_staff_id'];
    $winnerOk = count($won) === 1 && in_array(409, $codes, true) && $holder === ($won[0] === 'A' ? $staffId : $otherId);
    $check("{$reference}: two Staff press Process Request at once: one succeeds, the other gets 409, and the stored handler is the one told it succeeded",
        $winnerOk && $records($reference) === ['processingLog' => 1, 'accepted' => 1, 'audit' => 1], [$codes, $holder, $records($reference)]);
}

// ================= The handler stays through to the final status =================
[$code] = $staffA->json('update_request_status', ['id' => $r1, 'kind' => 'doc', 'status' => 'Out for Release']);
$final = $row($r1);
$check('completing the request keeps its handler', $code === 200 && $final['status'] === 'Out for Release' && (int) $final['processing_staff_id'] === $staffId
    && $final['processing_completed_at'] !== null, [$code, $final]);
[$code] = $staffB->json('start_processing', ['id' => $r1]);
[$codeA] = $staffA->json('start_processing', ['id' => $r1]);
$check('a closed request cannot be taken again by anyone, and its handler does not change', $code === 409 && $codeA === 409 && (int) $row($r1)['processing_staff_id'] === $staffId, [$code, $codeA]);

// ================= Assignment does not change when others look =================
$pageData($staffA);
$pageData($staffB);
$check('the unassigned request stays unassigned after everyone has viewed the lists', $row($r7)['processing_staff_id'] === null);

$head = $signIn('test.head');
// ================= Document Review previews do not count as opening the original =================
$r7Id = (int) $row($r7)['id'];
$r7Doc = (int) $pdo->query("SELECT id FROM request_documents WHERE request_id = {$r7Id} AND file_status <> 'missing' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO ai_verification_jobs (request_id, status) VALUES ({$r7Id}, 'completed')");
$opened = static fn (): int => (int) $pdo->query("SELECT COUNT(*) FROM request_document_views WHERE user_id = {$otherId} AND request_document_id = {$r7Doc}")->fetchColumn();
[$code, $body] = $staffB->http('/request-file.php?doc=' . $r7Doc . '&ref=' . rawurlencode($r7) . '&preview=1');
$check('an inline preview is served to Staff (same access checks) but is not recorded as opening the original', $code === 200 && $body !== '' && $opened() === 0, [$code, $opened()]);
[$code, $res] = $staffB->json('ai_verification_reviewed', ['reference' => $r7]);
$check('after only seeing the preview, Mark as reviewed is still refused: the original must be opened', $code === 409 && str_contains((string) ($res['error'] ?? ''), 'Open every original document'), [$code, $res]);
[$code] = $staffB->http('/request-file.php?doc=' . $r7Doc . '&ref=' . rawurlencode($r7) . '&preview=1&download=1');
$check('a download is always recorded, even with the preview flag', $code === 200 && $opened() === 1, [$code, $opened()]);
$pdo->exec("DELETE FROM request_document_views WHERE user_id = {$otherId}");
[$code] = $staffB->http('/request-file.php?doc=' . $r7Doc . '&ref=' . rawurlencode($r7));
$check('opening the original (Open, or the preview\'s full-size link) is recorded as before', $code === 200 && $opened() === 1, [$code, $opened()]);
$r7Back = (int) $pdo->query("SELECT d.id FROM request_documents d JOIN requests r ON r.id = d.request_id WHERE r.reference_no = " . $pdo->quote($r7) . " AND d.doc_key = 'ownerIdBack'")->fetchColumn();
$staffB->http('/request-file.php?doc=' . $r7Back . '&ref=' . rawurlencode($r7));   // the National ID's back side is an original too
[$code, $res] = $staffB->json('ai_verification_reviewed', ['reference' => $r7]);
$check('after opening the originals (the National ID front and back), Mark as reviewed succeeds', $code === 200 && ($res['data']['reviewed'] ?? false) === true, [$code, $res]);
$check('reviewing does not assign the request', $row($r7)['processing_staff_id'] === null);
[$code] = $browser()->http('/request-file.php?doc=' . $r7Doc . '&ref=' . rawurlencode($r7) . '&preview=1');
$check('a preview still needs a Staff session', $code === 401, $code);

// ================= Privacy and roles unchanged =================
$admin = $signIn('test.admin');
foreach (['Head' => $head, 'Admin' => $admin] as $role => $session) {
    $list = array_merge($pageData($session)['docRequests'] ?? [], $pageData($session)['landRequests'] ?? []);
    $check("{$role} still receive no applicant details, files or document results", $list !== []
        && array_filter($list, static fn (array $r): bool => isset($r['details']) || isset($r['files']) || isset($r['ai'])) === [], array_keys($list[0] ?? []));
    [$code] = $session->http('/request-file.php?doc=' . $docId . '&ref=' . rawurlencode($r1));
    $check("{$role} cannot open uploaded files", $code === 403, $code);
    [$code] = $session->json('start_processing', ['id' => $r7]);
    $check("{$role} cannot take a request for processing", $code >= 400 && $row($r7)['processing_staff_id'] === null, $code);
}
[$code] = $browser()->json('start_processing', ['id' => $r7]);
$check('a visitor who is not signed in cannot take a request', $code >= 400 && $row($r7)['processing_staff_id'] === null, $code);
$check('no SMS or email was attempted', !is_file($sendLog) || !preg_match('/sms|email/', (string) file_get_contents($sendLog)), is_file($sendLog) ? file_get_contents($sendLog) : '');

// ================= Report =================
$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
