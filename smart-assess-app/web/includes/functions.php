<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/icons.php';

function esc(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

const INTERNAL_POSITION_OPTIONS = ['Assessor Admin', 'Assessor Head', 'Assessment Clerk', 'Records Officer'];

function internal_contact_digits(?string $value): string
{
    $digits = preg_replace('/\D+/', '', $value ?? '');
    if (strlen($digits) > 10 && substr($digits, 0, 2) === '63') $digits = substr($digits, 2);
    elseif (strlen($digits) > 10 && substr($digits, 0, 1) === '0') $digits = substr($digits, 1);
    return substr($digits, 0, 10);
}

function canonical_internal_contact(?string $digits): ?string
{
    return preg_match('/^9\d{9}$/', $digits ?? '') ? '+63' . $digits : null;
}

function format_internal_contact(?string $stored): string
{
    $digits = internal_contact_digits($stored);
    return preg_match('/^9\d{9}$/', $digits) ? '+63 ' . $digits : ($stored ?: '—');
}

function internal_contact_control(?string $stored = null, ?string $formId = null, ?string $inputId = null): string
{
    $form = $formId ? ' form="' . esc($formId) . '"' : '';
    $id = $inputId ? ' id="' . esc($inputId) . '"' : '';
    return '<div class="contact-number-input"><span class="contact-prefix" aria-hidden="true">+63</span>'
        . '<input' . $id . ' type="tel" name="contact_number" aria-label="Contact Number after +63" value="' . esc(internal_contact_digits($stored)) . '" placeholder="9171234567" inputmode="numeric" autocomplete="tel-national" maxlength="10" pattern="9[0-9]{9}" required data-contact-number' . $form . '>'
        . '</div><div class="field-error" data-contact-error hidden>Contact number must contain exactly 10 digits after +63 and start with 9.</div>';
}

function internal_position_control(?string $selected = null, ?string $formId = null): string
{
    $form = $formId ? ' form="' . esc($formId) . '"' : '';
    $selected = $selected ?? '';
    $legacy = $selected !== '' && !in_array($selected, INTERNAL_POSITION_OPTIONS, true)
        ? '<option value="' . esc($selected) . '" selected>' . esc($selected) . '</option>'
        : '';
    $options = array_map(static function (string $position) use ($selected): string {
        return '<option value="' . esc($position) . '"' . ($selected === $position ? ' selected' : '') . '>' . esc($position) . '</option>';
    }, INTERNAL_POSITION_OPTIONS);
    return '<select name="position_title" aria-label="Position Title" required' . $form . '><option value=""' . ($selected === '' ? ' selected' : '') . '>Select Position</option>'
        . $legacy . implode('', $options) . '</select>';
}

/** Shared by both auth systems (client and internal) — a CSRF token isn't
 *  tied to which portal you're on, just to having an active session. */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . esc(csrf_token()) . '">';
}

function csrf_check(): bool
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    $submitted = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted);
}

function fmt_date(?string $iso): string
{
    if (!$iso) return '—';
    return date('M j, Y', strtotime($iso));
}

function fmt_datetime(?string $iso): string
{
    if (!$iso) return '—';
    return date('M j, Y g:i A', strtotime($iso));
}

const DOC_TYPES = [
    'Certified True Copy of Tax Declaration (CTC-TD)',
    'Certification of No/With Existing Improvement',
    'Certification of Property/No Property Holdings',
    'Certification of No Liens and Encumbrances',
    'Certification of Assessment',
];

const PURPOSES = [
    'Personal Copy', 'For Transfer', 'For Titling',
    'For Building Permit', 'For Reclassification', 'Other Legal Requirement',
];

const TRANSFER_TYPES = ['Sale', 'Donation', 'Estate'];

const LAND_TRANSFER_DOCS = [
    ['key' => 'ctcTdOrTitle', 'label' => 'Certified True Copy of Tax Declaration or Title'],
    ['key' => 'notarialDeed', 'label' => 'Notarial Deed of Sale or Donation'],
    ['key' => 'vicinityMap', 'label' => 'Vicinity Map'],
    ['key' => 'certNoImprovement', 'label' => 'Certification of No Improvement'],
    ['key' => 'taxClearance', 'label' => 'Tax Clearance'],
];

const STATUS_FLOW = ['Received', 'Processing', 'Approved', 'Rejected', 'Out for Release', 'Timed Out'];

/** Statuses that mean "this request is done" — a request already in one
 *  of these never gets auto-flagged Timed Out regardless of how old it is. */
const TERMINAL_STATUSES = ['Approved', 'Rejected', 'Out for Release'];

function status_badge_class(string $status): string
{
    return match ($status) {
        'Received' => 'slate',
        'Processing' => 'amber',
        'Approved' => 'green',
        'Rejected' => 'red',
        'Out for Release' => 'blue',
        'Timed Out' => 'orange',
        default => 'slate',
    };
}

/** Requests > expected processing window, in whole days, per flow —
 *  editable at Admin > Settings. */
function request_processing_days(string $flow): int
{
    $key = $flow === 'docreq' ? 'docreq_processing_days' : 'landtransfer_processing_days';
    $default = $flow === 'docreq' ? '5' : '10';
    return max(1, (int) get_setting($key, $default));
}

/** How long a request has been open, and whether it's past its expected
 *  processing window — shared by the list tables and the detail page so
 *  the figure is computed identically everywhere. */
/**
 * $sinceTimestamp is when the request's CURRENT status actually started —
 * the latest request_status_log entry for it, not necessarily the original
 * submission. That matters: once staff acts on an old, timed-out request,
 * the clock has to restart from that action, or the very next page load
 * would immediately flag it "Timed Out" again and undo what staff just
 * did. Callers fall back to requests.created_at for a request that's
 * never had a status change logged (shouldn't normally happen, since
 * submission itself logs "Received", but kept defensive).
 */
function request_elapsed_info(string $flow, string $sinceTimestamp, string $status): array
{
    $days = (int) floor((time() - strtotime($sinceTimestamp)) / 86400);
    $limit = request_processing_days($flow);
    return [
        'days' => $days,
        'limit' => $limit,
        'overdue' => !in_array($status, TERMINAL_STATUSES, true) && $days >= $limit,
    ];
}

/**
 * Time Stamp Tracking: flags any request still open past its expected
 * processing window as "Timed Out". There's no background cron in this
 * app, so every page that reads from `requests` calls this first — same
 * lazy, idempotent-when-nothing's-due pattern as publish_due_announcements()
 * uses for announcements. Each flip goes through push_status() with
 * actor='system', so it's logged with a real, DB-generated timestamp and
 * stays clearly distinguishable from an actual staff action.
 */
function mark_overdue_requests(PDO $pdo): void
{
    // Measured from each request's latest logged status change (falling
    // back to its own created_at if, somehow, it has no log rows yet) —
    // not its original submission time. See request_elapsed_info() for why.
    $stmt = $pdo->prepare(
        "SELECT r.id, r.reference_no
         FROM requests r
         LEFT JOIN (
           SELECT request_id, MAX(created_at) AS last_action_at
           FROM request_status_log GROUP BY request_id
         ) l ON l.request_id = r.id
         WHERE r.status NOT IN ('Approved','Rejected','Out for Release','Timed Out')
           AND (
             (r.flow = 'docreq' AND COALESCE(l.last_action_at, r.created_at) <= DATE_SUB(NOW(), INTERVAL ? DAY))
             OR (r.flow = 'landtransfer' AND COALESCE(l.last_action_at, r.created_at) <= DATE_SUB(NOW(), INTERVAL ? DAY))
           )"
    );
    $stmt->execute([request_processing_days('docreq'), request_processing_days('landtransfer')]);
    foreach ($stmt->fetchAll() as $row) {
        push_status((int) $row['id'], 'Timed Out', $row['reference_no'], [], 'system');
    }
}

/** Announcements > "Post To" — who the announcement is shown to. */
const ANNOUNCEMENT_AUDIENCES = ['client' => 'Client Interface', 'staff' => 'Staff Interface', 'both' => 'Both'];

function announcement_audience_label(string $audience): string
{
    return ANNOUNCEMENT_AUDIENCES[$audience] ?? $audience;
}

const ANNOUNCEMENT_STATUSES = ['Draft', 'Scheduled', 'Published', 'Cancelled'];

function announcement_status_badge_class(string $status): string
{
    return match ($status) {
        'Draft' => 'slate',
        'Scheduled' => 'amber',
        'Published' => 'green',
        'Cancelled' => 'red',
        default => 'slate',
    };
}

/**
 * Promotes any "Scheduled" announcement whose scheduled_at has arrived to
 * "Published". There's no background cron in this app, so every page that
 * reads from `announcements` calls this first — it's cheap (a single
 * conditional UPDATE) and idempotent when nothing is due.
 */
function publish_due_announcements(PDO $pdo): void
{
    $pdo->prepare(
        "UPDATE announcements SET status = 'Published', published_at = scheduled_at
         WHERE status = 'Scheduled' AND scheduled_at <= NOW()"
    )->execute();
}

/** Reads Admin > Settings (falls back to config.php constants if a key is missing). */
function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

/** Admin > Audit Logs. $actorType is one of client/staff/admin/head/system. */
function audit(string $actorType, ?int $actorId, string $actorName, string $action, ?string $target = null): void
{
    db()->prepare('INSERT INTO audit_log (actor_type, actor_id, actor_name, action, target) VALUES (?,?,?,?,?)')
        ->execute([$actorType, $actorId, $actorName, $action, $target]);
}

function sms_body_for(string $status, string $refNo, array $missing = []): string
{
    $officeName = get_setting('office_name', OFFICE_NAME);
    return match ($status) {
        'Received' => "SMART ASSESS: Your request $refNo has been received by the $officeName.",
        'Processing' => "SMART ASSESS: Your request $refNo is now under review.",
        'Approved' => "SMART ASSESS: Good news! Your request $refNo has been approved.",
        'Rejected' => "SMART ASSESS: Your request $refNo needs corrections. Missing/invalid: " . ($missing ? implode(', ', $missing) : 'see portal for details') . '.',
        'Out for Release' => "SMART ASSESS: Your document for request $refNo is ready for release at the MAO.",
        'Timed Out' => "SMART ASSESS: Your request $refNo has exceeded our expected processing time. We apologize for the delay — our office is prioritizing it now.",
        default => "SMART ASSESS: Your request $refNo status is now $status.",
    };
}

function id_label(string $flow, string $key): string
{
    $labels = [
        'docreq' => [
            'ownerId' => 'Valid Government-Issued ID',
            'requesterId' => 'Valid ID of Requester',
            'authLetter' => 'Authorization Letter',
        ],
        'landtransfer' => [
            'ownerId' => 'Valid ID of Property Owner',
            'requesterId' => 'Valid ID of Requester',
            'authLetter' => 'Authorization Letter',
        ],
    ];
    return $labels[$flow][$key] ?? $key;
}

function required_id_keys(bool $isOwner): array
{
    return $isOwner ? ['ownerId'] : ['ownerId', 'requesterId', 'authLetter'];
}

function validate_personal(array $p): array
{
    $errors = [];
    if (trim($p['first_name'] ?? '') === '') $errors['first_name'] = 'First name is required.';
    if (trim($p['last_name'] ?? '') === '') $errors['last_name'] = 'Last name is required.';

    $contact = trim($p['contact_number'] ?? '');
    if ($contact === '') $errors['contact_number'] = 'Contact number is required.';
    elseif (!preg_match('/^09\d{2}-\d{3}-\d{4}$/', $contact)) $errors['contact_number'] = 'Use format 09XX-XXX-XXXX.';

    $email = trim($p['email'] ?? '');
    if ($email === '') $errors['email'] = 'Email address is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';

    if (trim($p['address_line'] ?? '') === '') $errors['address_line'] = 'House number / street / barangay is required.';
    if (trim($p['province'] ?? '') === '') $errors['province'] = 'Province is required.';
    if (trim($p['city'] ?? '') === '') $errors['city'] = 'City/Municipality is required.';

    $zip = trim($p['zip_code'] ?? '');
    if ($zip === '') $errors['zip_code'] = 'Zip code is required.';
    elseif (!preg_match('/^\d{4}$/', $zip)) $errors['zip_code'] = 'Zip code must be 4 digits.';

    return $errors;
}

function validate_property_common(array $p, bool $requiresPurpose = true, bool $requiresPropertyAddress = true): array
{
    $errors = [];
    if ($requiresPurpose && !in_array($p['purpose'] ?? '', PURPOSES, true)) $errors['purpose'] = 'Please select a purpose.';
    if (trim($p['arp_number'] ?? '') === '') $errors['arp_number'] = 'ARP / Tax Declaration number is required.';
    if ($requiresPropertyAddress && trim($p['property_address'] ?? '') === '') $errors['property_address'] = 'Property address is required.';
    if (trim($p['barangay'] ?? '') === '') $errors['barangay'] = 'Barangay is required.';
    return $errors;
}

/** Reads $_FILES metadata (mime/size) for the given keys WITHOUT saving them
 *  to disk yet — used to ask the AI checker for a verdict before we commit
 *  anything to the database or filesystem. */
function build_uploaded_meta_from_files(array $keys): array
{
    $meta = [];
    foreach ($keys as $key) {
        if (empty($_FILES[$key]['name']) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $tmp = $_FILES[$key]['tmp_name'];
        $meta[$key] = [
            'mime' => (is_uploaded_file($tmp) ? mime_content_type($tmp) : null) ?: 'application/octet-stream',
            'size' => (int) $_FILES[$key]['size'],
        ];
    }
    return $meta;
}

function next_reference_no(string $flow): string
{
    $prefix = $flow === 'docreq' ? 'DR' : 'LT';
    $year = date('Y');
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS n FROM requests WHERE flow = ? AND reference_no LIKE ?"
    );
    $stmt->execute([$flow, $prefix . '-' . $year . '-%']);
    $n = (int) $stmt->fetch()['n'] + 1;
    return sprintf('%s-%s-%05d', $prefix, $year, $n);
}

/**
 * Calls the Django AI Rule-Based Requirement Checker service.
 * Falls back to a local "all missing" result (fail-safe, never silently
 * approves) if the service is unreachable, and reports the failure so the
 * caller can show a clear error instead of a false pass.
 */
function call_ai_checker(array $payload): array
{
    $ch = curl_init(AI_CHECKER_BASE_URL . '/check/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close() is a deprecated no-op as of PHP 8.5 (handles are closed
    // automatically); omitted so this runs cleanly on 8.0-8.5+ alike.

    if ($raw === false || $httpCode !== 200) {
        return [
            'ok' => false,
            'error' => $curlError ?: "AI checker service returned HTTP $httpCode",
            'requirement_complete' => false,
            'checklist' => [],
            'missing' => [],
            'advisory' => null,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'AI checker service returned an unreadable response.',
            'requirement_complete' => false,
            'checklist' => [],
            'missing' => [],
            'advisory' => null,
        ];
    }

    $decoded['ok'] = true;
    return $decoded;
}

/**
 * Pagination helper — given a total row count, clamps the current ?page=
 * value to valid bounds and returns everything a caller needs to both
 * build its LIMIT/OFFSET query and render the .pager control.
 */
function paginate_info(int $total, int $perPage = 25): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    return [
        'page' => $page, 'perPage' => $perPage, 'totalPages' => $totalPages,
        'offset' => ($page - 1) * $perPage, 'total' => $total,
    ];
}

/** Renders the shared .pager control (ported from smart-assess-internal.html's
 *  pagerHtml()), preserving every existing query-string param except page. */
function render_pager(array $info): string
{
    if ($info['total'] <= $info['perPage']) return '';
    $linkFor = function (int $page): string {
        $qs = $_GET;
        $qs['page'] = $page;
        return '?' . http_build_query($qs);
    };
    $from = $info['offset'] + 1;
    $to = min($info['offset'] + $info['perPage'], $info['total']);
    $prevDisabled = $info['page'] <= 1;
    $nextDisabled = $info['page'] >= $info['totalPages'];
    return '<div class="pager"><span>Showing ' . $from . '&ndash;' . $to . ' of ' . $info['total'] . '</span>'
        . '<div class="pbtns">'
        . '<a href="' . esc($linkFor(max(1, $info['page'] - 1))) . '" aria-disabled="' . ($prevDisabled ? 'true' : 'false') . '">' . icon_span('chevLeft', '15px') . '</a>'
        . '<a href="' . esc($linkFor(min($info['totalPages'], $info['page'] + 1))) . '" aria-disabled="' . ($nextDisabled ? 'true' : 'false') . '">' . icon_span('chevRight', '15px') . '</a>'
        . '</div></div>';
}

/** Shared <table> body used by every staff request-list page (dashboard,
 *  document-requests, land-transfers) so the markup only lives once. */
function render_requests_table(array $requests, string $detailBase = '/staff/detail.php'): string
{
    if (!$requests) return '<div class="empty-state">No requests match this filter.</div>';
    $rows = '';
    foreach ($requests as $r) {
        $checkBadge = $r['requirement_complete']
            ? '<span class="badge green">' . icon_span('check', '12px') . ' Complete</span>'
            : '<span class="badge amber">' . icon_span('alert', '12px') . ' Needs review</span>';
        $elapsed = request_elapsed_info($r['flow'], $r['last_action_at'] ?? $r['created_at'], $r['status']);
        $receivedCell = fmt_date($r['created_at'])
            . '<div class="cell-sub' . ($elapsed['overdue'] ? ' overdue' : '') . '">'
            . ($elapsed['overdue']
                ? icon_span('alert', '11px') . ' Overdue by ' . ($elapsed['days'] - $elapsed['limit']) . 'd'
                : $elapsed['days'] . 'd open')
            . '</div>';
        $rows .= '<tr>'
            . '<td class="mono" data-label="Reference No.">' . esc($r['reference_no']) . '</td>'
            . '<td data-label="Applicant">' . esc(trim($r['first_name'] . ' ' . $r['last_name'])) . '</td>'
            . '<td data-label="Service">' . ($r['flow'] === 'docreq' ? 'Document Request' : 'Land Transfer') . '</td>'
            . '<td data-label="Type">' . esc($r['document_type'] ?: $r['transfer_type']) . '</td>'
            . '<td data-label="Barangay">' . esc($r['barangay']) . '</td>'
            . '<td class="mono" data-label="Received">' . $receivedCell . '</td>'
            . '<td data-label="AI Check">' . $checkBadge . '</td>'
            . '<td data-label="Status"><span class="badge ' . status_badge_class($r['status']) . '">' . esc($r['status']) . '</span></td>'
            . '<td class="actions-cell" data-label="Actions"><a class="icon-btn" href="' . esc($detailBase) . '?id=' . (int) $r['id'] . '">' . icon_span('eye', '14px') . ' View</a></td>'
            . '</tr>';
    }
    return '<table><thead><tr><th>Reference No.</th><th>Applicant</th><th>Service</th><th>Type</th><th>Barangay</th><th>Received</th><th>AI Check</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table>';
}

function fetch_request_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) return null;
    return attach_request_children($req);
}

function fetch_request_by_reference(string $refNo): ?array
{
    $stmt = db()->prepare('SELECT * FROM requests WHERE reference_no = ?');
    $stmt->execute([$refNo]);
    $req = $stmt->fetch();
    if (!$req) return null;
    return attach_request_children($req);
}

function attach_request_children(array $req): array
{
    $docStmt = db()->prepare('SELECT * FROM request_documents WHERE request_id = ? ORDER BY id');
    $docStmt->execute([$req['id']]);
    $req['documents'] = $docStmt->fetchAll();

    $logStmt = db()->prepare('SELECT * FROM request_status_log WHERE request_id = ? ORDER BY created_at ASC');
    $logStmt->execute([$req['id']]);
    $req['status_log'] = $logStmt->fetchAll();

    return $req;
}

function push_status(int $requestId, string $status, string $refNo, array $missing = [], string $actor = 'staff'): void
{
    $pdo = db();
    $pdo->prepare('UPDATE requests SET status = ? WHERE id = ?')->execute([$status, $requestId]);
    $body = sms_body_for($status, $refNo, $missing);
    $pdo->prepare('INSERT INTO request_status_log (request_id, status, actor, sms_body) VALUES (?, ?, ?, ?)')
        ->execute([$requestId, $status, $actor, $body]);

    // Real notification, read/unread-tracked — only for requests tied to a
    // registered client account (anonymous submissions have no inbox to
    // deliver to, same as request_status_log's simulated SMS already did).
    $clientId = $pdo->prepare('SELECT client_id FROM requests WHERE id = ?');
    $clientId->execute([$requestId]);
    $clientId = $clientId->fetchColumn();
    if ($clientId) {
        $pdo->prepare('INSERT INTO notifications (client_id, request_id, type, message) VALUES (?,?,?,?)')
            ->execute([(int) $clientId, $requestId, 'status_change', $body]);
    }
}

/** Staff workflow activity — see activity_logs table comment in schema.sql
 *  for how this differs in purpose from audit_log. */
function log_activity(int $userId, string $action, ?int $requestId = null): void
{
    db()->prepare('INSERT INTO activity_logs (user_id, action, request_id) VALUES (?,?,?)')
        ->execute([$userId, $action, $requestId]);
}

/**
 * Saves one uploaded file (from $_FILES) into UPLOAD_DIR, returning its
 * stored metadata. Returns null if no file was submitted for this field.
 */
/**
 * Records the AI checker's per-requirement verdict as a document_validation
 * row — richer, queryable detail alongside request_documents.file_status's
 * single ok/flagged/missing flag. validated_by stays NULL (this is the
 * automatic AI pass, not a human); a future staff manual re-check would
 * set validated_by to record who overrode it.
 */
function record_document_validation(PDO $pdo, int $requestDocumentId, string $fileStatus): void
{
    $status = match ($fileStatus) {
        'ok' => 'valid',
        'flagged' => 'invalid',
        default => 'pending',
    };
    $remarks = match ($fileStatus) {
        'ok' => 'Passed automatic format/size check.',
        'flagged' => 'Uploaded file failed the automatic format/size check.',
        default => 'No file uploaded for this requirement.',
    };
    $pdo->prepare(
        'INSERT INTO document_validation (request_document_id, validation_status, remarks, validated_at) VALUES (?,?,?,NOW())'
    )->execute([$requestDocumentId, $status, $remarks]);
}

function save_uploaded_file(string $fieldKey, string $refNo): ?array
{
    if (empty($_FILES[$fieldKey]['name']) || $_FILES[$fieldKey]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$fieldKey];
    $mime = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = $refNo . '_' . $fieldKey . '_' . substr(sha1(uniqid('', true)), 0, 8) . ($ext ? '.' . $ext : '');

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $dest = UPLOAD_DIR . '/' . $safeName;

    if ($file['error'] === UPLOAD_ERR_OK && move_uploaded_file($file['tmp_name'], $dest)) {
        return [
            'original_name' => $file['name'],
            'stored_path' => 'uploads/' . $safeName,
            'mime' => $mime,
            'size' => $file['size'],
        ];
    }
    return null;
}
