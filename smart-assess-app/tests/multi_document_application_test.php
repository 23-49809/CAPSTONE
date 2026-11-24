<?php
declare(strict_types=1);

/*
 * Several document requests in one application (Document Requests), end to end, on a throwaway
 * database with fictional people and files only:
 *
 *  - Before migration 20261119 is applied: single-document applications work exactly as before,
 *    and several documents are refused with a clear message (nothing saved).
 *  - The migration runs twice without error and leaves every existing request unchanged.
 *  - One document: the same reference format and storage as before (no application row).
 *  - Several documents: one application reference; each document is its own request
 *    DR references, with its own type, purpose, status and its own copy of each
 *    uploaded file; duplicates and more than 5 documents are refused with nothing saved.
 *  - Drafts keep the chosen documents; resubmitting the same draft returns the same reference.
 *  - Tracking the application shows each document's own status; an item reference shows that
 *    document and its application; the full name only for the submitting browser.
 *  - Staff process each document separately (own handler); files open per document;
 *    Admin and Head still receive no applicant details or files.
 *  - Several documents: a missing required upload is refused by the server, with nothing saved;
 *    a failed file copy or database insert part-way leaves no record and no file behind.
 *  - With AI checks enabled each document gets its own job for its own files; the submission
 *    confirmation is queued once per application (SMS stub only, nothing is sent); Mark as reviewed
 *    needs each document's own original opened; Staff Send Updates works on one document.
 *  - The Head's report counts applications and individual document requests apart.
 *  - The rollback script refuses while application data exists, and works when there is none.
 *
 * Run (never against a real database; the name must end in _test):
 *   SMART_ASSESS_TEST_DB=smart_assess_test DB_HOST=127.0.0.1 DB_PORT=3318 \
 *   DB_USER=... SMART_ASSESS_DB_PASS='...' php tests/multi_document_application_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dbName = (string) getenv('SMART_ASSESS_TEST_DB');
if ($dbName !== 'smart_assess_test' || !extension_loaded('gd') || !extension_loaded('curl')) {
    fwrite(STDERR, "Refusing to run: set SMART_ASSESS_TEST_DB=smart_assess_test (GD and curl are also required).\n");
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
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 600)];
};

// ================= A copy of the application and a test database =================
$work = sys_get_temp_dir() . '/sa-multi-doc-' . bin2hex(random_bytes(4));
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
        // AI checks enabled for every submission here (only jobs are queued; no worker runs), and no
        // confirmation sender process: confirmations stay queued, so nothing is sent.
        'SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT=2020-01-01T00:00:00Z', 'SMART_ASSESS_PHP_CLI=/nonexistent/php',
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

$fixtures = sys_get_temp_dir() . '/sa-multi-doc-files-' . bin2hex(random_bytes(4));
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
        + ($flow === 'docreq' ? ['document_type' => DOCS[0], 'purpose' => 'Personal Copy'] : ['transfer_type' => 'Deed of Absolute Sale']);
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


$migration = static function (?PDO $db = null) use ($pdo, $project, &$runSql): void {
    $runSql($db ?? $pdo, (string) file_get_contents($project . '/smart-assess-app/database/migrations/20261119_request_applications.sql'));
};
$rollback = static function (?PDO $db = null) use ($pdo, $project, &$runSql): void {
    $runSql($db ?? $pdo, (string) file_get_contents($project . '/smart-assess-app/database/rollbacks/20261119_request_applications_rollback.sql'));
};
const DOCS = ['Certified True Copy of Tax Declaration (CTC-TD)', 'Certification of Assessment', 'Certification of No Liens and Encumbrances',
    'Certification of Property/No Property Holdings', 'Certification of No/With Existing Improvement'];
/** A client submits through the real API: $items = document list (null = the single-document fields). */
$apply = static function (?array $items, bool $isOwner = true, ?array $uploads = null, array $extra = [], ?object $client = null) use ($browser, $termsVersion, $fixtures): array {
    $client ??= $browser();
    [, $accepted] = $client->json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $termsVersion]);
    // A complete set: each ID's front and back side (required since the front/back upload), plus the letter.
    $uploads ??= $isOwner ? ['ownerId', 'ownerIdBack'] : ['requesterId', 'requesterIdBack', 'ownerId', 'ownerIdBack', 'authLetter'];
    $payload = ['action' => 'submit_request', 'csrf' => $client->csrf, 'terms_token' => (string) ($accepted['data']['terms_token'] ?? ''),
        'flow' => 'docreq', 'first_name' => 'Rosa', 'middle_name' => '', 'last_name' => 'Fictional', 'contact_number' => '+639170000' . random_int(100, 999),
        'address_line' => '1 Demo Street', 'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '4202', 'arp_number' => '14-0001-0' . random_int(1000, 9999),
        'property_address' => 'Poblacion', 'barangay' => 'POBLACION', 'is_owner' => $isOwner]
        + ($items !== null ? ['document_requests' => $items] : ['document_type' => DOCS[1], 'purpose' => 'Personal Copy']);
    $payload = array_merge($payload, $extra);
    $post = ['payload' => json_encode($payload)];
    foreach ($uploads as $key) {
        $post['file_' . $key] = new CURLFile($fixtures . '/id.png', 'image/png', $key . '.png');
    }
    [$status, $body] = $client->http('/api.php?action=submit_request', $post);
    $json = (array) json_decode($body, true);
    return [$status, $json, (string) ($json['data']['reference_no'] ?? $json['request']['reference_no'] ?? ''), $client];
};
$items = static fn (int $n): array => array_map(static fn (int $i): array => ['document_type' => DOCS[$i], 'purpose' => ['Personal Copy', 'For Transfer', 'For Titling', 'Personal Copy', 'For Building Permit'][$i]], range(0, $n - 1));
$count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$requestRow = static function (string $reference) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM requests WHERE reference_no = ?');
    $stmt->execute([$reference]);
    return (array) $stmt->fetch();
};
$childRef = static function (string $applicationReference, int $itemNo) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT r.reference_no FROM requests r JOIN request_applications a ON a.id=r.application_id WHERE a.reference_no=? AND r.item_no=?');
    $stmt->execute([$applicationReference, $itemNo]);
    return (string) $stmt->fetchColumn();
};

// An SMS service stub that accepts (nothing leaves this computer): it records each send in the send log.
file_put_contents($work . '/smart-assess-app/iprog-sms.php', "<?php\nfinal class IprogSmsService {\n"
    . "  public static function fromEnvironment(): self { return new self(); }\n"
    . "  public static function normalizePhoneNumber(string \$p): string { \$d = preg_replace('/\\D/', '', \$p); if (!preg_match('/^(63|0)?9\\d{9}\$/', \$d)) { throw new InvalidArgumentException('bad number'); } return '63' . substr(\$d, -10); }\n"
    . "  public function send(string \$phone, string \$message): array { file_put_contents(" . var_export($sendLog, true) . ", 'stub-sms ' . md5(\$message) . \"\\n\", FILE_APPEND); return ['message_id' => 'stub-' . bin2hex(random_bytes(4))]; }\n"
    . "  public function messageStatus(string \$id): ?string { return null; }\n}\n");
// Delivery tracking (as production): needed for the submission confirmation and Staff sends.
$runSql = static function (PDO $db, string $sql): void {
    // As the mysql client runs a file: statement by statement, reading any result, stopping at the first error.
    foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('/^\s*--.*$/m', '', $sql)))) as $statement) {
        $result = $db->query($statement);
        if ($result instanceof PDOStatement) {
            $result->fetchAll();
            $result->closeCursor();
        }
    }
};
$runSql($pdo, (string) file_get_contents($project . '/smart-assess-app/database/migrations/20261113_notification_delivery_tracking.sql'));
$pdo->exec('ALTER TABLE request_status_log ADD COLUMN sms_category VARCHAR(60) NULL, ADD COLUMN delivery_status VARCHAR(20) NULL, ADD COLUMN sms_sent_by_id INT NULL');

// ================= Before the migration =================
/** The client page as the server renders it: [status, its SMART_ASSESS_CONFIG, its HTML]. */
$renderedGate = static function () use ($browser): array {
    [$status, $body] = $browser()->http('/index.php');
    preg_match('/window\.SMART_ASSESS_CONFIG=(\{.*?\});window\.SMART_ASSESS_PAGE=/s', $body, $m);
    return [$status, (array) json_decode($m[1] ?? '{}', true), $body];
};
[$gateCode, $gateConfig, $gateHtml] = $renderedGate();
$check('before the migration: the rendered client page tells the page it can take one document per application (multiDocument false)', $gateCode === 200
    && array_key_exists('multiDocument', $gateConfig) && $gateConfig['multiDocument'] === false && ($gateConfig['portal'] ?? '') === 'client', [$gateCode, $gateConfig]);
$check('the rendered page carries the gate that hides "Add Another Document Request" and stops several documents at Step 2', str_contains($gateHtml, 'function multiDocAllowed(){')
    && str_contains($gateHtml, "c.multiDocument===false") && str_contains($gateHtml, "errors['doc-multi'] = ONE_DOC_ONLY_MSG;"));
[, $internalConfig] = (static function () use ($browser): array {
    [$status, $body] = $browser('/internal.php')->http('/internal.php');
    preg_match('/window\.SMART_ASSESS_CONFIG=(\{.*?\});window\.SMART_ASSESS_PAGE=/s', $body, $m);
    return [$status, (array) json_decode($m[1] ?? '{}', true)];
})();
$check('the Staff page is not given the client-page flag', $internalConfig !== [] && !array_key_exists('multiDocument', $internalConfig), $internalConfig);
[$code, $res, $legacyRef] = $apply(null);
$check('before the migration: a single-document application is saved as before', $code === 200 && (bool) preg_match('/^DR-\d{4}-[A-F0-9]{8}$/', $legacyRef), [$code, $res]);
$mixedClient = $browser();
$beforeMixed = [$count('SELECT COUNT(*) FROM requests'), $count('SELECT COUNT(*) FROM request_applications')];
[$mixedCode, $mixedBody] = $mixedClient->json('submit_request', ['flow' => 'mixed']);
$check('the submission API rejects the former mixed flow without creating a request or parent', $mixedCode === 422
    && [$count('SELECT COUNT(*) FROM requests'), $count('SELECT COUNT(*) FROM request_applications')] === $beforeMixed, [$mixedCode, $mixedBody]);
$before = $count('SELECT COUNT(*) FROM requests');
[$code, $res] = $apply($items(2));
$check('before the migration: several documents are refused with a clear message, nothing saved', $code === 422
    && str_contains((string) ($res['error'] ?? ''), 'not available yet') && $count('SELECT COUNT(*) FROM requests') === $before, [$code, $res]);
[$code, $res, $oneListRef] = $apply($items(1));
$check('before the migration: a one-item document list still works (single document)', $code === 200 && (bool) preg_match('/^DR-\d{4}-[A-F0-9]{8}$/', $oneListRef), [$code, $res]);
$legacyBefore = $requestRow($legacyRef);
$headEarly = $signIn('test.head');
[$code, $body] = $headEarly->http('/api.php?action=head_monthly_report');
$earlyCards = (array) (json_decode($body, true)['data']['cards'] ?? []);
$check('before the migration: every Document Request is its own application in the Head report', $code === 200 && ($earlyCards['documents'] ?? -1) === 2
    && ($earlyCards['documentApplications'] ?? -1) === 2 && ($earlyCards['applications'] ?? -1) === 2 + (int) ($earlyCards['landTransfers'] ?? 0), $earlyCards);

// ================= The migration =================
$migration();
$migration();
$runSql($pdo, (string) file_get_contents($project . '/smart-assess-app/database/migrations/20261125_mixed_applications.sql'));
$runSql($pdo, (string) file_get_contents($project . '/smart-assess-app/database/migrations/20261125_mixed_applications.sql'));
$legacyAfter = $requestRow($legacyRef);
$check('migrations run twice without error and the additive release migration adds Released status', $count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME IN ('application_id', 'item_no')") === 2
    && str_contains((string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='requests' AND COLUMN_NAME='status'")->fetchColumn(), "'Released'")
    && $count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'request_applications'") === 1);
unset($legacyAfter['application_id'], $legacyAfter['item_no']);
$check('existing requests are unchanged by the migration (new columns empty)', $legacyAfter === $legacyBefore && $requestRow($legacyRef)['application_id'] === null
    && $requestRow($legacyRef)['item_no'] === null, [$legacyBefore, $legacyAfter]);
[$gateCode, $gateConfig] = $renderedGate();
$check('after the migration: the rendered client page offers several documents (multiDocument true), with no other change', $gateCode === 200
    && ($gateConfig['multiDocument'] ?? null) === true && ($gateConfig['portal'] ?? '') === 'client', [$gateCode, $gateConfig]);
[, $tracked] = $browser()->track($legacyRef);
$check('an existing single-document request is still tracked as before', ($tracked['id'] ?? '') === $legacyRef && ($tracked['docType'] ?? '') === DOCS[1]
    && !isset($tracked['items']) && ($tracked['applicationRef'] ?? null) === null, $tracked);

// ================= One document after the migration =================
[$code, $res, $singleRef] = $apply(null);
$check('one document: the same reference format, no application row, no item number', $code === 200 && (bool) preg_match('/^DR-\d{4}-[A-F0-9]{8}$/', $singleRef)
    && $requestRow($singleRef)['application_id'] === null && $count('SELECT COUNT(*) FROM request_applications') === 0 && !isset($res['data']['documents']), [$code, $res]);

// ================= Several documents =================
[$code, $res, $appRef, $appClient] = $apply($items(3));
$appRow = (array) $pdo->query('SELECT * FROM request_applications WHERE reference_no = ' . $pdo->quote($appRef))->fetch();
$check('three documents: one APP application reference, recorded once with its document count', $code === 200 && (bool) preg_match('/^APP-\d{4}-[A-F0-9]{8}$/', $appRef)
    && (int) ($appRow['item_count'] ?? 0) === 3 && $count('SELECT COUNT(*) FROM request_applications') === 1, [$code, $res, $appRow]);
$children = $pdo->query('SELECT * FROM requests WHERE application_id = ' . (int) $appRow['id'] . ' ORDER BY item_no')->fetchAll();
$check('each document is its own DR request with own type and purpose, same requester and property', count($children) === 3
    && count(array_unique(array_column($children, 'reference_no'))) === 3
    && count(array_filter(array_column($children, 'reference_no'), static fn(string $r): bool => (bool)preg_match('/^DR-\d{4}-[A-F0-9]{8}$/', $r))) === 3
    && array_column($children, 'document_type') === [DOCS[0], DOCS[1], DOCS[2]] && array_column($children, 'purpose') === ['Personal Copy', 'For Transfer', 'For Titling']
    && count(array_unique(array_column($children, 'arp_number'))) === 1 && count(array_unique(array_column($children, 'first_name'))) === 1
    && array_map('intval', array_column($children, 'item_no')) === [1, 2, 3], array_map(static fn ($c) => [$c['reference_no'], $c['document_type'], $c['purpose']], $children));
$check('the APP reference itself is not a request and submission returns each child DR reference',
    $count('SELECT COUNT(*) FROM requests WHERE reference_no = ' . $pdo->quote($appRef)) === 0
    && ($res['data']['documents'][2]['reference_no'] ?? '') === $children[2]['reference_no'], $res);
$files = $pdo->query('SELECT rd.request_id, rd.doc_key, rd.stored_path, rd.file_status FROM request_documents rd JOIN requests r ON r.id = rd.request_id
    WHERE r.application_id = ' . (int) $appRow['id'] . ' ORDER BY rd.request_id, rd.id')->fetchAll();
$paths = array_column($files, 'stored_path');
$app = $work . '/smart-assess-app/';
$check('every document request has its own records and its own stored copies of the shared ID, front and back (6 distinct files, same content)', count($files) === 6
    && array_column($files, 'doc_key') === ['ownerId', 'ownerIdBack', 'ownerId', 'ownerIdBack', 'ownerId', 'ownerIdBack'] && count(array_unique($paths)) === 6
    && array_filter($paths, static fn ($p) => !is_file($app . $p)) === [] && count(array_unique(array_map(static fn ($p) => md5_file($app . $p), $paths))) === 1, $files);
$check('each document\'s requirements are complete on its own', array_map('intval', array_column($children, 'requirement_complete')) === [1, 1, 1]);
$check('each document has its own Received history entry', $count('SELECT COUNT(*) FROM request_status_log l JOIN requests r ON r.id = l.request_id WHERE r.application_id = ' . (int) $appRow['id'] . " AND l.status = 'Received' AND l.actor = 'client'") === 3);
$check('the Terms acceptance is linked once, to the first document', $count('SELECT COUNT(*) FROM terms_acceptances WHERE request_id IN (' . implode(',', array_column($children, 'id')) . ')') === 1
    && $count('SELECT COUNT(*) FROM terms_acceptances WHERE request_id = ' . (int) $children[0]['id']) === 1);

// A representative: each document keeps all three identity files of its own.
[$code, $res, $repRef] = $apply($items(2), false);
$repFiles = $pdo->query('SELECT r.reference_no, rd.doc_key, rd.file_status FROM request_documents rd JOIN requests r ON r.id = rd.request_id
    WHERE r.application_id = (SELECT id FROM request_applications WHERE reference_no=' . $pdo->quote($repRef) . ') ORDER BY r.item_no, rd.id')->fetchAll();
$check('a representative\'s two documents each keep both sides of the representative ID and of the owner ID, and the authorization letter', $code === 200 && count($repFiles) === 10
    && (static function (array $c): array { ksort($c); return $c; })(array_count_values(array_column($repFiles, 'doc_key'))) === ['authLetter' => 2, 'ownerId' => 2, 'ownerIdBack' => 2,
        'requesterId' => 2, 'requesterIdBack' => 2]
    && array_unique(array_column($repFiles, 'file_status')) === ['ok'], $repFiles);
// ================= Required uploads (server) =================
$snapshot = static fn (): array => [$count('SELECT COUNT(*) FROM requests'), $count('SELECT COUNT(*) FROM request_applications'),
    $count('SELECT COUNT(*) FROM request_documents'), $count('SELECT COUNT(*) FROM request_status_log'), $count('SELECT COUNT(*) FROM notifications'),
    count(glob($app . 'private_uploads/requests/*') ?: [])];
$before = $snapshot();
[$code, $res] = $apply($items(2), false, ['requesterId', 'ownerId']);
$check('several documents with a missing upload (authorization letter) are refused by the server, naming the file', $code === 422
    && str_contains((string) ($res['error'] ?? ''), 'Authorization Letter') && str_contains((string) ($res['error'] ?? ''), 'Nothing was submitted'), [$code, $res]);
[$code2, $res2] = $apply($items(3), true, []);
$check('several documents with no upload at all are refused', $code2 === 422 && str_contains((string) ($res2['error'] ?? ''), 'Property Owner National ID'), [$code2, $res2]);
// A page opened before the back photo existed: several documents with the front photo only are refused
// (nothing is silently accepted), and the message asks the applicant to reload the page.
[$code3, $res3] = $apply($items(2), true, ['ownerId']);
$check('an old cached page (several documents, front photo only) is refused, naming the back side, and says to reload the page', $code3 === 422
    && str_contains((string) ($res3['error'] ?? ''), 'Upload every required document before submitting: Property Owner National ID (back side). Nothing was submitted.')
    && str_contains((string) ($res3['error'] ?? ''), 'If you do not see a place to upload one of these, please reload this page and try again.'), [$code3, $res3]);
$check('a refused application leaves no request, application, document record, history, notification or file', $snapshot() === $before, [$before, $snapshot()]);
[$code, $res, $singleMissing] = $apply(null, false, ['requesterId', 'requesterIdBack', 'ownerId', 'ownerIdBack']);
$check('one document keeps the earlier rule for other files: a missing authorization letter is recorded as missing for Staff to follow up', $code === 200
    && (int) $requestRow($singleMissing)['requirement_complete'] === 0
    && $count("SELECT COUNT(*) FROM request_documents rd JOIN requests r ON r.id = rd.request_id WHERE r.reference_no = " . $pdo->quote($singleMissing) . " AND rd.doc_key = 'authLetter' AND rd.file_status = 'missing'") === 1, [$code, $res]);

// ================= A failure part-way leaves nothing behind =================
/** Starts a submission without waiting; returns a function that waits for its HTTP status. */
$applyAsync = static function (array $items, string $firstName) use ($browser, $termsVersion, $fixtures, $port): Closure {
    $client = $browser();
    [, $accepted] = $client->json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $termsVersion]);
    $payload = ['action' => 'submit_request', 'csrf' => $client->csrf, 'terms_token' => (string) ($accepted['data']['terms_token'] ?? ''), 'flow' => 'docreq',
        'first_name' => $firstName, 'middle_name' => '', 'last_name' => 'Fictional', 'contact_number' => '+639170000555', 'address_line' => '1 Demo Street',
        'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '4202', 'arp_number' => '14-0001-05555', 'property_address' => 'Poblacion', 'barangay' => 'POBLACION',
        'is_owner' => true, 'document_requests' => $items];
    $jar = (string) (new ReflectionProperty($client, 'jar'))->getValue($client);
    $handle = curl_init('http://127.0.0.1:' . $port . '/api.php?action=submit_request');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => ['payload' => json_encode($payload), 'file_ownerId' => new CURLFile($fixtures . '/id.png', 'image/png', 'id.png'),
            'file_ownerIdBack' => new CURLFile($fixtures . '/id.png', 'image/png', 'id-back.png')]]);
    $multi = curl_multi_init();
    curl_multi_add_handle($multi, $handle);
    curl_multi_exec($multi, $running);
    return static function (?Closure $whileWaiting = null) use ($multi, $handle): int {
        do {
            curl_multi_exec($multi, $running);
            if ($whileWaiting) {
                $whileWaiting();
            }
            curl_multi_select($multi, 0.05);
        } while ($running > 0);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $handle);
        curl_multi_close($multi);
        return $status;
    };
};
// (a) A file copy fails: the second document's request is held for 3 s (a test trigger), during which the
// first document's stored file is made unreadable, so copying it for the second document fails.
$pdo->exec("CREATE TRIGGER sa_test_hold_second BEFORE INSERT ON requests FOR EACH ROW
    BEGIN IF NEW.first_name = 'Copyfail' AND NEW.item_no = 2 THEN DO SLEEP(3); END IF; END");
$before = $snapshot();
$existing = glob($app . 'private_uploads/requests/*') ?: [];
$wait = $applyAsync($items(3), 'Copyfail');
$locked = null;
$status = $wait(static function () use ($app, $existing, &$locked): void {
    if ($locked === null) {
        $new = array_values(array_diff(glob($app . 'private_uploads/requests/*') ?: [], $existing));
        if ($new !== []) {
            $locked = $new[0];
            chmod($locked, 0000);
        }
    }
});
$pdo->exec('DROP TRIGGER sa_test_hold_second');
$check('a file copy that fails part-way fails the submission (the stored file was made unreadable in time)', $locked !== null && $status >= 500, [$status, $locked]);
$check('after the failed copy: no request, application, document record, history or notification, and no file left', $snapshot() === $before
    && $count("SELECT COUNT(*) FROM requests WHERE first_name = 'Copyfail'") === 0, [$before, $snapshot()]);
// (b) A database insert fails after files were copied: the third document's file record is refused.
$pdo->exec("CREATE TRIGGER sa_test_fail_third BEFORE INSERT ON request_documents FOR EACH ROW
    BEGIN IF (SELECT first_name FROM requests WHERE id = NEW.request_id) = 'Dbfail' AND (SELECT item_no FROM requests WHERE id = NEW.request_id) = 3
    THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test: insert refused'; END IF; END");
$before = $snapshot();
$status = $applyAsync($items(3), 'Dbfail')();
$pdo->exec('DROP TRIGGER sa_test_fail_third');
$check('a database insert that fails after two copies were stored fails the submission', $status >= 500, $status);
$check('after the failed insert: nothing saved and the copies already stored were removed', $snapshot() === $before
    && $count("SELECT COUNT(*) FROM requests WHERE first_name = 'Dbfail'") === 0, [$before, $snapshot()]);

// ================= AI checks and the confirmation, for the three-document application =================
$childIds = array_map('intval', array_column($children, 'id'));
$jobs = $pdo->query('SELECT id, request_id, status FROM ai_verification_jobs WHERE request_id IN (' . implode(',', $childIds) . ') ORDER BY request_id')->fetchAll();
$check('AI checks enabled: each document has exactly one job of its own', array_map('intval', array_column($jobs, 'request_id')) === $childIds, $jobs);
require_once $work . '/smart-assess-app/app/services/DocumentRequirements.php';
require_once $work . '/smart-assess-app/app/services/DocumentAnalysis.php';
require_once $work . '/smart-assess-app/app/services/AiVerification.php';
$manifests = array_map(static fn (array $job): array => AiVerification::jobManifest($pdo, $job, $work . '/smart-assess-app'), $jobs);
$jobPaths = array_map(static fn (array $m): array => array_column($m['documents'], 'path'), $manifests);
$storedPaths = static fn (int $id, string $which): array => array_map(static fn ($p) => realpath($app . $p), $pdo->query("SELECT stored_path FROM request_documents WHERE request_id = {$id}
    AND file_status = 'ok' AND doc_key " . $which . " ('ownerIdBack', 'requesterIdBack') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$ownPaths = array_map(static fn (int $id): array => $storedPaths($id, 'NOT IN'), $childIds);
$check('each job is built from its own document request: its document type and only its own stored files (the ID fronts)', array_column(array_column($manifests, 'declared'), 'document_type') === [DOCS[0], DOCS[1], DOCS[2]]
    && $jobPaths === $ownPaths && count(array_unique(array_merge(...$jobPaths))) === 3, [array_column($manifests, 'declared'), $jobPaths, $ownPaths]);
$backPaths = array_merge(...array_map(static fn (int $id): array => $storedPaths($id, 'IN'), $childIds));
$check('each document keeps its own stored back side, which the automatic check never receives', count($backPaths) === 3 && count(array_unique($backPaths)) === 3
    && array_intersect($backPaths, array_merge(...$jobPaths)) === [], $backPaths);
$confirmations = $pdo->query("SELECT request_id, channel, message FROM notifications WHERE type = 'submission_confirmation' AND request_id IN (" . implode(',', $childIds) . ')')->fetchAll();
$check('the submission confirmation is queued once for the whole application (on the first document, quoting the application reference)', count($confirmations) === 1
    && (int) $confirmations[0]['request_id'] === $childIds[0] && $confirmations[0]['channel'] === 'sms' && str_contains($confirmations[0]['message'], $appRef . '.'), $confirmations);
$singleId = (int) $requestRow($singleRef)['id'];
$check('a single document still gets its one confirmation', $count("SELECT COUNT(*) FROM notifications WHERE type = 'submission_confirmation' AND request_id = {$singleId}") === 1);
$check('nothing was sent at submission (confirmations stay queued; the SMS stub was not called)', !is_file($sendLog) || !str_contains((string) file_get_contents($sendLog), 'stub-sms'));

// ================= Refused lists: nothing saved =================
$beforeRequests = $count('SELECT COUNT(*) FROM requests');
$beforeApps = $count('SELECT COUNT(*) FROM request_applications');
$beforeFiles = count(glob($app . 'private_uploads/requests/*') ?: []);
[$code, $res] = $apply([['document_type' => DOCS[0], 'purpose' => 'Personal Copy'], ['document_type' => '  certified true copy of TAX declaration (ctc-td) ', 'purpose' => 'For Transfer']]);
$check('the same document twice (any case or spacing) is refused', $code === 422 && str_contains((string) ($res['error'] ?? ''), 'only once'), [$code, $res]);
[$code, $res] = $apply(array_merge($items(5), [['document_type' => 'Another Document', 'purpose' => 'Personal Copy']]));
$check('more than 5 documents is refused', $code === 422 && str_contains((string) ($res['error'] ?? ''), 'up to 5'), [$code, $res]);
[$code, $res] = $apply([['document_type' => DOCS[0], 'purpose' => '']]);
[$code2, $res2] = $apply([]);
$check('a document without a purpose, or an empty list, is refused', $code === 422 && $code2 === 422, [$code, $res, $code2, $res2]);
$check('refused applications save no request, application or file', $count('SELECT COUNT(*) FROM requests') === $beforeRequests
    && $count('SELECT COUNT(*) FROM request_applications') === $beforeApps && count(glob($app . 'private_uploads/requests/*') ?: []) === $beforeFiles);
[$code, $res, $fiveRef] = $apply($items(5));
$check('5 different documents are accepted', $code === 200 && $count('SELECT COUNT(*) FROM requests WHERE application_id = (SELECT id FROM request_applications WHERE reference_no=' . $pdo->quote($fiveRef) . ')') === 5, [$code, $res]);

// ================= Each document request is checked on its own: listed documents and purposes only =================
$page = (string) file_get_contents($project . '/smart-assess.html');
$pageList = static function (string $name) use ($page): array {
    preg_match('/const ' . $name . ' = \[(.*?)\];/s', $page, $m);
    preg_match_all("/'([^']+)'/", $m[1] ?? '', $values);
    return $values[1];
};
$check('the server accepts exactly the page\'s document types and purposes, in the same order', $pageList('DOC_TYPES') === DocumentRequirements::DOCUMENT_REQUEST_TYPES
    && $pageList('PURPOSES') === DocumentRequirements::DOCUMENT_REQUEST_PURPOSES && count(DOCS) === count(DocumentRequirements::DOCUMENT_REQUEST_TYPES)
    && array_diff(DOCS, DocumentRequirements::DOCUMENT_REQUEST_TYPES) === [], [$pageList('DOC_TYPES'), $pageList('PURPOSES')]);
$beforeRequests = $count('SELECT COUNT(*) FROM requests');
$beforeApps = $count('SELECT COUNT(*) FROM request_applications');
$beforeFiles = count(glob($app . 'private_uploads/requests/*') ?: []);
[$code, $res] = $apply([['document_type' => DOCS[0], 'purpose' => 'Personal Copy'], ['document_type' => 'Building Plan (not offered)', 'purpose' => 'Personal Copy']]);
$check('a document that is not offered is refused, naming its request ("Document Request 2")', $code === 422
    && str_starts_with((string) ($res['error'] ?? ''), 'Document Request 2: Select a document type from the list.'), [$code, $res]);
[$code, $res] = $apply([['document_type' => DOCS[0], 'purpose' => 'Personal Copy'], ['document_type' => DOCS[1], 'purpose' => 'For Transfer'], ['document_type' => DOCS[2], 'purpose' => 'Anything']]);
$check('a purpose that is not offered is refused, naming its request ("Document Request 3"), even when the others are valid', $code === 422
    && str_starts_with((string) ($res['error'] ?? ''), 'Document Request 3: Select a purpose from the list.'), [$code, $res]);
[$code, $res] = $apply([['document_type' => DOCS[0], 'purpose' => 'Personal Copy'], ['document_type' => '', 'purpose' => '']]);
[$code2, $res2] = $apply([['document_type' => DOCS[0], 'purpose' => 'Personal Copy'], ['document_type' => DOCS[1], 'purpose' => '']]);
$check('an empty document type or purpose is refused, naming its request', $code === 422 && ($res['error'] ?? '') !== '' && str_starts_with((string) $res['error'], 'Document Request 2: Select a document type.')
    && $code2 === 422 && str_starts_with((string) ($res2['error'] ?? ''), 'Document Request 2: Select a purpose.'), [$res, $res2]);
[$code, $res] = $apply([['document_type' => DOCS[0], 'purpose' => 'Personal Copy'], 'not a request']);
$check('a malformed entry in the list is refused, naming its request', $code === 422 && str_starts_with((string) ($res['error'] ?? ''), 'Document Request 2:'), [$code, $res]);
[$code, $res] = $apply(null, true, null, ['document_type' => 'Made-up Certificate']);
[$code2, $res2] = $apply(null, true, null, ['purpose' => 'Test']);
$check('one document (the earlier fields) follows the same lists, without a request number', $code === 422 && str_starts_with((string) ($res['error'] ?? ''), 'Select a document type from the list.')
    && $code2 === 422 && str_starts_with((string) ($res2['error'] ?? ''), 'Select a purpose from the list.'), [$res, $res2]);
$check('refused document requests save no request, application or file', $count('SELECT COUNT(*) FROM requests') === $beforeRequests
    && $count('SELECT COUNT(*) FROM request_applications') === $beforeApps && count(glob($app . 'private_uploads/requests/*') ?: []) === $beforeFiles);
[$code, $res, $spelledRef] = $apply([['document_type' => '  certification of   ASSESSMENT ', 'purpose' => 'for titling'], ['document_type' => DOCS[0], 'purpose' => 'personal copy']]);
$check('listed choices in another letter case or spacing are saved with the listed spelling, each on its own request', $code === 200
    && $requestRow($childRef($spelledRef, 1))['document_type'] === 'Certification of Assessment' && $requestRow($childRef($spelledRef, 1))['purpose'] === 'For Titling'
    && $requestRow($childRef($spelledRef, 2))['document_type'] === DOCS[0] && $requestRow($childRef($spelledRef, 2))['purpose'] === 'Personal Copy', [$code, $res]);
[$code, $res, $oneRef] = $apply(null);
$check('one valid document is still saved as before (no application, no item number)', $code === 200 && (bool) preg_match('/^DR-\d{4}-[A-F0-9]{8}$/', $oneRef)
    && $requestRow($oneRef)['application_id'] === null && $requestRow($oneRef)['document_type'] === DOCS[1], [$code, $res]);

// ================= Duplicate submissions without a saved draft (the page's submission key) =================
$snapshotKey = static fn (string $ref): array => [
    $count('SELECT COUNT(*) FROM request_applications WHERE reference_no = ' . $pdo->quote($ref)),
    $count('SELECT COUNT(*) FROM requests WHERE reference_no=' . $pdo->quote($ref) . ' OR application_id=(SELECT id FROM request_applications WHERE reference_no=' . $pdo->quote($ref) . ')'),
    $count('SELECT COUNT(*) FROM request_documents rd JOIN requests r ON r.id=rd.request_id WHERE r.reference_no=' . $pdo->quote($ref) . ' OR r.application_id=(SELECT id FROM request_applications WHERE reference_no=' . $pdo->quote($ref) . ')'),
    $count("SELECT COUNT(*) FROM notifications n JOIN requests r ON r.id=n.request_id WHERE n.type='submission_confirmation' AND (r.reference_no=" . $pdo->quote($ref) . " OR r.application_id=(SELECT id FROM request_applications WHERE reference_no=" . $pdo->quote($ref) . '))'),
];
$keyClient = $browser();
$key = bin2hex(random_bytes(16));
[$code, $res, $keyRef] = $apply($items(3), true, null, ['submission_key' => $key], $keyClient);
$first = $snapshotKey($keyRef);
$filesAfterFirst = count(glob($app . 'private_uploads/requests/*') ?: []);
$allRequests = $count('SELECT COUNT(*) FROM requests');
$check('an application sent with a submission key is saved once: 3 documents, their files and one confirmation', $code === 200 && $first[0] === 1 && $first[1] === 3 && $first[2] === 6 && $first[3] === 1, [$code, $res, $first]);
[$code, $res, $againKeyRef] = $apply($items(3), true, null, ['submission_key' => $key], $keyClient);
$check('sent again with the same key (a lost response, Submit pressed again): the same reference, marked as a repeat', $code === 200 && $againKeyRef === $keyRef
    && ($res['data']['duplicate'] ?? false) === true, [$code, $res]);
[$code, $res, $changedRef] = $apply($items(4), true, null, ['submission_key' => $key], $keyClient);
$check('a repeat with changed contents still returns the saved application and changes nothing', $code === 200 && $changedRef === $keyRef && $snapshotKey($keyRef) === $first, [$code, $res]);
$check('the repeats saved no request, document record, file or confirmation', $count('SELECT COUNT(*) FROM requests') === $allRequests
    && count(glob($app . 'private_uploads/requests/*') ?: []) === $filesAfterFirst && $snapshotKey($keyRef) === $first);
[$code, $res, $otherRef] = $apply($items(3), true, null, ['submission_key' => $key]);
$check('the same key from another browser session is a separate application (a key never reveals another session\'s reference)', $code === 200
    && $otherRef !== '' && $otherRef !== $keyRef && ($res['data']['duplicate'] ?? false) === false, [$code, $res]);
[$code, $res, $newRef] = $apply($items(2), true, null, ['submission_key' => bin2hex(random_bytes(16))], $keyClient);
$check('a new application from the same browser (a new key) is saved as its own application', $code === 200 && $newRef !== $keyRef && $snapshotKey($newRef)[1] === 2, [$code, $res]);
$singleKey = bin2hex(random_bytes(16));
[$code, $res, $singleKeyRef] = $apply(null, true, null, ['submission_key' => $singleKey], $keyClient);
[$code2, $res2, $singleAgain] = $apply(null, true, null, ['submission_key' => $singleKey], $keyClient);
$check('one document is protected the same way', $code === 200 && $code2 === 200 && $singleAgain === $singleKeyRef
    && $count('SELECT COUNT(*) FROM requests WHERE reference_no = ' . $pdo->quote($singleKeyRef)) === 1, [$res, $res2]);
$retryKey = bin2hex(random_bytes(16));
[$code, $res] = $apply($items(2), true, ['ownerId'], ['submission_key' => $retryKey], $keyClient);
[$code2, $res2, $retryRef] = $apply($items(2), true, null, ['submission_key' => $retryKey], $keyClient);
$check('a refused attempt does not use up its key: corrected and sent again, it is saved', $code === 422 && $code2 === 200 && $retryRef !== ''
    && ($res2['data']['duplicate'] ?? false) === false && $snapshotKey($retryRef)[1] === 2, [$res, $res2]);
// The same key for a different kind of application (a page that did not start a new key): a separate
// application, never answered with the earlier one; the earlier one is still recognized as before.
$ltKey = bin2hex(random_bytes(16));
[$code, $res, $ltDocRef] = $apply($items(2), true, null, ['submission_key' => $ltKey], $keyClient);
[, $ltAccepted] = $keyClient->json('terms_accept', ['flow' => 'landtransfer', 'agreed' => true, 'terms_version' => $termsVersion]);
$ltPost = ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $keyClient->csrf, 'terms_token' => (string) ($ltAccepted['data']['terms_token'] ?? ''),
    'flow' => 'landtransfer', 'first_name' => 'Rosa', 'middle_name' => '', 'last_name' => 'Fictional', 'contact_number' => '+639170000' . random_int(100, 999),
    'address_line' => '1 Demo Street', 'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '4202', 'arp_number' => '14-0001-0' . random_int(1000, 9999),
    'property_address' => 'Poblacion', 'barangay' => 'POBLACION', 'is_owner' => true, 'transfer_type' => 'Sale', 'submission_key' => $ltKey])];
foreach (array_keys(DocumentRequirements::requirementsFor('landtransfer', true, true)) as $ltDoc) {
    $ltPost['file_' . $ltDoc] = new CURLFile($fixtures . '/id.png', 'image/png', $ltDoc . '.png');
}
[$ltStatus, $ltBody] = $keyClient->http('/api.php?action=submit_request', $ltPost);
$ltJson = (array) json_decode($ltBody, true);
$ltRef = (string) ($ltJson['data']['reference_no'] ?? '');
$check('the same key sent with another kind of application (Land Transfer) saves that application; it is never answered with the earlier one', $code === 200
    && $ltStatus === 200 && str_starts_with($ltRef, 'LT-') && ($ltJson['data']['duplicate'] ?? false) === false
    && $count('SELECT COUNT(*) FROM requests WHERE reference_no = ' . $pdo->quote($ltRef)) === 1
    && $requestRow($ltRef)['application_id'] === null
    && $count('SELECT COUNT(*) FROM request_applications WHERE reference_no=' . $pdo->quote($ltRef)) === 0,
    [$code, $ltStatus, substr($ltBody, 0, 300)]);
[$code, $res, $ltDocAgain] = $apply($items(2), true, null, ['submission_key' => $ltKey], $keyClient);
$check('the earlier Document Request is still recognized by its key afterwards', $code === 200 && $ltDocAgain === $ltDocRef && ($res['data']['duplicate'] ?? false) === true, [$code, $res]);
$beforeOld = $count('SELECT COUNT(*) FROM request_applications');
[$code, , $oldRef1] = $apply($items(2), true, null, ['submission_key' => 'not-a-key'], $keyClient);
[$code2, , $oldRef2] = $apply($items(2), true, null, [], $keyClient);
$check('an older page (no key, or not a valid key) is handled as before', $code === 200 && $code2 === 200 && $oldRef1 !== $oldRef2
    && $count('SELECT COUNT(*) FROM request_applications') === $beforeOld + 2);

// ================= Drafts =================
$draftClient = $browser();
[, $draft] = $draftClient->json('draft_save', ['flow' => 'docreq', 'step' => 2, 'fields' => ['firstName' => 'Rosa', 'documentRequests' => [
    ['documentType' => DOCS[0], 'purpose' => 'Personal Copy'], ['documentType' => DOCS[3], 'purpose' => 'For Titling']]]]);
$draftCode = str_replace('-', '', (string) ($draft['data']['code'] ?? ''));
[, $loaded] = $draftClient->json('draft_load', ['draft_token' => $draftCode]);
$check('a draft keeps the chosen documents (type and purpose each)', ($loaded['data']['fields']['documentRequests'] ?? null) === [
    ['documentType' => DOCS[0], 'purpose' => 'Personal Copy'], ['documentType' => DOCS[3], 'purpose' => 'For Titling']], $loaded);
[$code] = $draftClient->json('draft_save', ['flow' => 'docreq', 'step' => 2, 'draft_token' => $draftCode, 'fields' => ['documentRequests' => array_fill(0, 6, ['documentType' => 'x', 'purpose' => 'y'])]]);
$check('a draft with more documents than allowed is refused', $code === 422, $code);
foreach (['ownerId', 'ownerIdBack'] as $draftKey) {   // the ID's front and back saved with the draft
    $draftClient->http('/api.php?action=draft_upload', ['payload' => json_encode(['action' => 'draft_upload', 'csrf' => $draftClient->csrf, 'draft_token' => $draftCode, 'doc_key' => $draftKey]),
        'file' => new CURLFile($fixtures . '/id.png', 'image/png', $draftKey . '.png')]);
}
$draftItems = [['document_type' => DOCS[0], 'purpose' => 'Personal Copy'], ['document_type' => DOCS[3], 'purpose' => 'For Titling']];
[$code, $res, $draftRef] = $apply($draftItems, true, [], ['draft_token' => $draftCode], $draftClient);
$check('submitting from a draft copies the saved ID (front and back) into both documents', $code === 200
    && $count("SELECT COUNT(*) FROM request_documents rd JOIN requests r ON r.id=rd.request_id WHERE r.application_id=(SELECT id FROM request_applications WHERE reference_no=" . $pdo->quote($draftRef) . ") AND rd.file_status='ok'") === 4, [$code, $res]);
[$code, $res, $againRef] = $apply($draftItems, true, [], ['draft_token' => $draftCode], $draftClient);
$check('submitting the same draft again returns the same application reference and saves nothing new', $code === 200 && $againRef === $draftRef
    && $count('SELECT COUNT(*) FROM request_applications WHERE reference_no = ' . $pdo->quote($draftRef)) === 1
    && $count('SELECT COUNT(*) FROM requests WHERE application_id=(SELECT id FROM request_applications WHERE reference_no=' . $pdo->quote($draftRef) . ')') === 2, [$code, $res]);
$check('the repeated submission adds no AI job (one per document)', $count('SELECT COUNT(*) FROM ai_verification_jobs j JOIN requests r ON r.id=j.request_id WHERE r.application_id=(SELECT id FROM request_applications WHERE reference_no=' . $pdo->quote($draftRef) . ')') === 2);

// ================= Staff process each document separately =================
$staffA = $signIn('test.staff');
$staffB = $signIn('other.staff');
$staffPage = $pageData($staffA);
$mine = array_values(array_filter($staffPage['docRequests'] ?? [], static fn (array $r): bool => str_starts_with((string) $r['id'], $appRef . '-')));
usort($mine, static fn ($a, $b) => strcmp($a['id'], $b['id']));
$check('Staff see each document as its own request, labelled with its application and place in it', count($mine) === 3
    && array_map(static fn ($r) => [$r['applicationRef'], $r['itemNo'], $r['itemCount'], $r['docType']], $mine) === [[$appRef, 1, 3, DOCS[0]], [$appRef, 2, 3, DOCS[1]], [$appRef, 3, 3, DOCS[2]]], $mine);
$legacyStaff = array_values(array_filter($staffPage['docRequests'] ?? [], static fn (array $r): bool => $r['id'] === $legacyRef))[0] ?? [];
$check('an older single-document request has no application on the Staff page', array_key_exists('applicationRef', $legacyStaff) && $legacyStaff['applicationRef'] === null
    && $legacyStaff['itemNo'] === null && $legacyStaff['itemCount'] === null, $legacyStaff);
$file1 = (int) ($mine[0]['files'][0]['docId'] ?? 0);
$file2 = (int) ($mine[1]['files'][0]['docId'] ?? 0);
// Each document's back side of the ID: its own original, listed after the front.
$back1 = (int) ($mine[0]['files'][1]['docId'] ?? 0);
$back2 = (int) ($mine[1]['files'][1]['docId'] ?? 0);
$check('Staff see each document\'s ID front and back as two separate originals', ($mine[0]['files'][1]['key'] ?? '') === 'ownerIdBack' && $back1 > 0 && $back2 > 0
    && $back1 !== $file1 && $back2 !== $file2, $mine[0]['files'] ?? []);
[$c1, $b1] = $staffA->http('/request-file.php?doc=' . $file1 . '&ref=' . rawurlencode($childRef($appRef, 1)));
[$c2] = $staffA->http('/request-file.php?doc=' . $file2 . '&ref=' . rawurlencode($childRef($appRef, 2)));
[$cx] = $staffA->http('/request-file.php?doc=' . $file2 . '&ref=' . rawurlencode($childRef($appRef, 1)));
$check('each document\'s own file opens with its item reference; another document\'s file does not', $c1 === 200 && $b1 !== '' && $c2 === 200 && $cx === 404 && $file1 !== $file2, [$c1, $c2, $cx]);
[$codeA] = $staffA->json('start_processing', ['id' => $childRef($appRef, 1)]);
[$codeB] = $staffB->json('start_processing', ['id' => $childRef($appRef, 2)]);
$check('two Staff members can each handle a different document of the same application', $codeA === 200 && $codeB === 200
    && (int) $requestRow($childRef($appRef, 1))['processing_staff_id'] !== (int) $requestRow($childRef($appRef, 2))['processing_staff_id']
    && $requestRow($childRef($appRef, 3))['processing_staff_id'] === null, [$codeA, $codeB]);
[$code] = $staffA->json('update_request_status', ['id' => $childRef($appRef, 1), 'kind' => 'doc', 'status' => 'Out for Release']);
$check('completing one document leaves the others unchanged', $code === 200 && $requestRow($childRef($appRef, 1))['status'] === 'Out for Release'
    && $requestRow($childRef($appRef, 2))['status'] === 'Processing' && $requestRow($childRef($appRef, 3))['status'] === 'Received', $code);

// ================= Mark as reviewed: each document's own original =================
$pdo->exec("UPDATE ai_verification_jobs SET status = 'completed' WHERE request_id IN (" . implode(',', $childIds) . ')');
$staffB->http('/request-file.php?doc=' . $file1 . '&ref=' . rawurlencode($childRef($appRef, 1)));
[$codeR1front] = $staffB->json('ai_verification_reviewed', ['reference' => $childRef($appRef, 1)]);
$check('the ID\'s back side is an original too: with only the front opened, Mark as reviewed is refused', $codeR1front === 409, $codeR1front);
$staffB->http('/request-file.php?doc=' . $back1 . '&ref=' . rawurlencode($childRef($appRef, 1)));
[$codeR1] = $staffB->json('ai_verification_reviewed', ['reference' => $childRef($appRef, 1)]);
[$codeR2, $resR2] = $staffB->json('ai_verification_reviewed', ['reference' => $childRef($appRef, 2)]);
$check('opening document 1\'s original allows Mark as reviewed for document 1 only', $codeR1 === 200 && $codeR2 === 409
    && str_contains((string) ($resR2['error'] ?? ''), 'Open every original document'), [$codeR1, $codeR2, $resR2]);
$staffB->http('/request-file.php?doc=' . $file2 . '&ref=' . rawurlencode($childRef($appRef, 2)) . '&preview=1');
[$codeR2b] = $staffB->json('ai_verification_reviewed', ['reference' => $childRef($appRef, 2)]);
$staffB->http('/request-file.php?doc=' . $file2 . '&ref=' . rawurlencode($childRef($appRef, 2)));
$staffB->http('/request-file.php?doc=' . $back2 . '&ref=' . rawurlencode($childRef($appRef, 2)));
[$codeR2c] = $staffB->json('ai_verification_reviewed', ['reference' => $childRef($appRef, 2)]);
$check('document 2 needs its own copy opened (an inline preview does not count); then it can be reviewed', $codeR2b === 409 && $codeR2c === 200, [$codeR2b, $codeR2c]);
$check('reviewing is recorded per document', $count("SELECT COUNT(*) FROM ai_verification_jobs WHERE staff_review_status = 'reviewed' AND request_id IN (" . implode(',', $childIds) . ')') === 2);

// ================= Staff Send Updates on one document =================
[$code, $owner] = $staffB->json('lookup_request_owner', ['reference' => $childRef($appRef, 2)]);
$check('Send Updates finds one document by its own reference', $code === 200 && ($owner['data']['submittedName'] ?? '') === 'Rosa Fictional', [$code, $owner]);
$beforeSends = [$count("SELECT COUNT(*) FROM notifications WHERE type = 'staff_message' AND request_id = " . $childIds[0]), $count("SELECT COUNT(*) FROM notifications WHERE type = 'staff_message' AND request_id = " . $childIds[2])];
[$code, $sent] = $staffB->json('send_notification', ['request_ref' => $childRef($appRef, 2), 'message' => 'Fictional update about your second document.', 'category' => 'Request Status Update',
    'channel_choice' => '1', 'send_sms' => '1']);
$sentRows = $pdo->query("SELECT request_id, channel, delivery_status FROM notifications WHERE type = 'staff_message' AND message = 'Fictional update about your second document.'")->fetchAll();
$check('a Staff update for document 2 is recorded on document 2 only (SMS stub, nothing leaves the computer)', $code === 200 && count($sentRows) === 1
    && (int) $sentRows[0]['request_id'] === $childIds[1] && $sentRows[0]['channel'] === 'sms'
    && [$count("SELECT COUNT(*) FROM notifications WHERE type = 'staff_message' AND request_id = " . $childIds[0]), $count("SELECT COUNT(*) FROM notifications WHERE type = 'staff_message' AND request_id = " . $childIds[2])] === $beforeSends, [$code, $sent, $sentRows]);
[$code] = $staffB->json('send_notification', ['request_ref' => $childRef($appRef, 2), 'message' => 'Fictional update about your second document.', 'category' => 'Request Status Update',
    'channel_choice' => '1', 'send_sms' => '1']);
$check('the existing duplicate guard still refuses the same message to document 2', $code === 409, $code);

// ================= Tracking =================
[$code, $t] = $appClient->track($appRef);
$check('tracking the application lists each document with its own status and reference', $code === 200 && ($t['isApplication'] ?? false) === true && ($t['id'] ?? '') === $appRef
    && array_column($t['items'] ?? [], 'id') === [$childRef($appRef, 1), $childRef($appRef, 2), $childRef($appRef, 3)]
    && array_column($t['items'] ?? [], 'status') === ['Out for Release', 'Processing', 'Received'] && array_column($t['items'] ?? [], 'docType') === [DOCS[0], DOCS[1], DOCS[2]], $t);
$check('each tracked document has its own history times', isset($t['items'][0]['timestamps']['Out for Release'], $t['items'][1]['timestamps']['Processing'])
    && !isset($t['items'][2]['timestamps']['Processing']), $t['items'] ?? null);
$check('the submitting browser sees the full name; it is the same for every document', ($t['applicant'] ?? '') === 'Rosa Fictional' && ($t['applicantInitialsOnly'] ?? true) === false
    && array_unique(array_column($t['items'] ?? [], 'applicant')) === ['Rosa Fictional'], $t);
[, $other] = $browser()->track(strtolower($appRef));
$check('another browser (lowercase reference too) sees initials only, for the application and every document', ($other['id'] ?? '') === $appRef && ($other['applicant'] ?? '') === 'R. F.'
    && ($other['applicantInitialsOnly'] ?? false) === true && array_unique(array_column($other['items'] ?? [], 'applicant')) === ['R. F.'], $other);
[, $item] = $browser()->track($childRef($appRef, 2));
$check('tracking one document\'s reference shows that document and its application', ($item['id'] ?? '') === $childRef($appRef, 2) && ($item['status'] ?? '') === 'Processing'
    && ($item['applicationRef'] ?? '') === $appRef && ($item['itemNo'] ?? 0) === 2 && ($item['itemCount'] ?? 0) === 3 && ($item['applicant'] ?? '') === 'R. F.', $item);
[$code, $apiBody] = $browser()->http('/api.php?action=track&ref=' . rawurlencode($appRef));
$apiTrack = (array) (json_decode($apiBody, true)['request'] ?? []);
$check('the tracking API returns the application with each document and its history, without full names', $code === 200 && ($apiTrack['is_application'] ?? false) === true
    && count($apiTrack['items'] ?? []) === 3 && isset($apiTrack['items'][0]['history']) && !str_contains($apiBody, 'Fictional') && !str_contains($apiBody, 'first_name'), [$code, substr($apiBody, 0, 300)]);
[, $missing] = $browser()->track($appRef . '-9');
$check('an item number that does not exist finds nothing', $missing === [], $missing);

// ================= Privacy for Admin and Head =================
foreach (['Head' => 'test.head', 'Admin' => 'test.admin'] as $role => $username) {
    $session = $signIn($username);
    $list = $pageData($session)['docRequests'] ?? [];
    $check("{$role} still receive no applicant details or files (application labels only)", $list !== []
        && array_filter($list, static fn (array $r): bool => isset($r['details']) || isset($r['files']) || isset($r['ai'])) === [], array_keys($list[0] ?? []));
    [$code] = $session->http('/request-file.php?doc=' . $file1 . '&ref=' . rawurlencode($childRef($appRef, 1)));
    $check("{$role} cannot open an application's files", $code === 403, $code);
}
// ================= Head report: applications and individual document requests =================
$head = $signIn('test.head');
[$code, $body] = $head->http('/api.php?action=head_monthly_report');
$cards = (array) (json_decode($body, true)['data']['cards'] ?? []);
$expectDocs = $count("SELECT COUNT(*) FROM requests WHERE flow = 'docreq'");
$expectApps = $count("SELECT COUNT(*) FROM requests WHERE flow = 'docreq' AND application_id IS NULL") + $count('SELECT COUNT(*) FROM request_applications');
$check('the Head report counts applications and individual document requests apart (counted independently from the application table)', $code === 200
    && ($cards['documents'] ?? -1) === $expectDocs && ($cards['documentApplications'] ?? -1) === $expectApps && $expectApps < $expectDocs
    && ($cards['applications'] ?? -1) === $expectApps + (int) ($cards['landTransfers'] ?? 0) && ($cards['total'] ?? -1) === $expectDocs + (int) ($cards['landTransfers'] ?? 0), [$cards, $expectDocs, $expectApps]);
$reports = $pageData($head, 'reports')['reports'] ?? [];
$docReport = array_values(array_filter($reports, static fn ($r) => ($r['id'] ?? '') === 'RPT-DOC'))[0]['stats'] ?? [];
$check('the summary report shows applications next to individual document requests', ($docReport['applications'] ?? -1) === $expectApps && ($docReport['total'] ?? -1) === $expectDocs, $docReport);
$staffRows = (array) (json_decode($body, true)['data']['staffPerformance'] ?? []);
$check('Staff performance is unchanged: no application figure was added', $staffRows !== [] && array_filter($staffRows, static fn ($r) => array_key_exists('applications', $r) || array_key_exists('documentApplications', $r)) === []
    && array_keys($staffRows[0]) === ['staffId', 'name', 'documents', 'landTransfers', 'completed', 'timedCount', 'avgProcessingSeconds', 'fastestSeconds', 'slowestSeconds'], $staffRows[0] ?? null);

// ================= Application counting: request_applications by its own creation date =================
$reportFor = static function (int $month, int $year) use ($head): array {
    [, $body] = $head->http('/api.php?action=head_monthly_report&month=' . $month . '&year=' . $year);
    return (array) (json_decode($body, true)['data'] ?? []);
};
$appIdOf = static fn (string $reference): int => (int) $pdo->query('SELECT id FROM request_applications WHERE reference_no = ' . $pdo->quote($reference))->fetchColumn();
// (a) A two-document application created at 23:59:59 on 31 October whose second document was saved at
//     00:00:00 on 1 November (a past year, so no other test data falls in these months).
[, , $edgeRef] = $apply($items(2));
$pdo->exec("UPDATE request_applications SET created_at = '2025-10-31 23:59:59' WHERE id = " . $appIdOf($edgeRef));
$pdo->exec("UPDATE requests SET created_at = '2025-10-31 23:59:59' WHERE reference_no = " . $pdo->quote($childRef($edgeRef, 1)));
$pdo->exec("UPDATE requests SET created_at = '2025-11-01 00:00:00' WHERE reference_no = " . $pdo->quote($childRef($edgeRef, 2)));
// (b) An older single-document request (no application record) in October.
[, , $oldRef] = $apply(null);
$pdo->exec("UPDATE requests SET created_at = '2025-10-15 08:00:00' WHERE reference_no = " . $pdo->quote($oldRef));
// (c) A three-document application in November whose FIRST document was later removed from the database.
[, , $gapRef] = $apply($items(3));
$pdo->exec("UPDATE request_applications SET created_at = '2025-11-10 09:00:00' WHERE id = " . $appIdOf($gapRef));
$pdo->exec("UPDATE requests SET created_at = '2025-11-10 09:00:00' WHERE application_id = " . $appIdOf($gapRef));
$firstId = (int) $requestRow($childRef($gapRef, 1))['id'];
$pdo->exec("DELETE FROM ai_verification_jobs WHERE request_id = {$firstId}");
$pdo->exec("DELETE FROM document_validation WHERE request_document_id IN (SELECT id FROM request_documents WHERE request_id = {$firstId})");
$pdo->exec("DELETE FROM request_documents WHERE request_id = {$firstId}");
$pdo->exec("DELETE FROM requests WHERE id = {$firstId}");
$oct = $reportFor(10, 2025);
$nov = $reportFor(11, 2025);
$check('October: the boundary application counts once, by its own creation date, with the older single request as a second application', ($oct['cards']['documentApplications'] ?? -1) === 2
    && ($oct['cards']['documents'] ?? -1) === 2 && ($oct['cards']['applications'] ?? -1) === 2, $oct['cards'] ?? null);
$check('November: the boundary application is not counted again; only its second document is (individual request)', ($nov['cards']['documents'] ?? -1) === 3
    && ($nov['cards']['documentApplications'] ?? -1) === 1, $nov['cards'] ?? null);
$check('an application whose first document is gone is still counted (from request_applications), and its remaining documents are counted individually',
    ($nov['cards']['documentApplications'] ?? -1) === 1 && $count('SELECT COUNT(*) FROM requests WHERE application_id=' . $appIdOf($gapRef)) === 2, $nov['cards'] ?? null);
$check('the previous-month figures use the same definition (November\'s "previous" = October)', ($nov['previousCards'] ?? null) === ($oct['cards'] ?? []), [$nov['previousCards'] ?? null, $oct['cards'] ?? null]);
$daySum = static fn (array $r): int => array_sum(array_column($r['daily'] ?? [], 'documents'));
$monthOf = static fn (array $r, int $m): int => (int) (array_values(array_filter($r['monthly'] ?? [], static fn ($x) => (int) $x['month'] === $m))[0]['documents'] ?? -1);
$check('daily and monthly figures count individual document requests, matching the cards', $daySum($oct) === 2 && $daySum($nov) === 3
    && $monthOf($oct, 10) === 2 && $monthOf($oct, 11) === 3, [$oct['daily'] ?? null, $oct['monthly'] ?? null]);

// ================= Report details: not limited to the latest 500 requests =================
$insertOld = $pdo->prepare("INSERT INTO requests (reference_no, flow, first_name, last_name, contact_number, created_at) VALUES (?, 'docreq', 'Window', 'Filler', '+639170000000', '2027-01-01 00:00:00')");
for ($i = 0; $i < 510; $i++) {
    $insertOld->execute([sprintf('DR-2027-W%07d', $i)]);
}
$headPage = $pageData($head, 'reports');
$loaded = array_column(array_merge($headPage['docRequests'] ?? [], $headPage['landRequests'] ?? []), 'id');
$detail = array_values(array_filter($headPage['reports'] ?? [], static fn ($r) => ($r['id'] ?? '') === 'RPT-DOC'))[0]['stats'] ?? [];
$allDocs = $count("SELECT COUNT(*) FROM requests WHERE flow = 'docreq'");
$allApps = $count("SELECT COUNT(*) FROM requests WHERE flow = 'docreq' AND application_id IS NULL") + $count("SELECT COUNT(*) FROM request_applications WHERE flow = 'docreq'");
$check('the page loads only the latest 500 requests, so the applications\' documents are outside it', count($loaded) === 500 && !in_array($childRef($appRef, 1), $loaded, true)
    && !in_array($childRef($edgeRef, 1), $loaded, true), count($loaded));
$check('report details still count every application and every individual document request in the database', ($detail['applications'] ?? -1) === $allApps
    && ($detail['total'] ?? -1) === $allDocs && $allDocs > 500 && $allApps < $allDocs, [$detail['applications'] ?? null, $detail['total'] ?? null, $allApps, $allDocs]);

// ================= Rollback protection =================
$beforeRollback = [$count('SELECT COUNT(*) FROM requests'), $count('SELECT COUNT(*) FROM request_applications'), $count('SELECT COUNT(*) FROM request_documents')];
$refused = '';
try {
    $rollback();
} catch (PDOException $e) {
    $refused = $e->getMessage();
}
$check('the rollback refuses while applications exist, with an error that names the reason', str_contains($refused, 'rollback_refused__multi_document_applications_exist'), $refused);
$check('the refused rollback changed nothing (table, columns, requests, applications and files kept)', [$count('SELECT COUNT(*) FROM requests'), $count('SELECT COUNT(*) FROM request_applications'), $count('SELECT COUNT(*) FROM request_documents')] === $beforeRollback
    && $count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME IN ('application_id', 'item_no')") === 2
    && $count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'request_applications'") === 1);
$releaseRollback = (string) file_get_contents($project . '/smart-assess-app/database/rollbacks/20261125_mixed_applications_rollback.sql');
$check('the Released rollback is guarded by Released records and never creates or selects another database',
    str_contains($releaseRollback, "status = 'Released'") && !preg_match('/\\b(?:CREATE|DROP)\\s+DATABASE\\b/i', $releaseRollback));

$sends = is_file($sendLog) ? (string) file_get_contents($sendLog) : '';
$check('the only send was the one Staff update, to the SMS stub; no real SMS or email', substr_count($sends, 'stub-sms') === 1 && !preg_match('/^(sms|email)$/m', $sends), $sends);

// ================= Report =================
$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
