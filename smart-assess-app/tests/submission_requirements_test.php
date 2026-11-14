<?php
declare(strict_types=1);

/*
 * The server decides which documents a request needs, which file types each
 * may be, and the size limit, for submissions and saved drafts alike:
 *  - the browser's "documents" list is ignored (it cannot drop, add or relabel
 *    a requirement); files for documents a request does not need are refused;
 *  - IDs and the authorization letter: JPG/PNG only; other documents also
 *    WEBP/PDF (DocumentRequirements, the same rules the public form shows);
 *  - every file must be strictly smaller than 5 MiB (the OCR limit too).
 *
 * Runs the real web/ code over HTTP against a copy in a temporary folder, with
 * the real terms, drafts and AI migrations. Mail/SMS senders are stubs. All
 * people and files are made up. Uses ONLY a database whose name ends in "_test".
 *
 *   SMART_ASSESS_TEST_DB=smart_ai_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=... SMART_ASSESS_DB_PASS='...' php tests/submission_requirements_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dbName = (string) getenv('SMART_ASSESS_TEST_DB');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $dbName) || !extension_loaded('gd')) {
    fwrite(STDERR, "Refusing to run: set SMART_ASSESS_TEST_DB to a database whose name ends in _test (GD is also required).\n");
    exit(2);
}
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = (string) getenv('SMART_ASSESS_DB_PASS');
$project = dirname(__DIR__, 2);
require_once $project . '/smart-assess-app/app/services/DocumentRequirements.php';

$work = sys_get_temp_dir() . '/sa-submission-req-' . bin2hex(random_bytes(4));
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
file_put_contents($work . '/smart-assess-app/gmail-mailer.php', "<?php\nreturn static function (array \$d): void { throw new RuntimeException('[mailer] disabled'); };\n");
file_put_contents($work . '/smart-assess-app/iprog-sms.php', "<?php\nfinal class IprogSmsService { public static function fromEnvironment(): self { throw new RuntimeException('disabled'); } }\n");

// ---- Test database ----
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int) $dbPort, $dbName), $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['ai_verification_jobs', 'terms_acceptances', 'request_draft_files', 'request_drafts', 'request_draft_rate', 'roles', 'users', 'clients', 'requests',
    'request_documents', 'document_validation', 'request_status_log', 'audit_log', 'announcements', 'settings', 'notifications', 'document_types', 'transfer_types'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach ([
    "CREATE TABLE roles (id INT PRIMARY KEY, name VARCHAR(40) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, role_id INT NOT NULL, name VARCHAR(150) NOT NULL, username VARCHAR(80) NOT NULL, password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(150) NULL, contact_number VARCHAR(20) NULL, position_title VARCHAR(80) NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active',
        archived_at DATETIME NULL, deleted_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE clients (id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(150) NOT NULL,
        contact_number VARCHAR(20) NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active') ENGINE=InnoDB",
    "CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, reference_no VARCHAR(20) NOT NULL, flow VARCHAR(20) NOT NULL,
        first_name VARCHAR(80) NOT NULL, middle_name VARCHAR(80) NULL, last_name VARCHAR(80) NOT NULL, contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL,
        address_line VARCHAR(255) NOT NULL DEFAULT '', province VARCHAR(80) NULL, city VARCHAR(80) NULL, zip_code VARCHAR(10) NOT NULL DEFAULT '',
        document_type VARCHAR(120) NULL, transfer_type VARCHAR(120) NULL, purpose VARCHAR(200) NULL, arp_number VARCHAR(60) NOT NULL DEFAULT '',
        property_address VARCHAR(255) NULL, barangay VARCHAR(100) NOT NULL DEFAULT '', is_owner TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'Received', requirement_complete TINYINT(1) NOT NULL DEFAULT 0, advisory TEXT NULL,
        processing_started_at DATETIME NULL, processing_completed_at DATETIME NULL, processing_duration_seconds INT NULL,
        processing_staff_id INT NULL, processing_staff_name VARCHAR(150) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE request_documents (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, doc_key VARCHAR(40) NOT NULL, label VARCHAR(150) NOT NULL,
        original_name VARCHAR(255) NULL, stored_path VARCHAR(255) NULL, mime_type VARCHAR(80) NULL, file_size INT NULL, file_status VARCHAR(20) NOT NULL DEFAULT 'missing') ENGINE=InnoDB",
    "CREATE TABLE document_validation (id INT AUTO_INCREMENT PRIMARY KEY, request_document_id INT NOT NULL, validation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        remarks VARCHAR(255) NULL, validated_by INT NULL, validated_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE request_status_log (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, status VARCHAR(30) NOT NULL, actor VARCHAR(10) NOT NULL DEFAULT 'staff',
        sms_body TEXT NOT NULL, sms_category VARCHAR(60) NULL, delivery_status VARCHAR(20) NULL, sms_sent_by_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE audit_log (id INT AUTO_INCREMENT PRIMARY KEY, actor_type VARCHAR(10) NOT NULL, actor_id INT NULL, actor_name VARCHAR(150) NOT NULL,
        action VARCHAR(120) NOT NULL, target VARCHAR(150) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE announcements (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, author VARCHAR(150) NOT NULL DEFAULT '',
        audience VARCHAR(20) NOT NULL DEFAULT 'both', start_date DATE NULL, end_date DATE NULL, image_url VARCHAR(255) NULL, status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        scheduled_at DATETIME NULL, published_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB",
    "CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, request_id INT NULL, type VARCHAR(40) NOT NULL, message TEXT NOT NULL,
        channel VARCHAR(10) NOT NULL DEFAULT 'system', delivery_status VARCHAR(10) NOT NULL DEFAULT 'sent', read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE document_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB",
    "CREATE TABLE transfer_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description TEXT NULL, active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB",
] as $statement) {
    $pdo->exec($statement);
}
foreach (['20261015_terms_acceptances.sql', '20261015_request_drafts.sql', '20261111_ai_document_verification.sql'] as $migration) {
    $migrationFile = $project . '/smart-assess-app/database/migrations/' . $migration;
    if (!is_file($migrationFile)) {
        continue;   // the AI migration exists only once AI verification is added
    }
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($migrationFile));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

// ---- Server (6 MB upload limit, so the application's own 5 MiB rule is what is tested) ----
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=6M', '-d', 'post_max_size=20M', '-S', '127.0.0.1:' . $port, '-t', $work . '/smart-assess-app/web'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work . '/smart-assess-app/web',
    ['PATH' => '/usr/bin:/bin', 'DB_HOST' => $dbHost, 'DB_PORT' => $dbPort, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'SMART_ASSESS_DB_PASS' => $dbPass,
        'SMART_ASSESS_GMAIL_DIR' => $work . '/empty-gmail']);
register_shutdown_function(static function () use ($server, $work): void {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($work));
});
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}
$base = 'http://127.0.0.1:' . $port;
$jar = tempnam(sys_get_temp_dir(), 'sa-cookies');
$http = static function (string $path, mixed $post = null, array $headers = []) use ($base, $jar): array {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
    }
    $body = (string) curl_exec($curl);
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
};
preg_match('/SMART_ASSESS_CONFIG=(\{.*?\});/', $http('/index.php')[1], $m);
$csrf = (string) (json_decode($m[1] ?? '{}', true)['csrf'] ?? '');
$json = static fn (string $action, array $data) => $http('/api.php?action=' . $action, (string) json_encode(['action' => $action, 'csrf' => $csrf] + $data), ['Content-Type: application/json']);

// ---- Synthetic files ----
$fixtures = sys_get_temp_dir() . '/sa-submission-files-' . bin2hex(random_bytes(4));
mkdir($fixtures, 0700);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($fixtures)));
$image = imagecreatetruecolor(30, 20);
imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
imagepng($image, $fixtures . '/id.png');
imagewebp($image, $fixtures . '/id.webp');
file_put_contents($fixtures . '/doc.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$sized = static function (int $bytes) use ($fixtures): string {
    $path = $fixtures . '/sized-' . $bytes . '.png';
    $png = (string) file_get_contents($fixtures . '/id.png');
    file_put_contents($path, $png . str_repeat("\0", $bytes - strlen($png)));
    return $path;
};
$file = static fn (string $path, string $mime): CURLFile => new CURLFile($path, $mime, basename($path));

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};
$submit = static function (string $flow, bool $isOwner, array $files, array $extra = []) use ($json, $http, $csrf, $project): array {
    $terms = json_decode($json('terms_accept', ['flow' => $flow, 'agreed' => true, 'terms_version' => (preg_match("/SA_TERMS_VERSION = '([^']+)'/", (string) file_get_contents($project . '/smart-assess-app/web/terms-acceptance.php'), $termsMatch) ? $termsMatch[1] : '')])[1], true);
    $payload = $extra + ['action' => 'submit_request', 'csrf' => $csrf, 'flow' => $flow, 'terms_token' => $terms['data']['terms_token'] ?? '',
        'first_name' => 'Juana', 'last_name' => 'Testcase', 'contact_number' => '+639170002222', 'email' => 'juana.test@example.invalid', 'address_line' => '1 Test St',
        'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '4202', 'purpose' => 'Test', 'arp_number' => '14-0007-00002', 'property_address' => 'Anilao',
        'barangay' => 'Anilao', 'is_owner' => $isOwner, 'document_type' => 'Certified True Copy of Tax Declaration', 'transfer_type' => 'Sale'];
    $post = ['payload' => json_encode($payload)];
    foreach ($files as $key => $curlFile) {
        $post['file_' . $key] = $curlFile;
    }
    [$status, $body] = $http('/api.php?action=submit_request', $post);
    return [$status, (string) (json_decode($body, true)['data']['reference_no'] ?? ''), $body];
};
$documentsOf = static function (string $reference) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT d.doc_key, d.label, d.file_status FROM request_documents d JOIN requests r ON r.id = d.request_id WHERE r.reference_no = ? ORDER BY d.id');
    $stmt->execute([$reference]);
    return $stmt->fetchAll();
};
$requestCount = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn();

// ================= Shared rules =================
$check('rules: the size limit is strictly smaller than 5 MiB', DocumentRequirements::MAX_FILE_BYTES === 5 * 1024 * 1024
    && DocumentRequirements::acceptsSize(5 * 1024 * 1024 - 1) && !DocumentRequirements::acceptsSize(5 * 1024 * 1024) && !DocumentRequirements::acceptsSize(0));
$check('rules: IDs and the authorization letter are JPG/PNG only', DocumentRequirements::allowedMimeTypes('ownerId') === ['image/jpeg', 'image/png']
    && DocumentRequirements::allowedMimeTypes('authLetter') === ['image/jpeg', 'image/png']);
$check('rules: other documents also allow WEBP and PDF; unknown keys allow nothing', in_array('application/pdf', DocumentRequirements::allowedMimeTypes('taxClearance'), true)
    && DocumentRequirements::allowedMimeTypes('passport') === []);
$check('rules: the requirement list matches evaluate()', array_keys(DocumentRequirements::requirementsFor('landtransfer', false))
    === array_column((new DocumentRequirements())->evaluate('landtransfer', false, [])['items'], 'key'));

// ================= 1. The server decides the required documents =================
[$status, $ref, $body] = $submit('docreq', true, ['ownerId' => $file($fixtures . '/id.png', 'image/png')],
    ['documents' => ['ownerId' => 'Anything I like', 'bonusDoc' => 'Not a real requirement']]);
$rows = $documentsOf($ref);
$check('a submission records only the server\'s requirements with the server\'s labels', $status === 200
    && $rows === [['doc_key' => 'ownerId', 'label' => 'Valid Government-Issued ID', 'file_status' => 'ok']], [$status, $rows, $body]);
[$status, $ref] = $submit('landtransfer', true, ['ownerId' => $file($fixtures . '/id.png', 'image/png')], ['documents' => ['ownerId' => 'Owner ID']]);
$rows = $documentsOf($ref);
$complete = (int) $pdo->query("SELECT requirement_complete FROM requests WHERE reference_no = '{$ref}'")->fetchColumn();
$check('the browser cannot drop requirements: all six land-transfer documents are recorded, five missing', $status === 200
    && array_column($rows, 'doc_key') === array_keys(DocumentRequirements::requirementsFor('landtransfer', true))
    && count(array_filter($rows, static fn (array $r): bool => $r['file_status'] === 'missing')) === 5 && $complete === 0, $rows);
[$status, $ref] = $submit('docreq', false, ['ownerId' => $file($fixtures . '/id.png', 'image/png'), 'requesterId' => $file($fixtures . '/id.png', 'image/png'),
    'authLetter' => $file($fixtures . '/id.png', 'image/png')], ['documents' => []]);
$check('a representative\'s complete set is accepted and marked complete', $status === 200 && count($documentsOf($ref)) === 3
    && (int) $pdo->query("SELECT requirement_complete FROM requests WHERE reference_no = '{$ref}'")->fetchColumn() === 1);
$before = $requestCount();
[$status, , $body] = $submit('docreq', true, ['ownerId' => $file($fixtures . '/id.png', 'image/png'), 'passport' => $file($fixtures . '/id.png', 'image/png')]);
$check('a file for a document that is not a requirement is refused, and nothing is saved', $status === 422 && $requestCount() === $before, [$status, $body]);
[$status] = $submit('docreq', true, ['requesterId' => $file($fixtures . '/id.png', 'image/png')]);
$check('a representative-only document on an owner\'s request is refused', $status === 422 && $requestCount() === $before);

// ================= 2. One size limit =================
[$status, , $body] = $submit('docreq', true, ['ownerId' => $file($sized(5 * 1024 * 1024), 'image/png')]);
$check('an upload of exactly 5 MiB is refused with a clear message', $status === 422 && str_contains($body, 'smaller than 5 MB') && $requestCount() === $before, [$status, $body]);
[$status, $ref] = $submit('docreq', true, ['ownerId' => $file($sized(5 * 1024 * 1024 - 1), 'image/png')]);
$check('an upload one byte under 5 MiB is accepted', $status === 200 && $ref !== '', $status);

// ================= 3. File types per document =================
$before = $requestCount();
[$status, , $body] = $submit('docreq', true, ['ownerId' => $file($fixtures . '/doc.pdf', 'application/pdf')]);
$check('a PDF as an ID is refused', $status === 422 && str_contains($body, 'JPG or PNG') && $requestCount() === $before, [$status, $body]);
[$status] = $submit('docreq', true, ['ownerId' => $file($fixtures . '/id.webp', 'image/webp')]);
$check('a WEBP as an ID is refused', $status === 422 && $requestCount() === $before);
[$status] = $submit('docreq', false, ['ownerId' => $file($fixtures . '/id.png', 'image/png'), 'requesterId' => $file($fixtures . '/id.png', 'image/png'),
    'authLetter' => $file($fixtures . '/doc.pdf', 'application/pdf')]);
$check('a PDF authorization letter is refused', $status === 422 && $requestCount() === $before);
[$status, $ref] = $submit('landtransfer', true, ['ctcTdOrTitle' => $file($fixtures . '/doc.pdf', 'application/pdf'), 'vicinityMap' => $file($fixtures . '/id.webp', 'image/webp'),
    'ownerId' => $file($fixtures . '/id.png', 'image/png')]);
$check('PDF and WEBP supporting documents are accepted', $status === 200 && $ref !== '', $status);
copy($fixtures . '/doc.pdf', $fixtures . '/disguised.png');
[$status] = $submit('docreq', true, ['ownerId' => $file($fixtures . '/disguised.png', 'image/png')]);
$check('a PDF renamed to .png is refused by its real content', $status === 422);

// ================= Saved drafts follow the same rules =================
[, $body] = $json('draft_save', ['flow' => 'docreq', 'step' => 1, 'fields' => ['firstName' => 'Juana']]);
$code = str_replace('-', '', (string) (json_decode($body, true)['data']['code'] ?? ''));
$upload = static fn (string $key, CURLFile $f) => $http('/api.php?action=draft_upload', ['payload' => json_encode(['action' => 'draft_upload', 'csrf' => $csrf, 'draft_token' => $code, 'doc_key' => $key]), 'file' => $f]);
$check('a draft was started', strlen($code) === 20, $body);
[$status] = $upload('ownerId', $file($fixtures . '/doc.pdf', 'application/pdf'));
$check('draft: a PDF ID is refused', $status === 422, $status);
[$status] = $upload('ownerId', $file($sized(5 * 1024 * 1024), 'image/png'));
$check('draft: an exactly 5 MiB file is refused', $status === 422, $status);
[$status] = $upload('ctcTdOrTitle', $file($fixtures . '/doc.pdf', 'application/pdf'));
$check('draft: a PDF supporting document is accepted', $status === 200, $status);
[$status] = $upload('ownerId', $file($fixtures . '/id.png', 'image/png'));
$check('draft: a PNG ID is accepted', $status === 200, $status);

$draftId = (int) $pdo->query('SELECT id FROM request_drafts ORDER BY id DESC LIMIT 1')->fetchColumn();
$plant = static function (string $key, string $source, string $mime) use ($pdo, $draftId, $work): string {
    $name = 'd' . bin2hex(random_bytes(8)) . '.' . pathinfo($source, PATHINFO_EXTENSION);
    copy($source, $work . '/smart-assess-app/private_uploads/drafts/' . $name);
    $pdo->prepare('INSERT INTO request_draft_files (draft_id, doc_key, original_name, stored_path, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE stored_path = VALUES(stored_path), mime_type = VALUES(mime_type), file_size = VALUES(file_size)')
        ->execute([$draftId, $key, 'old.' . pathinfo($source, PATHINFO_EXTENSION), 'private_uploads/drafts/' . $name, $mime, filesize($source)]);
    return $work . '/smart-assess-app/private_uploads/drafts/' . $name;
};
$oldPdfId = $plant('ownerId', $fixtures . '/doc.pdf', 'application/pdf');   // saved before these rules existed
$before = $requestCount();
[$status, , $body] = $submit('docreq', true, [], ['draft_token' => $code]);
$check('submitting a draft holding an old PDF ID is refused with a request to upload it again; the draft keeps its file', $status === 422
    && str_contains($body, 'again') && $requestCount() === $before && is_file($oldPdfId), [$status, $body]);
$ownerDraft = $plant('ownerId', $fixtures . '/id.png', 'image/png');
$extra = $plant('requesterId', $fixtures . '/id.png', 'image/png');   // not needed by an owner's request
// Step 12 (F7): a failure after the draft's files were copied into the request leaves the draft intact.
$requestFiles = static fn (): array => glob($work . '/smart-assess-app/private_uploads/requests/*') ?: [];
$filesBefore = $requestFiles();
$before = $requestCount();
$pdo->exec("CREATE TRIGGER sa_test_fail_documents BEFORE INSERT ON request_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test: documents unavailable'");
[$status, , $body] = $submit('docreq', true, [], ['draft_token' => $code]);
$pdo->exec('DROP TRIGGER sa_test_fail_documents');
$check('a draft submission that fails after its files were copied: refused, nothing saved, no copy left, the draft keeps every file',
    $status >= 500 && $requestCount() === $before && $requestFiles() === $filesBefore && is_file($ownerDraft) && is_file($extra), [$status, substr($body, 0, 200)]);
[$status, $ref, $body] = $submit('docreq', true, [], ['draft_token' => $code]);
$check('a draft submission uses only required documents; an unneeded saved file is not attached and is deleted', $status === 200
    && array_column($documentsOf($ref), 'doc_key') === ['ownerId'] && !is_file($extra), [$status, $documentsOf($ref), $body]);
// Step 12 (F1): the request holds its own COPY (a new file, so it gets the folder's default access
// entries); the draft's file is removed only after the commit.
$stored = (string) $pdo->query('SELECT d.stored_path FROM request_documents d JOIN requests r ON r.id = d.request_id WHERE r.reference_no = ' . $pdo->quote($ref) . " AND d.doc_key = 'ownerId'")->fetchColumn();
$check('the request\'s file is a copy in the request folder and the draft\'s own file is gone after the commit', $stored !== ''
    && is_file($work . '/smart-assess-app/' . $stored) && !is_file($ownerDraft)
    && hash_file('sha256', $work . '/smart-assess-app/' . $stored) === hash_file('sha256', $fixtures . '/id.png'), [$stored, is_file($ownerDraft)]);
$api = (string) file_get_contents($project . '/smart-assess-app/web/api.php');
$check('source: draft files are copied into a request, never renamed', str_contains($api, "@copy((string) \$draftFile['path'], \$destination)")
    && !preg_match('/rename\(\(string\) \$draftFile/', $api));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
