<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function sa_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('SMART_ASSESS_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

sa_start_session();

/** True when $table has $column (cached per request). Lets new code work
 *  before and after an additive migration has been applied. */
function sa_has_column(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $stmt = sa_db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$key];
}

function sa_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') !== false && getenv('DB_HOST') !== '' ? getenv('DB_HOST') : '127.0.0.1';
    $port = getenv('DB_PORT') !== false && getenv('DB_PORT') !== '' ? getenv('DB_PORT') : '3307';
    $name = getenv('DB_NAME') !== false && getenv('DB_NAME') !== '' ? getenv('DB_NAME') : 'smart_assess';
    $user = getenv('DB_USER') !== false && getenv('DB_USER') !== '' ? getenv('DB_USER') : 'root';
    $password = getenv('SMART_ASSESS_DB_PASS');

    if (!preg_match('/^[A-Za-z0-9.:-]+$/', (string) $host) ||
        !preg_match('/^[A-Za-z0-9_]+$/', (string) $name) ||
        !ctype_digit((string) $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Invalid database connection configuration.');
    }
    if ($password === false) {
        throw new RuntimeException('SMART_ASSESS_DB_PASS is not configured.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $name);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    return $pdo;
}

function sa_csrf_token(): string
{
    if (empty($_SESSION['sa_csrf']) || !is_string($_SESSION['sa_csrf'])) {
        $_SESSION['sa_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sa_csrf'];
}

function sa_verify_csrf(?string $token): void
{
    if ($token === null || !hash_equals(sa_csrf_token(), $token)) {
        throw new SaHttpException(403, 'Request could not be completed.');
    }
}

function sa_e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sa_json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

class SaHttpException extends RuntimeException
{
    public function __construct(public readonly int $statusCode, string $message = 'Request could not be completed.')
    {
        parent::__construct($message);
    }
}

function sa_current_internal(): ?array
{
    $id = filter_var($_SESSION['sa_internal_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        return null;
    }

    $stmt = sa_db()->prepare(
        "SELECT u.id, u.role_id, u.name, u.username, u.email, u.contact_number, u.position_title,
                u.status, r.name AS role
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id AND u.status = 'Active'
           AND u.archived_at IS NULL AND u.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute(['id' => $id]);
    $account = $stmt->fetch();
    if (!$account || !in_array((int) $account['role_id'], [2, 3, 4], true)) {
        unset($_SESSION['sa_internal_id'], $_SESSION['sa_internal_session_id']);
        return null;
    }
    return $account;
}

function sa_current_client(): ?array
{
    $id = filter_var($_SESSION['sa_client_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        return null;
    }
    $stmt = sa_db()->prepare(
        "SELECT id, first_name, last_name, email, contact_number, status
         FROM clients WHERE id = :id AND status = 'Active' LIMIT 1"
    );
    $stmt->execute(['id' => $id]);
    $client = $stmt->fetch();
    if (!$client) {
        unset($_SESSION['sa_client_id'], $_SESSION['sa_client_session_id']);
        return null;
    }
    return $client;
}

/** Announcement schedules are Philippine Standard Time wall-clock values. */
function sa_schedule_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

/** Publish scheduled announcements whose Philippine time has come. The
 *  status condition makes this idempotent, so nothing publishes twice. */
function sa_publish_due_announcements(): void
{
    sa_db()->prepare(
        "UPDATE announcements SET status = 'Published', published_at = scheduled_at, scheduled_at = NULL
         WHERE status = 'Scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= :now"
    )->execute(['now' => (new DateTimeImmutable('now', sa_schedule_timezone()))->format('Y-m-d H:i:s')]);
}

function sa_public_bootstrap(): array
{
    $pdo = sa_db();
    sa_publish_due_announcements();
    $settings = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $announcements = $pdo->query(
        "SELECT id, title, body, audience, start_date, end_date, image_url, published_at
         FROM announcements
         WHERE status = 'Published' AND audience IN ('client', 'both')
           AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY COALESCE(published_at, created_at) DESC"
    )->fetchAll();
    return [
        'csrf' => sa_csrf_token(),
        'settings' => $settings,
        'document_types' => $pdo->query(
            'SELECT id, name, description FROM document_types WHERE active = 1 ORDER BY name'
        )->fetchAll(),
        'transfer_types' => $pdo->query(
            'SELECT id, name, description FROM transfer_types WHERE active = 1 ORDER BY name'
        )->fetchAll(),
        'announcements' => $announcements,
        'client' => sa_current_client(),
    ];
}

function sa_track_request(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 20) {
        return null;
    }
    $stmt = sa_db()->prepare(
        'SELECT id, reference_no, flow, first_name, last_name, document_type, transfer_type,
                purpose, status, requirement_complete, advisory, created_at
         FROM requests WHERE reference_no = :ref LIMIT 1'
    );
    $stmt->execute(['ref' => $ref]);
    $request = $stmt->fetch();
    if (!$request) {
        return null;
    }
    $logs = sa_db()->prepare(
        'SELECT status, actor, created_at FROM request_status_log
         WHERE request_id = :id ORDER BY created_at ASC, id ASC'
    );
    $logs->execute(['id' => $request['id']]);
    $request['history'] = $logs->fetchAll();
    return $request;
}

function sa_internal_bootstrap(): array
{
    $pdo = sa_db();
    $user = sa_current_internal();
    if (!$user) {
        return ['csrf' => sa_csrf_token(), 'user' => null];
    }
    $roleId = (int) $user['role_id'];
    $data = ['csrf' => sa_csrf_token(), 'user' => $user];

    $data['requests'] = $pdo->query(
        'SELECT r.id, r.reference_no, r.flow, r.first_name, r.last_name, r.email, r.contact_number,
                r.middle_name, r.address_line, r.province, r.city, r.zip_code,
                r.document_type, r.transfer_type, r.purpose, r.arp_number, r.property_address,
                r.barangay, r.is_owner, r.status, r.requirement_complete, r.advisory, r.created_at,
                r.processing_started_at, UNIX_TIMESTAMP(r.processing_started_at) AS processing_started_epoch,
                r.processing_completed_at, UNIX_TIMESTAMP(r.processing_completed_at) AS processing_completed_epoch,
                r.processing_duration_seconds,
                r.processing_staff_id, r.processing_staff_name,
                c.first_name AS client_first_name,
                c.last_name AS client_last_name
         FROM requests r LEFT JOIN clients c ON c.id = r.client_id
         ORDER BY r.created_at DESC, r.id DESC LIMIT 500'
    )->fetchAll();
    $data['documents'] = $pdo->query(
        'SELECT rd.id, rd.request_id, rd.doc_key, rd.label, rd.original_name, rd.mime_type,
                rd.file_size, rd.file_status, dv.validation_status, dv.remarks, dv.validated_at
         FROM request_documents rd LEFT JOIN document_validation dv ON dv.request_document_id = rd.id
         JOIN (SELECT id FROM requests ORDER BY created_at DESC LIMIT 500) recent
           ON recent.id = rd.request_id
         ORDER BY rd.request_id DESC, rd.id'
    )->fetchAll();

    if ($roleId === 3) {
        $data['accounts'] = $pdo->query(
            "SELECT u.id, u.role_id, r.name AS role, u.name, u.username, u.email, u.contact_number,
                    u.position_title, u.status, u.created_at
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.archived_at IS NULL AND u.deleted_at IS NULL
             ORDER BY u.name"
        )->fetchAll();
        $data['archives'] = $pdo->query(
            "SELECT u.id, u.role_id, r.name AS role, u.name, u.username, u.email, u.status,
                    u.archived_at, u.deleted_at
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.archived_at IS NOT NULL OR u.deleted_at IS NOT NULL
             ORDER BY COALESCE(u.deleted_at, u.archived_at) DESC"
        )->fetchAll();
        $data['audit_logs'] = $pdo->query(
            'SELECT id, actor_type, actor_name, action, target, created_at
             FROM audit_log ORDER BY created_at DESC, id DESC LIMIT 200'
        )->fetchAll();
    }

    if ($roleId === 4) {
        sa_publish_due_announcements();
        $data['announcements'] = $pdo->query(
            'SELECT id, title, body, author, audience, start_date, end_date, image_url,
                    status, scheduled_at, published_at, created_at
             FROM announcements ORDER BY created_at DESC, id DESC LIMIT 200'
        )->fetchAll();
        $data['reports'] = [
            'total_requests' => (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn(),
            'by_status' => $pdo->query(
                'SELECT status, COUNT(*) AS total FROM requests GROUP BY status ORDER BY status'
            )->fetchAll(),
            'by_flow' => $pdo->query(
                'SELECT flow, COUNT(*) AS total FROM requests GROUP BY flow ORDER BY flow'
            )->fetchAll(),
            'recent_requests' => $data['requests'],
        ];
    }

    $smsCategory = sa_has_column('request_status_log', 'sms_category') ? 'l.sms_category' : 'NULL';
    $notificationsQuery = $pdo->prepare(
        'SELECT l.id, l.request_id, l.status, l.actor, l.sms_body AS message,
                ' . $smsCategory . ' AS sms_category, l.created_at, r.reference_no,
                NULLIF(TRIM(CONCAT_WS(
                    \' \', NULLIF(c.first_name, \'\'), NULLIF(c.last_name, \'\')
                )), \'\') AS client_name
         FROM request_status_log l
         LEFT JOIN requests r ON r.id = l.request_id
         LEFT JOIN clients c ON c.id = r.client_id
         ORDER BY l.created_at DESC, l.id DESC LIMIT 200'
    );
    $notificationsQuery->execute();
    $data['notifications'] = $notificationsQuery->fetchAll();
    return $data;
}
