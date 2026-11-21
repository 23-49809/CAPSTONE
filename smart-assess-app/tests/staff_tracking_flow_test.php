<?php
declare(strict_types=1);

/*
 * Staff processing -> client tracking, end to end, on a throwaway database.
 *
 *  - Clients submit a Document Request and a Land Transfer Request through the real API.
 *  - Staff sign in, see the requests, start processing and save a new status.
 *  - The client tracking page and tracking API then show the saved status, its time
 *    and the full history, never a stale copy, and never another client's change.
 *  - Saving a status never sends SMS or email (only in-app "system" records).
 *  - Source checks: no Send Notification button in the Staff request details, the
 *    Notifications page keeps its own, Staff see the portrait layout, and the client
 *    page shows what to do next for every status.
 *
 * Run (never against a real database; the name must end in _test):
 *   SMART_ASSESS_TEST_DB=smart_tracking_flow_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=... SMART_ASSESS_DB_PASS='...' php tests/staff_tracking_flow_test.php
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
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 400)];
};
$between = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    if ($from === false) {
        return '';
    }
    $to = strpos($source, $end, $from + strlen($start));
    return $to === false ? '' : substr($source, $from, $to - $from);
};

// ================= Source checks =================
$internal = (string) file_get_contents($project . '/smart-assess-internal.html');
$public = (string) file_get_contents($project . '/smart-assess.html');
$bridge = (string) file_get_contents($project . '/smart-assess-app/web/assets/php-bridge.js');
$api = (string) file_get_contents($project . '/smart-assess-app/web/api.php');

$detail = $between($internal, 'function modalReqDetail(id, kind){', "\nfunction ");
$check('request details (Document and Land Transfer) have no Send Notification button',
    $detail !== '' && !str_contains($detail, 'Send Notification') && !str_contains($detail, 'nav-notify') && !str_contains($detail, 'rd-notify'));
$check('both request types use the same details popup', str_contains($internal, "modalWrap(modalReqDetail(m.id,'doc'), false, 'req-detail')")
    && str_contains($internal, "modalWrap(modalReqDetail(m.id,'land'), false, 'req-detail')"));
$check('the Notifications page keeps its Send Notification button and form',
    str_contains($internal, ' Send Notification</button></div>') && str_contains($internal, 'data-form="send-notif"'));
$check('the notification send action and backend are unchanged', str_contains($bridge, "'send-notif': 'send_notification'")
    && str_contains($api, "'send_notification' =>"));
$check('Staff details use the portrait layout (one label/value pair per row)', str_contains($internal, "function rdPortrait(){ var a = currentAccount(); return !!(a && a.role==='staff'); }")
    && str_contains($internal, "class=\"rd-table rd-portrait\""));
$check('the portrait layout is scoped to Staff and keeps a compact width', str_contains($internal, 'html.staff-ui .modal.req-detail:has(.rd-portrait){ width:min(780px, 94vw); max-width:780px; }'));
$check('Admin and Head keep the wide layout (two pairs per row)', str_contains($internal, "if(pending){ rows.push(pending + cell); pending = null; } else { pending = cell; }"));
$check('document previews are still available in the details', str_contains($detail . $between($internal, 'function reqSubmittedFilesHtml(r){', "\nfunction "), 'toggle-file-preview'));
$portraitCss = $between($internal, '/* Portrait request details (Staff)', "\n  }\n");
$check('the portrait layout does not change font families or sizes', $portraitCss !== '' && !preg_match('/font-(family|size)\s*:/', $portraitCss), $portraitCss);
$check('Update Status offers Processing, Rejected and Approve (Approve saves Out for Release)', str_contains($detail, "var statusChoices = [['Processing','Processing']];")
    && str_contains($detail, "statusChoices.push(['Rejected','Rejected'], ['Out for Release','Approve']);"));

$check('the receipt\'s "View My Request Status" loads the live status from the server',
    str_contains($between($public, 'function viewSubmittedStatus(){', "\nfunction "), "if(draftServer()){ window.location.assign('/index.php?track='+encodeURIComponent(submission.id)); return; }"));
$check('a tracking page restored from the back/forward cache reloads from the server',
    str_contains($public, "if(e.persisted && draftServer() && state.view==='track'"));
foreach (['Received', 'Processing', 'Approved', 'Out for Release', 'Rejected', 'Timed Out'] as $s) {
    $check("the tracking page explains the {$s} status", (bool) preg_match("/^  '" . preg_quote($s, '/') . "': \['[^']+', '[^']+'\]/m", $public));
}
$check('rejected requests tell the client how to find out the reason', str_contains($public, 'to learn the reason and what to do next'));
$check('out-for-release requests give pickup instructions', str_contains($public, 'Your document is ready. Claim it at the Municipal Assessor’s Office of Mabini during office hours'));
$check('the tracking page shows when the current status was saved', str_contains($public, "'<div class=\"trk-updated\">Updated '+esc(receiptDate(r.timestamps[status]))+'</div>'"));

// ================= A copy of the application and a test database =================
$work = sys_get_temp_dir() . '/sa-staff-tracking-' . bin2hex(random_bytes(4));
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
$staffId = (int) $pdo->query("SELECT id FROM users WHERE username = 'test.staff'")->fetchColumn();

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

$fixtures = sys_get_temp_dir() . '/sa-staff-tracking-files-' . bin2hex(random_bytes(4));
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

// ================= 1. Clients submit =================
[$docClient, $docRef] = $submit('docreq', 'Ana', 'Dimaculangan', 'Reyes');
[$landClient, $landRef] = $submit('landtransfer', 'Ben', 'Macalindong');
[$otherClient, $otherRef] = $submit('docreq', 'Carla', 'Panganiban');
[$approvedClient, $approvedRef] = $submit('docreq', 'Dino', 'Ilagan');
[$timeoutClient, $timeoutRef] = $submit('landtransfer', 'Ella', 'Magsino');
$check('references follow DR-/LT- per request type', str_starts_with($docRef, 'DR-') && str_starts_with($landRef, 'LT-'), [$docRef, $landRef]);

foreach ([[$docClient, $docRef], [$landClient, $landRef]] as [$client, $reference]) {
    [$status, $result] = $client->track($reference);
    $check("{$reference}: tracking starts at Received", $status === 200 && ($result['status'] ?? '') === 'Received'
        && isset($result['timestamps']['Received']), $result);
}

// ================= 2. Staff open the applications =================
$staff = $browser('/internal.php');
[$code] = $staff->json('internal_login', ['username' => 'test.staff', 'password' => PW]);
$check('Staff sign in', $code === 200, $code);
$staff->http('/internal.php');
[$code, $bootJson] = $staff->http('/api.php?action=internal_bootstrap');
$check('Staff see both new requests in their lists', $code === 200 && str_contains($bootJson, $docRef) && str_contains($bootJson, $landRef), [$code, substr($bootJson, 0, 200)]);

$history = static function (string $reference) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT l.status, l.created_at, l.id FROM request_status_log l JOIN requests r ON r.id = l.request_id WHERE r.reference_no = ? ORDER BY l.id');
    $stmt->execute([$reference]);
    return $stmt->fetchAll();
};
$dbStatus = static function (string $reference) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT status FROM requests WHERE reference_no = ?');
    $stmt->execute([$reference]);
    return (string) $stmt->fetchColumn();
};
$untouchedBefore = $history($otherRef);

// ================= 3-6. Staff update the status; the client sees it =================
$flows = [
    // [client browser, reference, kind used by the page, final status]
    [$docClient, $docRef, 'doc', 'Out for Release'],
    [$landClient, $landRef, 'land', 'Rejected'],
];
foreach ($flows as [$client, $reference, $kind, $final]) {
    // What "Process Request" posts.
    [$code, $res] = $staff->json('start_processing', ['id' => $reference]);
    $check("{$reference}: Process Request saves Processing", $code === 200 && ($res['data']['status'] ?? '') === 'Processing'
        && $dbStatus($reference) === 'Processing', [$code, $res]);
    [$status, $result] = $client->track($reference);
    $check("{$reference}: the client sees Processing immediately", ($result['status'] ?? '') === 'Processing' && isset($result['timestamps']['Processing']), $result);
    $processingAt = $result['timestamps']['Processing'] ?? null;

    // What the Update Status form posts (data-form="update-status": id = reference, kind, status).
    [$code, $res] = $staff->json('update_request_status', ['id' => $reference, 'kind' => $kind, 'status' => $final]);
    $check("{$reference}: Update Status saves {$final}", $code === 200 && ($res['data']['status'] ?? '') === $final && $dbStatus($reference) === $final, [$code, $res]);
    [$status, $result] = $client->track($reference);
    $check("{$reference}: the client tracking page shows {$final}", $status === 200 && ($result['status'] ?? '') === $final, $result);
    $check("{$reference}: the time of {$final} is shown", isset($result['timestamps'][$final]), $result['timestamps'] ?? null);
    $check("{$reference}: earlier history and times are kept", ($result['timestamps']['Processing'] ?? null) === $processingAt
        && isset($result['timestamps']['Received']), $result['timestamps'] ?? null);
    $check("{$reference}: the page and its tracking data are never cached", $client->noStore(), $client->headers);
    [$code, $body] = $client->http('/api.php?action=track&ref=' . rawurlencode($reference));
    $tracked = (array) (json_decode($body, true)['request'] ?? []);
    $check("{$reference}: the tracking API returns {$final} with the full history", $code === 200 && ($tracked['status'] ?? '') === $final
        && array_column($tracked['history'] ?? [], 'status') === ['Received', 'Processing', $final] && $client->noStore(), [$code, $tracked['history'] ?? null]);
    // Anyone tracking with the reference number (another device) sees the same saved status.
    [$status, $result] = $browser()->track($reference);
    $check("{$reference}: another device sees {$final} too", ($result['status'] ?? '') === $final, $result);
    [$status, $result] = $browser()->track(strtolower($reference));
    $check("{$reference}: a lowercase reference finds the same request", ($result['status'] ?? '') === $final, $result);
    [$code] = $staff->json('update_request_status', ['id' => $reference, 'kind' => $kind, 'status' => 'Received']);
    $check("{$reference}: a closed request cannot be reopened", $code >= 400 && $dbStatus($reference) === $final, $code);
}

// ================= Processing from Update Status, Processing End, rejection reasons =================
[$procClient, $procRef] = $submit('docreq', 'Fe', 'Atienza');
$timing = static function (string $reference) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT status, processing_started_at, processing_completed_at, processing_duration_seconds, processing_staff_id FROM requests WHERE reference_no = ?');
    $stmt->execute([$reference]);
    return (array) $stmt->fetch();
};
// Update Status > Processing posts start_processing (php-bridge.js); the server's own Processing route is the same function.
[$code, $res] = $staff->json('update_request_status', ['id' => $procRef, 'kind' => 'doc', 'status' => 'Processing']);
$started = $timing($procRef);
$check('Update Status > Processing starts the processing timer and assigns the Staff member', $code === 200 && $started['status'] === 'Processing'
    && $started['processing_started_at'] !== null && (int) $started['processing_staff_id'] === $staffId && $started['processing_completed_at'] === null, [$code, $res, $started]);
sleep(1);
[$code] = $staff->json('start_processing', ['id' => $procRef]);
$again = $timing($procRef);
$processingRows = array_values(array_filter($history($procRef), static fn (array $row): bool => $row['status'] === 'Processing'));
$check('choosing Processing again keeps the running timer (start time and history not reset)', $code === 200
    && $again['processing_started_at'] === $started['processing_started_at'] && count($processingRows) === 1, [$started, $again, $processingRows]);
[$code, $res] = $staff->json('update_request_status', ['id' => $procRef, 'kind' => 'doc', 'status' => 'Out for Release']);
$ended = $timing($procRef);
$check('Approve saves Out for Release and ends processing (Processing End time and final duration kept)', $code === 200 && $ended['status'] === 'Out for Release'
    && $ended['processing_completed_at'] !== null && $ended['processing_duration_seconds'] !== null && (int) $ended['processing_duration_seconds'] >= 1
    && $ended['processing_started_at'] === $started['processing_started_at'], [$code, $res, $ended]);
[, $result] = $procClient->track($procRef);
$check('the client tracking page shows the release status after Approve', ($result['status'] ?? '') === 'Out for Release' && isset($result['timestamps']['Out for Release']), $result);

// Send Updates: the recipient is the exact submitted name; rejection reasons fit the request type.
[$code, $res] = $staff->json('lookup_request_owner', ['reference' => $docRef]);
$check('Send Updates shows the full name exactly as submitted (with middle name)', $code === 200 && ($res['data']['submittedName'] ?? '') === 'Ana Reyes Dimaculangan', $res);
[$code, $res] = $staff->json('send_notification', ['request_ref' => $landRef, 'message' => 'Test', 'category' => 'Incomplete Request Details',
    'channel_choice' => '1', 'send_email' => '1']);
$check('a Document Request rejection reason is refused for a Land Transfer request', $code === 422 && str_contains((string) ($res['error'] ?? $res['message'] ?? json_encode($res)), 'rejection category'), [$code, $res]);
[$code, $res] = $staff->json('send_notification', ['request_ref' => $docRef, 'message' => 'Test', 'category' => 'Missing Vicinity Map',
    'channel_choice' => '1', 'send_email' => '1']);
$check('a Land Transfer rejection reason is refused for a Document Request', $code === 422 && str_contains((string) json_encode($res), 'rejection category'), [$code, $res]);
[$code, $res] = $staff->json('send_notification', ['request_ref' => $landRef, 'message' => 'Test', 'category' => 'Missing Vicinity Map',
    'channel_choice' => '1', 'send_email' => '1']);
$check('a Land Transfer reason is accepted for a Land Transfer request (stops later: no email on file, nothing sent)', $code === 422
    && !str_contains((string) json_encode($res), 'rejection category') && str_contains((string) json_encode($res), 'no valid email'), [$code, $res]);
[$code, $res] = $staff->json('send_notification', ['request_ref' => $docRef, 'message' => 'Test', 'category' => 'Invalid or Missing Government ID',
    'channel_choice' => '1', 'send_email' => '1']);
$check('the shared Government ID reason is accepted for a Document Request', $code === 422 && !str_contains((string) json_encode($res), 'rejection category'), [$code, $res]);

// The two remaining statuses the server accepts.
[$code] = $staff->json('start_processing', ['id' => $approvedRef]);
[$code, $res] = $staff->json('update_request_status', ['id' => $approvedRef, 'kind' => 'doc', 'status' => 'Approved']);
[$status, $result] = $approvedClient->track($approvedRef);
$check('Approved is saved and shown to the client', $code === 200 && ($result['status'] ?? '') === 'Approved' && isset($result['timestamps']['Approved']), [$code, $result]);
[$code, $res] = $staff->json('update_request_status', ['id' => $timeoutRef, 'kind' => 'land', 'status' => 'Timed Out']);
[$status, $result] = $timeoutClient->track($timeoutRef);
$check('Timed Out is saved and shown to the client', $code === 200 && ($result['status'] ?? '') === 'Timed Out' && isset($result['timestamps']['Timed Out']), [$code, $res, $result]);

// ================= Isolation and permissions =================
[$status, $result] = $otherClient->track($otherRef);
$check('another client\'s request is unaffected (still Received)', ($result['status'] ?? '') === 'Received' && $dbStatus($otherRef) === 'Received', $result);
$check('another client\'s history is unchanged', $history($otherRef) === $untouchedBefore, [$untouchedBefore, $history($otherRef)]);
$changed = $pdo->query("SELECT reference_no, status FROM requests ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$check('each update changed only its own request', $changed === [$docRef => 'Out for Release', $landRef => 'Rejected', $otherRef => 'Received',
    $approvedRef => 'Approved', $timeoutRef => 'Timed Out', $procRef => 'Out for Release'], $changed);

$otherStaff = $browser('/internal.php');
$otherStaff->json('internal_login', ['username' => 'other.staff', 'password' => PW]);
$otherStaff->http('/internal.php');
$otherStaff->json('start_processing', ['id' => $otherRef]);
[$code] = $staff->json('update_request_status', ['id' => $otherRef, 'kind' => 'doc', 'status' => 'Rejected']);
$check('Staff cannot update a request assigned to someone else', $code === 409 && $dbStatus($otherRef) === 'Processing', [$code, $dbStatus($otherRef)]);
$anonymous = $browser();
[$code] = $anonymous->json('update_request_status', ['id' => $otherRef, 'kind' => 'doc', 'status' => 'Rejected']);
$check('a client (not signed in as Staff) cannot change a status', $code >= 400 && $dbStatus($otherRef) === 'Processing', $code);
$check('the processing Staff member is recorded', (int) $pdo->query("SELECT processing_staff_id FROM requests WHERE reference_no = " . $pdo->quote($docRef))->fetchColumn() === $staffId);

// ================= No automatic SMS or email =================
$channels = $pdo->query("SELECT DISTINCT channel FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
$check('status changes create in-app records only (no SMS or email channel)', array_diff($channels, ['system']) === [], $channels);
$check('no SMS or email was attempted while statuses changed', !is_file($sendLog) || !preg_match('/sms|email/', (string) file_get_contents($sendLog)),
    is_file($sendLog) ? file_get_contents($sendLog) : '');
$check('the status-update code still states it sends no SMS or email', str_contains((string) file_get_contents($project . '/smart-assess-app/web/api.php'), 'Status changes appear in request tracking only; no SMS or email.'));

// ================= Report =================
$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
