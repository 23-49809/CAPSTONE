<?php
declare(strict_types=1);

/*
 * Request tracking and applicant names:
 *  - the browser session that submitted a request sees the applicant's full
 *    name on its receipt and in tracking (page and API);
 *  - any other session with only the reference number sees initials, the
 *    service and the status, never the full name or contact details;
 *  - opening another person's receipt link shows tracking (initials), not the receipt;
 *  - a duplicate submission from another browser (same saved-application code)
 *    does not make that browser the submitter;
 *  - the receipt keeps its Current Status row, on screen and in the saved image;
 *  - the Terms version and the upload rules match between the browser and PHP,
 *    and the legal text no longer says anyone can see the full name.
 *
 * Runs the real web/ code over HTTP against a copy in a temporary folder. The
 * copy's SMS and Gmail senders are stubs that refuse, so nothing can be sent.
 * All people and contact details are made up. Uses ONLY a database whose name
 * ends in "_test"; it drops and recreates the tables it needs there.
 *
 *   SMART_ASSESS_TEST_DB=smart_tracking_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=... SMART_ASSESS_DB_PASS='...' php tests/tracking_name_privacy_test.php
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
$termsVersion = '2026-11-13';   // the current Terms version (browser and PHP)

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};

// ================= Source checks (no server needed) =================
$publicPage = (string) file_get_contents($project . '/smart-assess.html');
$termsSource = (string) file_get_contents($project . '/smart-assess-app/web/terms.php');
$privacySource = (string) file_get_contents($project . '/smart-assess-app/web/privacy-policy.php');
preg_match("/const SA_TERMS_VERSION = '([^']+)'/", (string) file_get_contents($project . '/smart-assess-app/web/terms-acceptance.php'), $serverVersion);
preg_match("/const TERMS_VERSION = '([^']+)'/", $publicPage, $clientVersion);
$check('the Terms version is ' . $termsVersion . ' in both the browser and PHP', ($serverVersion[1] ?? '') === $termsVersion && ($clientVersion[1] ?? '') === $termsVersion,
    [$serverVersion[1] ?? null, $clientVersion[1] ?? null]);

$between = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    if ($from === false) {
        return '';
    }
    $to = strpos($source, $end, $from + strlen($start));
    return $to === false ? '' : substr($source, $from, $to - $from);
};
$onScreen = $between($publicPage, 'function renderConfirmation(){', "\nfunction ");
$savedImage = $between($publicPage, 'function saveTrackingNumber(){', "\nfunction ");
$check('the on-screen receipt keeps its Current Status row', str_contains($onScreen, "row('clock', 'Current Status', '<span class=\"rcpt-pill\">'+esc(receiptStatus(r))+'</span>')"));
$check('the saved receipt image keeps its Current Status row', str_contains($savedImage, "['Current Status', receiptStatus(submission)]"));
$check('the receipt still shows the reference number, request type, date, client name and service',
    str_contains($onScreen, 'data-ref-no') && str_contains($onScreen, "'Request Type'") && str_contains($onScreen, "'Date Submitted'")
    && str_contains($onScreen, "'Client Name'") && str_contains($onScreen, "'Service'")
    && str_contains($savedImage, "['Client Name', submission.applicant || '—']"));

$check('the browser rejects a file of exactly the limit (>=), as the server does', str_contains($publicPage, 'if(file.size >= maxBytes){')
    && str_contains($publicPage, "'This file is too large. Each file must be smaller than '+maxMb+' MB. Choose a smaller file to continue.'"));
$check('the browser marks a file valid only below the limit and with its document\'s types',
    str_contains($publicPage, 'f.size < uploadLimitBytes()') && str_contains($publicPage, "const IMAGE_ONLY_KEYS = ['ownerId','requesterId','authLetter'];")
    && str_contains($publicPage, 'const IMAGE_FILE_RE = /^image\/(jpeg|png)$/;'));
$check('the upload hint says "smaller than", not "up to"', str_contains($publicPage, "fileTypesFor(key) + ', smaller than ' + maxMb + ' MB'")
    && !str_contains($publicPage, "', up to ' + maxMb"));
$check('the footer button markup is well formed with its label', str_contains($publicPage,
    '<nav class="footer-links" aria-label="About SMART ASSESS"><h5>Learn More</h5><button type="button" data-scroll-to="why-choose">'));

$uploadWording = 'Uploaded files must be smaller than 5 MiB each. Government-issued IDs and authorization letters must be in JPG or PNG format. '
    . 'Other supporting documents may be submitted in JPG, PNG, WEBP, or PDF format.';
$check('the Terms page and the Terms popup state the same upload rules', str_contains($termsSource, $uploadWording) && str_contains($publicPage, $uploadWording));
$oldClaims = ["can see the applicant's name", 'the applicant’s name, and the service requested', "can see the applicant's name, the service requested"];
$stale = array_values(array_filter($oldClaims, static fn (string $claim): bool => str_contains($termsSource . $privacySource . $publicPage, $claim)));
$check('no legal text says anyone with a reference number sees the applicant\'s name', $stale === [], $stale);
$check('the Terms page, Privacy Policy and popup all say others see initials only',
    str_contains($termsSource, "the applicant's initials") && str_contains($privacySource, "the applicant's initials")
    && str_contains($publicPage, 'the applicant’s initials'));
$check('the Privacy Policy says Admin and Head do not receive contact details or file names',
    str_contains($privacySource, 'They do not receive your mobile number, email address, home address, client account name, or the names of your uploaded files.'));

$windowSentence = 'full name for up to 15 minutes after submission. This ends sooner if the applicant leaves the receipt page';
$check('the Terms page, Privacy Policy and popup describe the 15-minute window and the early end',
    str_contains($termsSource, $windowSentence) && str_contains($privacySource, $windowSentence) && str_contains($publicPage, $windowSentence));
$check('the legal text says the shown or saved receipt remains usable',
    substr_count($termsSource . $privacySource . $publicPage, 'The receipt already shown on screen, and any receipt image the applicant saved, remain usable.') === 3);
$check('no legal text says the name is shown until the browser is closed',
    !str_contains($termsSource . $privacySource . $publicPage, 'until that browser is closed'));
$check('the Privacy Policy cookie section says the session remembers submissions for up to 15 minutes',
    str_contains($privacySource, 'remembers, for up to 15 minutes, which requests were submitted from your browser'));

// ---- A copy of the application and a test database ----
$work = sys_get_temp_dir() . '/sa-tracking-names-' . bin2hex(random_bytes(4));
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
        continue;
    }
    $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($migrationFile));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

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
/** Starts the copied site on a free port with extra environment settings; returns the port. */
$startServer = static function (array $extraEnv = []) use (&$servers, $work, $dbHost, $dbPort, $dbName, $dbUser, $dbPass): int {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $servers[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $work . '/smart-assess-app/web'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work . '/smart-assess-app/web',
        ['PATH' => '/usr/bin:/bin', 'DB_HOST' => $dbHost, 'DB_PORT' => $dbPort, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'SMART_ASSESS_DB_PASS' => $dbPass,
            'SMART_ASSESS_GMAIL_DIR' => $work . '/empty-gmail'] + $extraEnv);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    return $port;
};
$port = $startServer();

/** One browser: its own cookie jar and CSRF token (on the default server unless $onPort is given). */
$browser = static function (?int $onPort = null) use (&$port, &$jars): object {
    $jar = (string) tempnam(sys_get_temp_dir(), 'sa-cookies');
    $jars[] = $jar;
    return new class ($onPort ?? $port, $jar) {
        public string $csrf = '';
        public function __construct(private readonly int $port, private readonly string $jar)
        {
        }
        /** @return array{0:int,1:string} */
        public function http(string $path, mixed $post = null, array $headers = []): array
        {
            $curl = curl_init('http://127.0.0.1:' . $this->port . $path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
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
                ['Content-Type: application/json']);
            return [$status, (array) json_decode($body, true)];
        }
        /** The data the page was rendered with (SMART_ASSESS_PAGE). */
        public function page(string $path): array
        {
            [$status, $body] = $this->http($path);
            preg_match('/SMART_ASSESS_PAGE=(\{.*?\});<\/script>/s', $body, $m);
            return [$status, (array) json_decode($m[1] ?? '{}', true), $body];
        }
    };
};

$fixtures = sys_get_temp_dir() . '/sa-tracking-files-' . bin2hex(random_bytes(4));
mkdir($fixtures, 0700);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($fixtures)));
$image = imagecreatetruecolor(30, 20);
imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 60));
imagepng($image, $fixtures . '/id.png');

// Made-up applicant. Everything in $private must never reach another browser.
$applicant = ['flow' => 'docreq', 'first_name' => 'Lorna', 'middle_name' => 'Quintos', 'last_name' => 'Ybañez', 'contact_number' => '+639170005151',
    'email' => 'lorna.tracking@example.invalid', 'address_line' => '77 Zinniaxq Lane', 'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '4202',
    'purpose' => 'Test', 'arp_number' => '14-0007-05151', 'property_address' => 'Anilao', 'barangay' => 'Anilao', 'is_owner' => true,
    'document_type' => 'Certified True Copy of Tax Declaration'];
$private = ['first name' => 'Lorna', 'middle name' => 'Quintos', 'last name' => 'Ybañez', 'last name (JSON)' => 'Yba\\u00f1ez', 'phone' => '9170005151',
    'email' => 'lorna.tracking', 'home address' => 'Zinniaxq', 'file name' => 'lorna-id-scan'];
$leaks = static function (string $body) use ($private): array {
    $decoded = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
    $decoded .= ' ' . (string) json_encode(json_decode((string) preg_replace('/^[^{]*/', '', $body), true), JSON_UNESCAPED_UNICODE);
    return array_keys(array_filter($private, static fn (string $value): bool => stripos($body, $value) !== false || stripos($decoded, $value) !== false));
};

// ================= The submitting browser =================
$submitter = $browser();
$submitter->http('/index.php');
[, $accepted] = $submitter->json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $termsVersion]);
[, $draft] = $submitter->json('draft_save', ['flow' => 'docreq', 'step' => 3, 'fields' => ['firstName' => 'Lorna']]);
$draftCode = str_replace('-', '', (string) ($draft['data']['code'] ?? ''));
[$status, $body] = $submitter->http('/api.php?action=draft_upload', ['payload' => json_encode(['action' => 'draft_upload', 'csrf' => $submitter->csrf,
    'draft_token' => $draftCode, 'doc_key' => 'ownerId']), 'file' => new CURLFile($fixtures . '/id.png', 'image/png', 'lorna-id-scan.png')]);
$check('setup: a saved application with an uploaded ID', strlen($draftCode) === 20 && $status === 200, [$status, $body]);
[$status, $body] = $submitter->http('/api.php?action=submit_request', ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $submitter->csrf,
    'terms_token' => (string) ($accepted['data']['terms_token'] ?? ''), 'draft_token' => $draftCode] + $applicant)]);
$reference = (string) (json_decode($body, true)['data']['reference_no'] ?? '');
$check('setup: the request is submitted', $status === 200 && $reference !== '', [$status, $body]);

[$status, $page, $html] = $submitter->page('/index.php?submitted=' . rawurlencode($reference));
$receipt = $page['lastSubmission'] ?? [];
$check('submitting browser: the receipt opens with the full applicant name', $status === 200 && ($page['view'] ?? '') === 'confirmation'
    && ($receipt['applicant'] ?? '') === 'Lorna Ybañez' && ($receipt['applicantInitialsOnly'] ?? null) === false, $receipt);
$check('submitting browser: the receipt keeps the reference, service and current status', ($receipt['id'] ?? '') === $reference
    && ($receipt['docType'] ?? '') === 'Certified True Copy of Tax Declaration' && ($receipt['status'] ?? '') === 'Received', $receipt);
$check('submitting browser: the receipt page has no contact details', array_diff($leaks($html), ['first name', 'last name', 'last name (JSON)']) === [], $leaks($html));

[$status, $page] = $submitter->page('/index.php?track=' . rawurlencode($reference));
$result = $page['track']['result'] ?? [];
$check('submitting browser: tracking shows the full applicant name', $status === 200 && ($result['applicant'] ?? '') === 'Lorna Ybañez'
    && ($result['applicantInitialsOnly'] ?? null) === false, $result);
[$status, $body] = $submitter->http('/api.php?action=track&ref=' . rawurlencode($reference));
$apiRequest = (array) (json_decode($body, true)['request'] ?? []);
$check('submitting browser: the tracking API returns the full applicant name', $status === 200 && ($apiRequest['applicant'] ?? '') === 'Lorna Ybañez', $apiRequest);

// ================= Another browser with only the reference number =================
$other = $browser();
$other->http('/index.php');
[$status, $page, $html] = $other->page('/index.php?track=' . rawurlencode($reference));
$result = $page['track']['result'] ?? [];
$check('other browser: tracking shows initials (L. Y.)', $status === 200 && ($result['applicant'] ?? '') === 'L. Y.'
    && ($result['applicantInitialsOnly'] ?? null) === true, $result);
$check('other browser: the service and status are still shown', ($result['docType'] ?? '') === 'Certified True Copy of Tax Declaration'
    && ($result['status'] ?? '') === 'Received' && ($result['id'] ?? '') === $reference, $result);
$check('other browser: the tracking page has no name, contact details or file names', $leaks($html) === [], $leaks($html));

[$status, $page, $html] = $other->page('/index.php?track=' . rawurlencode(strtolower($reference)));
$check('other browser: a lowercase reference also gets initials only', ($page['track']['result']['applicant'] ?? '') === 'L. Y.' && $leaks($html) === [],
    [$page['track']['result'] ?? null, $leaks($html)]);

[$status, $page, $html] = $other->page('/index.php?submitted=' . rawurlencode($reference));
$check('other browser: the receipt link shows tracking with initials, not the receipt', $status === 200 && ($page['view'] ?? '') === 'track'
    && ($page['lastSubmission'] ?? null) === null && ($page['track']['result']['applicant'] ?? '') === 'L. Y.' && $leaks($html) === [],
    [$page['view'] ?? null, $leaks($html)]);

[$status, $body] = $other->http('/api.php?action=track&ref=' . rawurlencode($reference));
$apiRequest = (array) (json_decode($body, true)['request'] ?? []);
$check('other browser: the tracking API returns initials and no name fields', $status === 200 && ($apiRequest['applicant'] ?? '') === 'L. Y.'
    && !array_key_exists('first_name', $apiRequest) && !array_key_exists('last_name', $apiRequest), $apiRequest);
$check('other browser: the tracking API response has no name, contact details or file names', $leaks($body) === [], $leaks($body));

// A request parameter cannot claim to be the submitter.
[$status, $body] = $other->http('/api.php?action=track&ref=' . rawurlencode($reference) . '&sa_submitted_refs[]=' . rawurlencode($reference));
$check('other browser: a forged parameter does not reveal the name', $leaks($body) === [], $leaks($body));

// ================= A duplicate submission from another browser =================
$second = $browser();
$second->http('/index.php');
[$status, $body] = $second->http('/api.php?action=submit_request', ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $second->csrf,
    'draft_token' => $draftCode] + $applicant)]);
$duplicate = (array) (json_decode($body, true)['data'] ?? []);
$check('a second browser re-submitting the same saved application gets the same reference', $status === 200
    && ($duplicate['reference_no'] ?? '') === $reference && ($duplicate['duplicate'] ?? false) === true, [$status, $body]);
[, $page, $html] = $second->page('/index.php?track=' . rawurlencode($reference));
$check('that second browser is not treated as the submitter (initials only)', ($page['track']['result']['applicant'] ?? '') === 'L. Y.'
    && $leaks($html) === [], [$page['track']['result'] ?? null, $leaks($html)]);
$check('only one request exists', (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn() === 1);

// ================= The submitting browser keeps the full name =================
[, $page] = $submitter->page('/index.php?track=' . rawurlencode($reference));
$check('the submitting browser still sees the full name afterwards', ($page['track']['result']['applicant'] ?? '') === 'Lorna Ybañez');

// A second request from the submitting browser: both stay visible to it.
[, $accepted] = $submitter->json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $termsVersion]);
[$status, $body] = $submitter->http('/api.php?action=submit_request', ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $submitter->csrf,
    'terms_token' => (string) ($accepted['data']['terms_token'] ?? '')] + ['first_name' => 'Marco', 'last_name' => 'Abad'] + $applicant),
    'file_ownerId' => new CURLFile($fixtures . '/id.png', 'image/png', 'id.png')]);
$secondReference = (string) (json_decode($body, true)['data']['reference_no'] ?? '');
[, $pageA] = $submitter->page('/index.php?track=' . rawurlencode($reference));
[, $pageB] = $submitter->page('/index.php?track=' . rawurlencode($secondReference));
[, $pageOther] = $other->page('/index.php?track=' . rawurlencode($secondReference));
$check('a browser that submitted two requests sees both full names', $secondReference !== '' && $secondReference !== $reference
    && ($pageA['track']['result']['applicant'] ?? '') === 'Lorna Ybañez' && ($pageB['track']['result']['applicant'] ?? '') === 'Marco Abad',
    [$secondReference, $pageB['track']['result'] ?? null]);
$check('another browser sees the second request as initials (M. A.)', ($pageOther['track']['result']['applicant'] ?? '') === 'M. A.');

// ================= Shared computers: the same browser, after the applicant is done =================
/** Submits a new request with an uploaded ID from browser $b; returns its reference. */
$submitNew = static function (object $b, string $first, string $last) use ($applicant, $fixtures): string {
    $b->http('/index.php');
    [, $accepted] = $b->json('terms_accept', ['flow' => 'docreq', 'agreed' => true, 'terms_version' => $GLOBALS['termsVersion']]);
    [, $body] = $b->http('/api.php?action=submit_request', ['payload' => json_encode(['action' => 'submit_request', 'csrf' => $b->csrf,
        'terms_token' => (string) ($accepted['data']['terms_token'] ?? '')] + ['first_name' => $first, 'last_name' => $last] + $applicant),
        'file_ownerId' => new CURLFile($fixtures . '/id.png', 'image/png', 'id.png')]);
    return (string) (json_decode($body, true)['data']['reference_no'] ?? '');
};
$nameShown = static function (object $b, string $reference, string $fullName): array {
    [, $receiptPage, $receiptHtml] = $b->page('/index.php?submitted=' . rawurlencode($reference));
    [, $trackPage] = $b->page('/index.php?track=' . rawurlencode($reference));
    [, $apiBody] = $b->http('/api.php?action=track&ref=' . rawurlencode($reference));
    return [
        'receipt' => ($receiptPage['view'] ?? '') === 'confirmation' && ($receiptPage['lastSubmission']['applicant'] ?? '') === $fullName,
        'receipt html' => str_contains($receiptHtml, explode(' ', $fullName)[0]),
        'tracking' => ($trackPage['track']['result']['applicant'] ?? '') === $fullName,
        'api' => (json_decode($apiBody, true)['request']['applicant'] ?? '') === $fullName,
        'initials everywhere' => ($receiptPage['view'] ?? '') === 'track' && ($receiptPage['lastSubmission'] ?? null) === null
            && !str_contains($receiptHtml, explode(' ', $fullName)[0])
            && preg_match('/^[A-Z]\. [A-Z]\.$/', (string) ($trackPage['track']['result']['applicant'] ?? '')) === 1
            && preg_match('/^[A-Z]\. [A-Z]\.$/', (string) (json_decode($apiBody, true)['request']['applicant'] ?? '')) === 1,
    ];
};

$kiosk = $browser();
$kioskRef = $submitNew($kiosk, 'Rosa', 'Villareal');
$shown = $nameShown($kiosk, $kioskRef, 'Rosa Villareal');
$check('shared computer: right after submitting, the receipt, tracking and API show the full name',
    $kioskRef !== '' && $shown['receipt'] && $shown['tracking'] && $shown['api'], $shown);

[$status] = $kiosk->http('/api.php?action=receipt_done', (string) json_encode(['action' => 'receipt_done', 'csrf' => 'wrong-token', 'reference' => $kioskRef]),
    ['Content-Type: application/json']);
$check('shared computer: "done" without the page\'s security token is refused and changes nothing', $status === 403
    && $nameShown($kiosk, $kioskRef, 'Rosa Villareal')['receipt'], $status);

$bystander = $browser();
$bystander->http('/index.php');
[$status] = $bystander->json('receipt_done', ['reference' => $kioskRef]);
$check('shared computer: another browser saying "done" does not affect the applicant', $status === 200
    && $nameShown($kiosk, $kioskRef, 'Rosa Villareal')['tracking'], $status);

[$status, $done] = $kiosk->json('receipt_done', ['reference' => $kioskRef]);
$shown = $nameShown($kiosk, $kioskRef, 'Rosa Villareal');
$check('shared computer: after the applicant is done, the same browser sees only initials (receipt link, tracking, API)',
    $status === 200 && ($done['data']['forgotten'] ?? null) === true && $shown['initials everywhere'], [$status, $done, $shown]);
$check('shared computer: the receipt link from the browser history no longer opens the receipt', !$shown['receipt'] && !$shown['receipt html'], $shown);

// The time window: a server set to 2 seconds (the default is 15 minutes).
$shortPort = $startServer(['SMART_ASSESS_RECEIPT_NAME_SECONDS' => '2']);
$walkAway = $browser($shortPort);
$walkAwayRef = $submitNew($walkAway, 'Teodoro', 'Umali');
$before = $nameShown($walkAway, $walkAwayRef, 'Teodoro Umali');
$badPort = $startServer(['SMART_ASSESS_RECEIPT_NAME_SECONDS' => 'not-a-number']);
$fallback = $browser($badPort);
$fallbackRef = $submitNew($fallback, 'Nilda', 'Pascual');
sleep(4);
$after = $nameShown($walkAway, $walkAwayRef, 'Teodoro Umali');
$check('shared computer: within the window the applicant still sees the full receipt', $before['receipt'] && $before['tracking'] && $before['api'], $before);
$check('shared computer: after the window ends, the same browser sees only initials', $after['initials everywhere'] && !$after['receipt'], $after);
$check('an invalid window setting falls back to the default window (the name is still shown after 4 seconds), not to zero',
    $nameShown($fallback, $fallbackRef, 'Nilda Pascual')['receipt']);

$check('the page tells the server when the applicant leaves the receipt (keeps Save, Copy and View status)',
    str_contains($publicPage, "action:'receipt_done'") && str_contains($publicPage, "['save-tracking','copy-tracking','view-status']")
    && str_contains($publicPage, 'keepalive:true'));

$check('saving the receipt does not end the window: only a person\'s click counts, and download links are ignored',
    str_contains($publicPage, "if(!e.isTrusted || !state.lastSubmission") && str_contains($publicPage, "if(!el || el.hasAttribute('download')) return;"));
$check('the email hint no longer promises an automatic confirmation email',
    !str_contains($publicPage, 'receive a confirmation of your request') && str_contains($publicPage, 'Staff may use it to send you messages about your request.'));

// ================= Unknown references =================
[$status, $page] = $other->page('/index.php?track=DR-2099-99999');
$check('an unknown reference shows "not found"', ($page['track']['searched'] ?? false) === true && array_key_exists('result', $page['track'] ?? []) && $page['track']['result'] === null);
[$status] = $other->http('/api.php?action=track&ref=DR-2099-99999');
$check('the tracking API answers 404 for an unknown reference', $status === 404, $status);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
