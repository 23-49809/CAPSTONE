<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/icons.php';

function esc(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
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

const STATUS_FLOW = ['Received', 'Processing', 'Approved', 'Rejected', 'Out for Release'];

function status_badge_class(string $status): string
{
    return match ($status) {
        'Received' => 'slate',
        'Processing' => 'amber',
        'Approved' => 'green',
        'Rejected' => 'red',
        'Out for Release' => 'blue',
        default => 'slate',
    };
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
        $rows .= '<tr>'
            . '<td class="mono">' . esc($r['reference_no']) . '</td>'
            . '<td>' . esc(trim($r['first_name'] . ' ' . $r['last_name'])) . '</td>'
            . '<td>' . ($r['flow'] === 'docreq' ? 'Document Request' : 'Land Transfer') . '</td>'
            . '<td>' . esc($r['document_type'] ?: $r['transfer_type']) . '</td>'
            . '<td>' . esc($r['barangay']) . '</td>'
            . '<td class="mono">' . fmt_date($r['created_at']) . '</td>'
            . '<td>' . $checkBadge . '</td>'
            . '<td><span class="badge ' . status_badge_class($r['status']) . '">' . esc($r['status']) . '</span></td>'
            . '<td><a class="icon-btn" href="' . esc($detailBase) . '?id=' . (int) $r['id'] . '">' . icon_span('eye', '14px') . ' View</a></td>'
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

function push_status(int $requestId, string $status, string $refNo, array $missing = []): void
{
    $pdo = db();
    $pdo->prepare('UPDATE requests SET status = ? WHERE id = ?')->execute([$status, $requestId]);
    $body = sms_body_for($status, $refNo, $missing);
    $pdo->prepare('INSERT INTO request_status_log (request_id, status, sms_body) VALUES (?, ?, ?)')
        ->execute([$requestId, $status, $body]);
}

/**
 * Saves one uploaded file (from $_FILES) into UPLOAD_DIR, returning its
 * stored metadata. Returns null if no file was submitted for this field.
 */
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
