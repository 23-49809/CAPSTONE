<?php
declare(strict_types=1);

/*
 * Terms and Conditions version update: the wording on the public Terms page
 * and in the acceptance popup, both version constants, and what happens to
 * applicants who accepted the previous version:
 *  - their saved draft and its uploaded files stay intact;
 *  - submitting asks them to accept the updated Terms (code "terms_outdated");
 *  - after accepting, the new version is recorded and linked to the request;
 *  - the rest of the acceptance workflow behaves as before.
 *
 * Runs the real web/ code over HTTP against a copy in a temporary folder, with
 * the real terms, drafts and AI migrations. All data is made up. Uses ONLY a
 * database whose name ends in "_test".
 *
 *   SMART_ASSESS_TEST_DB=smart_terms_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=... SMART_ASSESS_DB_PASS='...' php tests/terms_version_test.php
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
$previousVersion = '2026-11-06';
$newWording = 'Uploaded files must be smaller than 5 MiB each. Government-issued IDs and authorization letters must be in JPG or PNG format. '
    . 'Other supporting documents may be submitted in JPG, PNG, WEBP, or PDF format.';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};

// ================= Version constants and wording (no server needed) =================
preg_match("/const SA_TERMS_VERSION = '([^']+)'/", (string) file_get_contents($project . '/smart-assess-app/web/terms-acceptance.php'), $server);
$publicPage = (string) file_get_contents($project . '/smart-assess.html');
preg_match("/const TERMS_VERSION = '([^']+)'/", $publicPage, $client);
$version = (string) ($server[1] ?? '');
$check('the server and the public page use the same new Terms version', $version !== '' && $version === ($client[1] ?? null)
    && $version !== $previousVersion && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $version), [$version, $client[1] ?? null]);
$termsSource = (string) file_get_contents($project . '/smart-assess-app/web/terms.php');
$check('the public Terms page has the new wording, and the old wording is gone', str_contains($termsSource, $newWording)
    && !str_contains($termsSource, 'up to 5 MB'));
$check('the acceptance popup (the text applicants accept) has the same wording', str_contains($publicPage, $newWording));

// ---- A copy of the application and a test database ----
$work = sys_get_temp_dir() . '/sa-terms-version-' . bin2hex(random_bytes(4));
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

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, (int) $dbPort, $dbName), $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['ai_verification_jobs', 'terms_acceptances', 'request_draft_files', 'request_drafts', 'request_draft_rate', 'users', 'clients', 'requests',
    'request_documents', 'document_validation', 'request_status_log', 'audit_log', 'announcements', 'settings', 'notifications', 'document_types', 'transfer_types'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach ([
    "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, role_id INT NOT NULL, name VARCHAR(150) NOT NULL, username VARCHAR(80) NOT NULL, password_hash VARCHAR(255) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE clients (id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(150) NOT NULL,
        contact_number VARCHAR(20) NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active') ENGINE=InnoDB",
    "CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NULL, reference_no VARCHAR(20) NOT NULL, flow VARCHAR(20) NOT NULL,
        first_name VARCHAR(80) NOT NULL, middle_name VARCHAR(80) NULL, last_name VARCHAR(80) NOT NULL, contact_number VARCHAR(20) NOT NULL, email VARCHAR(150) NULL,
        address_line VARCHAR(255) NOT NULL DEFAULT '', province VARCHAR(80) NULL, city VARCHAR(80) NULL, zip_code VARCHAR(10) NOT NULL DEFAULT '',
        document_type VARCHAR(120) NULL, transfer_type VARCHAR(120) NULL, purpose VARCHAR(200) NULL, arp_number VARCHAR(60) NOT NULL DEFAULT '',
        property_address VARCHAR(255) NULL, barangay VARCHAR(100) NOT NULL DEFAULT '', is_owner TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'Received', requirement_complete TINYINT(1) NOT NULL DEFAULT 0, advisory TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE request_documents (id INT AUTO_INCREMENT PRIMARY KEY, request_id INT NOT NULL, doc_key VARCHAR(40) NOT NULL, label VARCHAR(150) NOT NULL,
        original_name VARCHAR(255) NULL, stored_path VARCHAR(255) NULL, mime_type VARCHAR(80) NULL, file_size INT NULL, file_status VARCHAR(20) NOT NULL DEFAULT 'missing') ENGINE=InnoDB",
    "CREATE TABLE document_validation (id INT AUTO_INCREMENT PRIMARY KEY, request_document_id INT NOT NULL, validation_status VARCHAR(20) NOT NULL DEFAULT 'pending') ENGINE=InnoDB",
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

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $work . '/smart-assess-app/web'],
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
$jar = tempnam(sys_get_temp_dir(), 'sa-cookies');
$http = static function (string $path, mixed $post = null, array $headers = []) use ($port, $jar): array {
    $curl = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
    }
    $body = (string) curl_exec($curl);
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
};
[$status, $page] = $http('/terms.php');
$check('the public Terms page shows the new wording and date', $status === 200 && str_contains(html_entity_decode($page), $newWording)
    && str_contains($page, 'November 11, 2026'), $status);
preg_match('/SMART_ASSESS_CONFIG=(\{.*?\});/', $http('/index.php')[1], $m);
$csrf = (string) (json_decode($m[1] ?? '{}', true)['csrf'] ?? '');
$json = static fn (string $action, array $data) => $http('/api.php?action=' . $action, (string) json_encode(['action' => $action, 'csrf' => $csrf] + $data), ['Content-Type: application/json']);

$fixtures = sys_get_temp_dir() . '/sa-terms-files-' . bin2hex(random_bytes(4));
mkdir($fixtures, 0700);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($fixtures)));
$image = imagecreatetruecolor(30, 20);
imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 60));
imagepng($image, $fixtures . '/id.png');
$applicant = ['flow' => 'docreq', 'first_name' => 'Terms', 'last_name' => 'Tester', 'contact_number' => '+639170003333', 'email' => 'terms.test@example.invalid',
    'address_line' => '1 Test St', 'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '4202', 'purpose' => 'Test', 'arp_number' => '14-0007-00003',
    'property_address' => 'Anilao', 'barangay' => 'Anilao', 'is_owner' => true, 'document_type' => 'Certified True Copy of Tax Declaration'];
$submit = static function (array $extra, array $files = []) use ($http, $csrf, $applicant): array {
    $post = ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $csrf] + $extra + $applicant)];
    foreach ($files as $key => $path) {
        $post['file_' . $key] = new CURLFile($path, 'image/png', basename($path));
    }
    [$status, $body] = $http('/api.php?action=submit_request', $post);
    $decoded = json_decode($body, true);
    return [$status, (string) ($decoded['data']['reference_no'] ?? ''), (string) ($decoded['code'] ?? ''), $body];
};

// ================= An earlier request, submitted before the update =================
[, $earlierBody] = $json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $version]);
[$status, $earlierRef] = $submit(['terms_token' => (string) (json_decode($earlierBody, true)['data']['terms_token'] ?? '')], ['ownerId' => $fixtures . '/id.png']);
$earlierFile = $work . '/smart-assess-app/' . (string) $pdo->query("SELECT d.stored_path FROM request_documents d JOIN requests r ON r.id = d.request_id WHERE r.reference_no = '{$earlierRef}'")->fetchColumn();
$earlierHash = is_file($earlierFile) ? hash_file('sha256', $earlierFile) : '';
$check('an earlier request with an uploaded file exists', $status === 200 && $earlierHash !== '', $status);

// ================= An applicant who accepted the previous version, with a saved draft =================
$oldToken = bin2hex(random_bytes(24));
$pdo->prepare('INSERT INTO terms_acceptances (token_hash, terms_version, flow) VALUES (?, ?, ?)')
    ->execute([hash('sha256', 'smart-assess-terms|' . $oldToken), $previousVersion, 'docreq']);
[, $draftBody] = $json('draft_save', ['flow' => 'docreq', 'step' => 2, 'fields' => ['firstName' => 'Terms', 'termsToken' => $oldToken]]);
$code = str_replace('-', '', (string) (json_decode($draftBody, true)['data']['code'] ?? ''));
[$status] = $http('/api.php?action=draft_upload', ['payload' => json_encode(['action' => 'draft_upload', 'csrf' => $csrf, 'draft_token' => $code, 'doc_key' => 'ownerId']),
    'file' => new CURLFile($fixtures . '/id.png', 'image/png', 'id.png')]);
$draftFile = $work . '/smart-assess-app/' . (string) $pdo->query('SELECT stored_path FROM request_draft_files ORDER BY id DESC LIMIT 1')->fetchColumn();
$check('the applicant has a saved draft with an uploaded file', strlen($code) === 20 && $status === 200 && is_file($draftFile));
$draftHash = hash_file('sha256', $draftFile);

[$status, $body] = $json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $previousVersion]);
$check('a page still showing the previous version cannot record an acceptance', $status === 409, [$status, $body]);
$requestsBefore = (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn();
[$status, , $errorCode, $body] = $submit(['terms_token' => $oldToken, 'draft_token' => $code]);
$check('submitting with the previous acceptance asks to accept the updated Terms (terms_outdated)', $status === 422 && $errorCode === 'terms_outdated'
    && str_contains($body, 'updated'), [$status, $errorCode, $body]);
$draftRow = $pdo->query('SELECT submitted_reference, form_data FROM request_drafts ORDER BY id DESC LIMIT 1')->fetch();
$check('nothing was submitted, and the draft keeps its details', (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn() === $requestsBefore
    && $draftRow['submitted_reference'] === null && str_contains((string) $draftRow['form_data'], 'Terms'));
$check('the draft\'s uploaded file is untouched', is_file($draftFile) && hash_file('sha256', $draftFile) === $draftHash
    && (int) $pdo->query('SELECT COUNT(*) FROM request_draft_files')->fetchColumn() === 1);
$check('the previous acceptance is not marked as used', $pdo->query("SELECT request_id FROM terms_acceptances WHERE terms_version = '{$previousVersion}'")->fetchColumn() === null);

[$status, $body] = $json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $version]);
$newToken = (string) (json_decode($body, true)['data']['terms_token'] ?? '');
$check('accepting the updated Terms records the new version', $status === 200 && strlen($newToken) === 48
    && $pdo->query("SELECT terms_version FROM terms_acceptances WHERE token_hash = '" . hash('sha256', 'smart-assess-terms|' . $newToken) . "'")->fetchColumn() === $version, [$status, $body]);
[$status, $reference, , $body] = $submit(['terms_token' => $newToken, 'draft_token' => $code]);
$linked = $pdo->prepare('SELECT t.terms_version FROM terms_acceptances t JOIN requests r ON r.id = t.request_id WHERE r.reference_no = ?');
$linked->execute([$reference]);
$check('the draft is then submitted, linked to the new version', $status === 200 && $reference !== '' && $linked->fetchColumn() === $version, [$status, $body]);
$check('the draft\'s file became the request\'s document', (string) $pdo->query("SELECT d.file_status FROM request_documents d JOIN requests r ON r.id = d.request_id WHERE r.reference_no = '{$reference}' AND d.doc_key = 'ownerId'")->fetchColumn() === 'ok');
$check('the earlier request\'s uploaded file is untouched', is_file($earlierFile) && hash_file('sha256', $earlierFile) === $earlierHash);

// ================= The rest of the workflow is unchanged =================
[$status, , $errorCode] = $submit(['terms_token' => $newToken], ['ownerId' => $fixtures . '/id.png']);
$check('an acceptance cannot be used for a second request', $status === 409, [$status, $errorCode]);
[$status, , $errorCode] = $submit([], ['ownerId' => $fixtures . '/id.png']);
$check('submitting without accepting asks for acceptance (terms_required)', $status === 422 && $errorCode === 'terms_required', [$status, $errorCode]);
[, $body] = $json('terms_accept', ['flow' => 'landtransfer', 'agreed' => true, 'terms_version' => $version]);
[$status, , $errorCode] = $submit(['terms_token' => (string) (json_decode($body, true)['data']['terms_token'] ?? '')], ['ownerId' => $fixtures . '/id.png']);
$check('an acceptance for the other request type is refused', $status === 422 && $errorCode === 'terms_required', [$status, $errorCode]);
[$status] = $json('terms_accept', ['flow' => 'docreq', 'agreed' => false, 'terms_version' => $version]);
$check('the box must be ticked', $status === 422);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
