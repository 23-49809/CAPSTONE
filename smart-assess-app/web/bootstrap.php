<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/app/services/AiVerification.php';

function sa_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    // Keep sessions for a working day of composing; the 24-minute PHP default
    // expired sessions mid-task and every later save failed CSRF.
    ini_set('session.gc_maxlifetime', '28800');
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

// Web requests only: command-line scripts (the AI dispatcher, draft cleanup) need no
// session, and starting one there would write session files or, under the
// dispatcher's read-only systemd sandbox, log warnings on every run.
if (PHP_SAPI !== 'cli') {
    sa_start_session();
}

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

/**
 * True once database update 20261113_notification_delivery_tracking.sql has run:
 * notifications then record IPROG's message ID and status, link to their history
 * row, and carry the send state of automatic submission confirmations. Before
 * that, everything works as it did and no confirmation is queued.
 */
function sa_notification_tracking_available(): bool
{
    foreach (['provider_message_id', 'provider_status', 'status_log_id', 'send_state'] as $column) {
        if (!sa_has_column('notifications', $column)) {
            return false;
        }
    }
    return true;
}

/** A provider value safe to store: printable ASCII only, at most $max characters, null when empty. */
function sa_provider_value(mixed $value, int $max): ?string
{
    if (!is_scalar($value)) {
        return null;
    }
    $clean = substr((string) preg_replace('/[^\x21-\x7E]/', '', (string) $value), 0, $max);
    return $clean === '' ? null : $clean;
}

/** True when $table.$column accepts NULL (cached per request). */
function sa_column_nullable(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $stmt = sa_db()->prepare(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        $cache[$key] = strtoupper((string) $stmt->fetchColumn()) === 'YES';
    }
    return $cache[$key];
}

function sa_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = sa_db_connect();
    return $pdo;
}

/**
 * A new database connection with the same settings as sa_db(). Used on its own only to
 * find out, after a failed commit, whether the submission was saved (sa_commit_submission()).
 */
function sa_db_connect(): PDO
{
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
    // Store DATETIME defaults such as requests.created_at in Philippine time.
    $pdo->exec("SET time_zone = '+08:00'");
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
        throw new SaHttpException(403, 'Your session has expired or this page is out of date. Reload the page, sign in if asked, and try again.');
    }
}

/*
 * Deployment maintenance gate. While the deploy script copies files it creates
 * this flag next to the served pages (outside the application folder). Requests
 * that change data are then refused with HTTP 503 (see api.php), so nothing is
 * saved against a half-updated application; pages and read-only requests keep
 * working.
 *
 * Fail closed: the flag blocks for as long as it exists, whatever its age. Only
 * a verified deployment, a verified rollback, or the recovery tool
 * (smart-assess-maintenance.sh clear, which checks consistency first) removes
 * it. A deployment killed mid-copy (kill -9, power loss) therefore leaves
 * changes paused instead of accepting them against mixed files. A flag older
 * than SA_MAINTENANCE_STALE_AFTER is logged so an administrator can recover.
 */
const SA_MAINTENANCE_FLAG = '.smart-assess-maintenance';
const SA_MAINTENANCE_STALE_AFTER = 900;   // seconds; a deployment holds the gate for well under a minute
const SA_MAINTENANCE_MESSAGE = 'SMART ASSESS is being updated. Please try again in a minute. Nothing you entered has been lost.';

function sa_maintenance_flag_path(): string
{
    return dirname(__DIR__, 2) . '/' . SA_MAINTENANCE_FLAG;
}

function sa_maintenance_active(): bool
{
    static $staleLogged = false;
    $path = sa_maintenance_flag_path();
    clearstatcache(true, $path);
    $modified = @filemtime($path);
    if ($modified === false) {
        return file_exists($path);   // exists but unreadable time: still blocks
    }
    $age = time() - $modified;
    if (!$staleLogged && ($age > SA_MAINTENANCE_STALE_AFTER || $age < -60)) {
        $staleLogged = true;
        error_log('[SMART_ASSESS] The deployment maintenance flag is ' . $age . ' s old: a deployment may have been interrupted. '
            . 'Changes stay paused until it is recovered: run "smart-assess-maintenance.sh status", then redeploy or "smart-assess-maintenance.sh clear".');
    }
    return true;
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
    /** $errorCode: optional machine-readable reason sent as "code" in the JSON
     *  error response, so the page can react (e.g. "terms_outdated"). */
    public function __construct(
        public readonly int $statusCode,
        string $message = 'Request could not be completed.',
        public readonly ?string $errorCode = null
    ) {
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

/** Current Asia/Manila wall time, the convention all announcement timestamps use. */
function sa_manila_now(): string
{
    return (new DateTimeImmutable('now', sa_schedule_timezone()))->format('Y-m-d H:i:s');
}

/** Client-facing announcements disappear this many hours after publishing. */
const SA_CLIENT_ANNOUNCEMENT_HOURS = 24;

/**
 * Single source of truth for when an announcement is visible.
 * Returns [whereSql, expiresSql] for the audience ('client' or 'staff'); bind
 * :vis_now, :vis_now2 and :vis_now3 (sa_manila_now()).
 *
 * Client expiry is automatic: 24 hours after the moment the announcement went
 * live. published_at holds that moment for both immediate sends (publish time)
 * and scheduled sends (sa_publish_due_announcements copies scheduled_at into
 * it), so a scheduled post expires 24 hours after its send time, not after it
 * was written. Still-scheduled rows use scheduled_at so the Head can see the
 * coming expiry. Everything is computed from stored timestamps in SQL, so
 * expired rows cannot reappear from cached pages or frontend-only filtering,
 * and no row is ever deleted.
 */
function sa_announcement_visibility_sql(string $audience): array
{
    $published = 'COALESCE(published_at, created_at)';
    $liveFrom = 'COALESCE(published_at, scheduled_at, created_at)';
    $dayEnd = "TIMESTAMP(end_date, '23:59:59')";
    $audiences = $audience === 'client' ? "('client', 'both')" : "('staff', 'both')";
    $clientEnd = "DATE_ADD({$liveFrom}, INTERVAL " . SA_CLIENT_ANNOUNCEMENT_HOURS . ' HOUR)';
    // Both branches stay plain DATETIME values ("Y-m-d H:i:s"), which the pages
    // parse as Philippine time.
    $expires = $audience === 'client'
        ? "IF(end_date IS NULL, {$clientEnd}, LEAST({$dayEnd}, {$clientEnd}))"
        : $dayEnd;
    $where = "status = 'Published' AND audience IN {$audiences}
           AND {$published} <= :vis_now
           AND start_date <= DATE(:vis_now2)
           AND ({$expires} IS NULL OR {$expires} > :vis_now3)";
    return [$where, $expires];
}

/** Published announcements currently visible to the audience, newest first. */
function sa_visible_announcements(string $audience, string $columns): array
{
    sa_publish_due_announcements();
    [$where, $expires] = sa_announcement_visibility_sql($audience);
    $stmt = sa_db()->prepare(
        "SELECT {$columns}, {$expires} AS expires_at FROM announcements
         WHERE {$where}
         ORDER BY COALESCE(published_at, created_at) DESC"
    );
    $now = sa_manila_now();
    $stmt->execute(['vis_now' => $now, 'vis_now2' => $now, 'vis_now3' => $now]);
    return $stmt->fetchAll();
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
    $announcements = sa_visible_announcements(
        'client',
        'id, title, body, audience, start_date, end_date, image_url, published_at'
    );
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
    // Only the browser session that submitted the request sees the applicant's full
    // name; anyone else who has the reference number sees initials. The separate
    // name fields are removed so no tracking response can carry the full name.
    $owned = sa_session_submitted((string) $request['reference_no']);
    $request['applicant'] = $owned
        ? trim((string) $request['first_name'] . ' ' . (string) $request['last_name'])
        : sa_name_initials((string) $request['first_name'], (string) $request['last_name']);
    $request['applicant_initials_only'] = !$owned;
    unset($request['first_name'], $request['last_name']);
    return $request;
}

/** How many references one browser session remembers (oldest dropped first). */
const SA_SESSION_SUBMITTED_LIMIT = 20;

/*
 * Shared computers: the full name is shown to the submitting browser only for a
 * short time (default 15 minutes, enough to view and save the receipt), and the
 * page ends it sooner when the applicant leaves the receipt ("receipt_done").
 * After that the same browser, like any other, sees initials, so the next person
 * using the computer cannot reopen the receipt from the browser history.
 */
const SA_RECEIPT_NAME_DEFAULT_SECONDS = 900;

/** How long, in seconds, the submitting browser sees the full name (1 to 3600; invalid -> default). */
function sa_receipt_name_seconds(): int
{
    $value = getenv('SMART_ASSESS_RECEIPT_NAME_SECONDS');
    if ($value === false || !preg_match('/^\d{1,4}$/', trim($value))) {
        return SA_RECEIPT_NAME_DEFAULT_SECONDS;
    }
    $seconds = (int) trim($value);
    return $seconds >= 1 && $seconds <= 3600 ? $seconds : SA_RECEIPT_NAME_DEFAULT_SECONDS;
}

/** This session's still-valid submitted references: reference => time submitted. Expired ones are dropped. */
function sa_session_submitted_references(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $oldest = time() - sa_receipt_name_seconds();
    $references = [];
    foreach ((array) ($_SESSION['sa_submitted_refs'] ?? []) as $reference => $submittedAt) {
        if (is_string($reference) && $reference !== '' && is_int($submittedAt) && $submittedAt >= $oldest) {
            $references[$reference] = $submittedAt;
        }
    }
    $_SESSION['sa_submitted_refs'] = $references;
    return $references;
}

/** Records that this browser session submitted $reference (called only for a new submission). */
function sa_remember_submitted_reference(string $reference): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || $reference === '') {
        return;
    }
    $references = sa_session_submitted_references();
    unset($references[$reference]);
    $references[$reference] = time();
    $_SESSION['sa_submitted_refs'] = array_slice($references, -SA_SESSION_SUBMITTED_LIMIT, null, true);
}

/** The applicant has left the receipt: from now on this browser sees initials too. True when it was remembered. */
function sa_forget_submitted_reference(string $reference): bool
{
    $references = sa_session_submitted_references();
    if (!isset($references[$reference])) {
        return false;
    }
    unset($references[$reference]);
    $_SESSION['sa_submitted_refs'] = $references;
    return true;
}

/** True when this browser session submitted $reference within the time window (stored reference, exact match). */
function sa_session_submitted(string $reference): bool
{
    return $reference !== '' && isset(sa_session_submitted_references()[$reference]);
}

/** "Juan Dela Cruz" -> "J. D."; the first letter of the first and last name only. */
function sa_name_initials(string $firstName, string $lastName): string
{
    $initial = static function (string $name): string {
        $name = trim($name);
        return $name === '' ? '' : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . '.';
    };
    $initials = trim($initial($firstName) . ' ' . $initial($lastName));
    return $initials === '' ? '—' : $initials;
}

/**
 * Applicant contact details (phone, email, home address, client account name)
 * and uploaded file names are for Staff, who process requests and contact
 * applicants. Admin and Department Head get request records without them.
 */
function sa_can_view_applicant_contact(array $user): bool
{
    return (int) ($user['role_id'] ?? 0) === 2;
}

/** Email addresses and phone numbers in free text (such as an audit entry), masked. */
function sa_redact_contact(?string $text): ?string
{
    if ($text === null) {
        return null;
    }
    $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email hidden]', $text);
    return (string) preg_replace('/(?<!\d)(?:\+?63|0)?[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/', '[phone hidden]', $text);
}

/** Last 4 digits only: enough for staff to recognise the number in a result or history entry. */
function sa_mask_phone(string $phone): string
{
    $digits = (string) preg_replace('/\D+/', '', $phone);
    return strlen($digits) >= 4 ? '…' . substr($digits, -4) : 'no number on file';
}

/** First letter and domain only. */
function sa_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    return $at === false || $at === 0 ? 'no email on file' : $email[0] . '•••' . substr($email, $at);
}

/**
 * Staff notification history, newest first. The name and destination come from
 * the request itself, because that is who a staff message goes to: a request
 * need not have a client account, and an account holder may be someone else.
 * Contact fields never change after submission, so the request's values are the
 * ones used. Only masked values leave this function, and only for Staff.
 */
function sa_notification_history(PDO $pdo, bool $withDestination): array
{
    $smsCategory = sa_has_column('request_status_log', 'sms_category') ? 'l.sms_category' : 'NULL';
    $deliveryStatus = sa_has_column('request_status_log', 'delivery_status') ? 'l.delivery_status' : 'NULL';
    $hasSentBy = sa_has_column('request_status_log', 'sms_sent_by_id');
    $tracking = sa_notification_tracking_available();
    $query = $pdo->prepare(
        'SELECT l.id, l.request_id, l.status, l.actor, l.sms_body AS message,
                ' . $smsCategory . ' AS sms_category, ' . $deliveryStatus . ' AS delivery_status,
                ' . ($tracking ? 'n.provider_message_id, n.provider_status' : 'NULL AS provider_message_id, NULL AS provider_status') . ',
                ' . ($hasSentBy ? 'u.name' : 'NULL') . ' AS sent_by_name, l.created_at, r.reference_no,
                r.contact_number AS recipient_phone, r.email AS recipient_email,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(\' \', NULLIF(r.first_name, \'\'), NULLIF(r.last_name, \'\'))), \'\'),
                    NULLIF(TRIM(CONCAT_WS(\' \', NULLIF(c.first_name, \'\'), NULLIF(c.last_name, \'\'))), \'\')
                ) AS client_name
         FROM request_status_log l
         LEFT JOIN requests r ON r.id = l.request_id
         LEFT JOIN clients c ON c.id = r.client_id
         ' . ($hasSentBy ? 'LEFT JOIN users u ON u.id = l.sms_sent_by_id' : '') . '
         ' . ($tracking ? 'LEFT JOIN notifications n ON n.status_log_id = l.id' : '') . '
         ORDER BY l.created_at DESC, l.id DESC LIMIT 200'
    );
    $query->execute();
    return array_map(static function (array $row) use ($withDestination): array {
        $isEmail = in_array($row['actor'], ['email', 'auto_email'], true);
        $row['destination'] = !$withDestination || (string) ($row['delivery_status'] ?? '') === '' ? ''
            : ($isEmail ? sa_mask_email((string) $row['recipient_email']) : sa_mask_phone((string) $row['recipient_phone']));
        if (!$withDestination) {
            $row['provider_message_id'] = $row['provider_status'] = null;   // Staff only, like the destination
        }
        unset($row['recipient_phone'], $row['recipient_email']);
        return $row;
    }, $query->fetchAll());
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
    // Contact columns are never selected for other roles, so no page or API
    // response built from this data can contain them.
    $withContact = sa_can_view_applicant_contact($user);

    $data['requests'] = $pdo->query(
        'SELECT r.id, r.reference_no, r.flow, r.first_name, r.last_name,
                ' . ($withContact ? 'r.email, r.contact_number, r.address_line, r.province, r.city, r.zip_code,' : '') . '
                r.middle_name,
                r.document_type, r.transfer_type, r.purpose, r.arp_number, r.property_address,
                r.barangay, r.is_owner, r.status, r.requirement_complete, r.advisory, r.created_at,
                r.processing_started_at, UNIX_TIMESTAMP(r.processing_started_at) AS processing_started_epoch,
                r.processing_completed_at, UNIX_TIMESTAMP(r.processing_completed_at) AS processing_completed_epoch,
                r.processing_duration_seconds,
                r.processing_staff_id, r.processing_staff_name
                ' . ($withContact ? ', c.first_name AS client_first_name, c.last_name AS client_last_name' : '') . '
         FROM requests r ' . ($withContact ? 'LEFT JOIN clients c ON c.id = r.client_id' : '') . '
         ORDER BY r.created_at DESC, r.id DESC LIMIT 500'
    )->fetchAll();
    $data['documents'] = $pdo->query(
        'SELECT rd.id, rd.request_id, rd.doc_key, rd.label, ' . ($withContact ? 'rd.original_name, ' : '') . 'rd.mime_type,
                rd.file_size, rd.file_status, dv.validation_status, dv.remarks, dv.validated_at
         FROM request_documents rd LEFT JOIN document_validation dv ON dv.request_document_id = rd.id
         JOIN (SELECT id FROM requests ORDER BY created_at DESC LIMIT 500) recent
           ON recent.id = rd.request_id
         ORDER BY rd.request_id DESC, rd.id'
    )->fetchAll();
    if ($roleId === 2 && $data['documents'] !== []) {
        // Staff only: whether each original can actually be opened (the same check
        // request-file.php and the AI review gate use). Only a yes/no leaves PHP, never the path.
        $paths = [];
        foreach ($pdo->query('SELECT rd.id, rd.stored_path FROM request_documents rd
                 JOIN (SELECT id FROM requests ORDER BY created_at DESC LIMIT 500) recent ON recent.id = rd.request_id')->fetchAll() as $row) {
            $paths[(int) $row['id']] = $row['stored_path'] === null ? null : (string) $row['stored_path'];
        }
        // The full check (including the file's type) only where a review depends on it: requests
        // whose AI check is finished and not yet reviewed. For the rest a cheaper check (stored path,
        // inside the uploads folder, present and readable) is enough for this hint; every file was
        // type-checked at upload, and opening one (request-file.php) always checks fully.
        $reviewPending = [];
        if (AiVerification::available($pdo)) {
            foreach ($pdo->query("SELECT request_id FROM ai_verification_jobs WHERE status IN ('completed', 'needs_manual_review')
                     AND staff_review_status = 'not_reviewed'")->fetchAll(PDO::FETCH_COLUMN) as $pendingId) {
                $reviewPending[(int) $pendingId] = true;
            }
        }
        $appRoot = dirname(__DIR__);
        foreach ($data['documents'] as &$document) {
            $document['file_available'] = AiVerification::originalFile($appRoot, $paths[(int) $document['id']] ?? null, (string) $document['file_status'],
                isset($reviewPending[(int) $document['request_id']])) !== null;
        }
        unset($document);
    }

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
        // Older entries recorded a client's email as the target; never show it.
        $data['audit_logs'] = array_map(static function (array $entry): array {
            // A client's name is applicant information; Admin sees the account number instead.
            if ($entry['actor_type'] === 'client') {
                $entry['actor_name'] = 'Client account #' . (int) $entry['actor_id'];
            }
            $entry['action'] = sa_redact_contact($entry['action']);
            $entry['target'] = sa_redact_contact($entry['target']);
            return $entry;
        }, $pdo->query(
            'SELECT id, actor_type, actor_id, actor_name, action, target, created_at
             FROM audit_log ORDER BY created_at DESC, id DESC LIMIT 200'
        )->fetchAll());
    }

    if ($roleId === 4) {
        sa_publish_due_announcements();
        // The Head's list keeps every announcement (history/audit); each row
        // carries its staff/client expiry so expired ones are marked, not deleted.
        [, $staffExpires] = sa_announcement_visibility_sql('staff');
        [, $clientExpires] = sa_announcement_visibility_sql('client');
        $data['announcements'] = $pdo->query(
            "SELECT id, title, body, author, audience, start_date, end_date, image_url,
                    status, scheduled_at, published_at, created_at,
                    {$staffExpires} AS staff_expires_at, {$clientExpires} AS client_expires_at
             FROM announcements ORDER BY created_at DESC, id DESC LIMIT 200"
        )->fetchAll();
        $data['server_now'] = sa_manila_now();
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

    // Notification history is the Staff Notifications page; no other role has it.
    if (!$withContact) {
        $data['notifications'] = [];
        return $data;
    }
    // Staff notification history (only Staff reach this point: see the early return above).
    $data['notifications'] = sa_notification_history($pdo, $roleId === 2);

    // AI document checks are applicant information: Staff (role 2) only, checked
    // here explicitly so it holds whatever else this function returns per role.
    if ($roleId === 2) {
        $requestIds = array_map('intval', array_column($data['requests'], 'id'));
        $data['ai_verifications'] = AiVerification::staffViews($pdo, $requestIds);
        // Whether each request may be checked by AI (Privacy Policy effective date).
        $data['ai_eligibility'] = AiVerification::eligibility($pdo, $requestIds);
    }
    return $data;
}
