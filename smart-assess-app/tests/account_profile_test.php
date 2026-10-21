<?php
declare(strict_types=1);

/*
 * Repeatable tests for internal account editing (Admin) and own-profile
 * updates, run against the real API over HTTP.
 *
 * Uses ONLY a dedicated test database: it refuses to run unless
 * SMART_ASSESS_TEST_DB names a database ending in "_test". It drops and
 * recreates just the tables it needs (roles, users, user_sessions,
 * audit_log, activity_logs) in that database, starts PHP's built-in server
 * on a free local port, and stops it at the end. No email or SMS is sent.
 *
 *   SMART_ASSESS_TEST_DB=smart_assess_test DB_HOST=127.0.0.1 DB_PORT=3306 \
 *   DB_USER=root SMART_ASSESS_DB_PASS='...' php tests/account_profile_test.php
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
// Optional: build the tables with a specific collation (production schemas may use
// either of these); uniqueness must behave the same under both.
$collation = getenv('SMART_ASSESS_TEST_COLLATION') ?: 'utf8mb4_0900_ai_ci';
if (!in_array($collation, ['utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci'], true)) {
    fwrite(STDERR, "Unsupported SMART_ASSESS_TEST_COLLATION.\n");
    exit(2);
}
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = (string) (getenv('SMART_ASSESS_DB_PASS') ?: '');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, (int) $dbPort),
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4");
$pdo->exec("USE `{$dbName}`");

// Minimal schema, matching the production definitions of these tables.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['activity_logs', 'audit_log', 'user_sessions', 'users', 'roles'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE roles (id TINYINT PRIMARY KEY, name VARCHAR(30) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE {$collation}");
$pdo->exec("INSERT INTO roles (id, name) VALUES (1,'CLIENT'), (2,'STAFF'), (3,'ADMIN'), (4,'DEPARTMENT_HEAD')");
$pdo->exec("CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id TINYINT NOT NULL,
    name VARCHAR(150) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(150) NULL,
    contact_number VARCHAR(20) NULL,
    position_title VARCHAR(80) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    archived_at DATETIME NULL, archived_by INT NULL, restored_at DATETIME NULL, restored_by INT NULL,
    deleted_at DATETIME NULL, deleted_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE {$collation}");
$pdo->exec("CREATE TABLE user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY, user_type ENUM('client','staff','admin','head') NOT NULL, user_id INT NOT NULL,
    ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL,
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE {$collation}");
$pdo->exec("CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY, actor_type ENUM('client','staff','admin','head','system') NOT NULL,
    actor_id INT NULL, actor_name VARCHAR(150) NOT NULL, action VARCHAR(120) NOT NULL, target VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE {$collation}");
$pdo->exec("CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, actor_name VARCHAR(150) NULL, action VARCHAR(200) NOT NULL,
    request_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE {$collation}");

const PW = 'Test-Only-Pass-123';
$hash = password_hash(PW, PASSWORD_DEFAULT);
$seed = $pdo->prepare('INSERT INTO users (role_id, name, username, email, contact_number, position_title, password_hash) VALUES (?,?,?,?,?,?,?)');
$seed->execute([3, 'Test Admin', 'test.admin', 'office@example.invalid', '9170000001', 'Assessor Admin', $hash]);
$seed->execute([2, 'Test Staff', 'test.staff', null, '9170000002', 'Assessment Clerk', $hash]);
$seed->execute([4, 'Test Head', 'test.head', null, '9170000003', 'Assessor Head', $hash]);
$seed->execute([2, 'Other Staff', 'other.staff', 'other@example.invalid', '9170000004', 'Records Officer', $hash]);
$pdo->exec("INSERT INTO activity_logs (user_id, actor_name, action, request_id) VALUES (2, 'Test Staff', 'Completed request processing', 1)");
$ids = $pdo->query('SELECT username, id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);

// Start the app on a free local port, pointed at the test database.
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
// Variables go through /usr/bin/env so an empty password is still passed
// (proc_open drops empty environment values).
$server = proc_open(
    ['/usr/bin/env', 'DB_HOST=' . $dbHost, 'DB_PORT=' . $dbPort, 'DB_NAME=' . $dbName, 'DB_USER=' . $dbUser,
        'SMART_ASSESS_DB_PASS=' . $dbPass, 'SMART_ASSESS_SMS_ENABLED=0',
        PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/web'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    dirname(__DIR__) . '/web'
);
register_shutdown_function(static function () use ($server): void { proc_terminate($server); });
$base = 'http://127.0.0.1:' . $port;
for ($i = 0; $i < 50 && @file_get_contents($base . '/internal.php') === false; $i++) {
    usleep(100000);
}

final class Client
{
    private CurlHandle $ch;
    private string $csrf = '';

    public function __construct(private readonly string $base)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 20]);
        curl_setopt($this->ch, CURLOPT_URL, $this->base . '/internal.php');
        curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        $html = (string) curl_exec($this->ch);
        preg_match('/SMART_ASSESS_CONFIG=(\{.*?\});/', $html, $match);
        $this->csrf = (string) (json_decode($match[1] ?? '{}', true)['csrf'] ?? '');
    }

    /** @return array{0:int,1:array} */
    public function post(string $action, array $data): array
    {
        $data += ['action' => $action, 'csrf' => $this->csrf];
        curl_setopt_array($this->ch, [
            CURLOPT_URL => $this->base . '/api.php?action=' . $action,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $body = (string) curl_exec($this->ch);
        return [(int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), json_decode($body, true) ?: []];
    }
}

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : json_encode($detail)];
};
$row = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT name, username, email, role_id, status, contact_number, position_title, password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
$withoutHash = static fn (array $r): array => array_diff_key($r, ['password_hash' => 1]);
$login = static function (string $username, string $password = PW) use ($base): array {
    $client = new Client($base);
    [$code] = $client->post('internal_login', ['username' => $username, 'password' => $password]);
    return [$client, $code];
};
// What the Admin "Edit Staff Account" form posts (field names as in the page).
$editForm = static function (int $id) use ($row): array {
    $r = $row($id);
    return ['id' => (string) $id, 'fullName' => $r['name'], 'username' => $r['username'], 'password' => '',
        'email' => (string) ($r['email'] ?? ''), 'contact' => (string) $r['contact_number'],
        'role' => [2 => 'staff', 3 => 'admin', 4 => 'head'][(int) $r['role_id']],
        'positionTitle' => (string) $r['position_title'], 'status' => strtolower($r['status'])];
};

[$admin, $code] = $login('test.admin');
$check('admin can sign in', $code === 200, $code);
$staffId = (int) $ids['test.staff'];
$headId = (int) $ids['test.head'];

// ---- Admin account edit
$before = $row($staffId);
[$code, $r] = $admin->post('edit_account', ['password' => 'Brand-New-Pass-2026'] + $editForm($staffId));
$after = $row($staffId);
$check('edit: password-only change of a NULL-email Staff account succeeds', $code === 200, [$code, $r]);
$check('edit: every other field unchanged (email stays NULL)', $withoutHash($after) === $withoutHash($before), $withoutHash($after));
$check('edit: password hash changed', $after['password_hash'] !== $before['password_hash']);
$check('edit: new password works', $login('test.staff', 'Brand-New-Pass-2026')[1] === 200);
$before = $row($headId);
[$code] = $admin->post('edit_account', ['password' => 'Head-New-Pass-2026'] + $editForm($headId));
$check('edit: password-only change of a NULL-email Head succeeds, fields unchanged', $code === 200 && $withoutHash($row($headId)) === $withoutHash($before), $code);
[$code] = $admin->post('edit_account', ['positionTitle' => 'Records Officer'] + $editForm($staffId));
$check('edit: position change saved', $code === 200 && $row($staffId)['position_title'] === 'Records Officer', $row($staffId)['position_title']);
[$code] = $admin->post('edit_account', ['email' => 'Staff.Person@Example.invalid'] + $editForm($staffId));
$check('edit: email stored lowercase', $code === 200 && $row($staffId)['email'] === 'staff.person@example.invalid');
[$code] = $admin->post('edit_account', ['email' => '  '] + $editForm($staffId));
$check('edit: blank email stored as NULL', $code === 200 && $row($staffId)['email'] === null);
$before = $row($staffId);
[$code, $r] = $admin->post('edit_account', ['email' => 'OFFICE@example.invalid', 'password' => 'Other-Pass-2026'] + $editForm($staffId));
$check('edit: duplicate email -> 409 with clear message', $code === 409 && str_contains((string) ($r['error'] ?? ''), 'email address is already used'), [$code, $r]);
$check('edit: refused edit changes nothing (password included)', $row($staffId) === $before);
[$code] = $admin->post('edit_account', ['email' => 'Other@EXAMPLE.invalid'] + $editForm($staffId));
$check('edit: duplicate email differing only in case -> 409', $code === 409, $code);
[$code, $r] = $admin->post('edit_account', ['username' => 'TEST.ADMIN'] + $editForm($staffId));
$check('edit: duplicate username (different case) -> 409 with clear message', $code === 409 && str_contains((string) ($r['error'] ?? ''), 'username is already used'), [$code, $r]);
$check("schema collation is {$collation}", $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'")->fetchColumn() === $collation);
foreach (['invalid email' => ['email' => 'nope'], 'short password' => ['password' => 'short'], 'bad username' => ['username' => 'a b'],
          'invalid status' => ['status' => 'gone'], 'empty name' => ['fullName' => ' ']] as $label => $override) {
    [$code] = $admin->post('edit_account', $override + $editForm($staffId));
    $check("edit: invalid input ({$label}) -> 422", $code === 422, $code);
}
[$code] = $admin->post('edit_account', ['id' => '999999'] + $editForm($staffId));
$check('edit: unknown account -> 404', $code === 404, $code);
[$code, $r] = $admin->post('create_account', ['fullName' => 'New Clerk', 'username' => 'new.clerk', 'password' => 'Clerk-Pass-2026',
    'email' => '', 'contact' => '9170000009', 'role' => 'staff', 'positionTitle' => 'Assessment Clerk']);
$newId = (int) ($r['data']['user_id'] ?? 0);
$check('create: blank email stored as NULL', $code === 200 && $newId > 0 && $row($newId)['email'] === null, [$code, $r]);
[$staff] = $login('test.staff', 'Brand-New-Pass-2026');
[$code] = $staff->post('edit_account', ['password' => 'Hijack-Pass-2026'] + $editForm($headId));
$check('edit: Staff cannot edit accounts (403)', $code === 403, $code);

// ---- Own profile (what the "My Profile" form posts: fullName, email, contact, current_password, password)
$before = $row($staffId);
[$code, $r] = $staff->post('update_profile', ['fullName' => 'Test Staff Renamed', 'email' => '', 'contact' => (string) $before['contact_number'], 'current_password' => '', 'password' => '']);
$after = $row($staffId);
$check('profile: save with blank email succeeds (no email required)', $code === 200, [$code, $r]);
$check('profile: only the name changed; email stays NULL', $after['name'] === 'Test Staff Renamed' && $withoutHash(array_merge($after, ['name' => $before['name']])) === $withoutHash($before), $withoutHash($after));
[$code] = $staff->post('update_profile', ['fullName' => 'Test Staff Renamed']);
$check('profile: omitted fields keep their values (partial update)', $code === 200 && $row($staffId)['contact_number'] === $before['contact_number'] && $row($staffId)['position_title'] === $before['position_title']);
[$code] = $staff->post('update_profile', ['email' => 'Me@Example.invalid']);
$check('profile: email set and stored lowercase', $code === 200 && $row($staffId)['email'] === 'me@example.invalid');
[$code, $r] = $staff->post('update_profile', ['email' => 'other@example.invalid']);
$check('profile: duplicate email -> 409 with clear message', $code === 409 && str_contains((string) ($r['error'] ?? ''), 'email address is already used') && $row($staffId)['email'] === 'me@example.invalid', [$code, $r]);
[$code] = $staff->post('update_profile', ['email' => 'not-an-email']);
$check('profile: invalid email -> 422', $code === 422, $code);
[$code] = $staff->post('update_profile', ['email' => '']);
$check('profile: blank email clears it to NULL', $code === 200 && $row($staffId)['email'] === null);
$hashBefore = $row($staffId)['password_hash'];
[$code] = $staff->post('update_profile', ['password' => 'Profile-New-Pass-2026']);
$check('profile: password change without current password -> 403', $code === 403 && $row($staffId)['password_hash'] === $hashBefore, $code);
[$code] = $staff->post('update_profile', ['password' => 'Profile-New-Pass-2026', 'current_password' => 'wrong-password']);
$check('profile: wrong current password -> 403', $code === 403 && $row($staffId)['password_hash'] === $hashBefore, $code);
[$code] = $staff->post('update_profile', ['password' => 'short', 'current_password' => 'Brand-New-Pass-2026']);
$check('profile: too-short new password -> 422', $code === 422, $code);
[$code] = $staff->post('update_profile', ['password' => 'Profile-New-Pass-2026', 'current_password' => 'Brand-New-Pass-2026']);
$check('profile: password change with correct current password', $code === 200 && $login('test.staff', 'Profile-New-Pass-2026')[1] === 200, $code);

// ---- History and audit
$check('request-processing history untouched', (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn() === 1);
$audit = implode("\n", $pdo->query("SELECT action FROM audit_log WHERE action LIKE 'Internal %'")->fetchAll(PDO::FETCH_COLUMN));
$check('audit lists changed fields, never values', str_contains($audit, 'password') && !str_contains($audit, '@') && !str_contains($audit, 'Pass-2026'), $audit);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . substr($detail, 0, 200) : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
