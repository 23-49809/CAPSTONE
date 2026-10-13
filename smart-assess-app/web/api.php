<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Short random reference shown to the user and written to the log, so a
 *  reported error can be matched to its diagnostic entry. */
function sa_request_ref(): string
{
    static $ref = null;
    return $ref ??= strtoupper(bin2hex(random_bytes(3)));
}

/** Append one JSON line per failed API call to logs/api-errors.log (outside
 *  the web root). Records field NAMES only; never values, tokens or passwords. */
function sa_log_api_failure(int $status, string $action, Throwable $exception, ?array $data): void
{
    $cookieName = session_name();
    $cookieSid = (string) ($_COOKIE[$cookieName] ?? '');
    $entry = [
        'time' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
        'ref' => sa_request_ref(),
        'status' => $status,
        'action' => $action !== '' ? $action : 'unknown',
        'error' => $exception->getMessage(),
        'session' => [
            'cookie_sent' => $cookieSid !== '',
            'cookie_matches_session' => $cookieSid !== '' && $cookieSid === session_id(),
            'internal_user_id' => isset($_SESSION['sa_internal_id']) ? (int) $_SESSION['sa_internal_id'] : null,
            'client_user_id' => isset($_SESSION['sa_client_id']) ? (int) $_SESSION['sa_client_id'] : null,
            'csrf_sent' => is_array($data) && isset($data['csrf']) && $data['csrf'] !== '',
        ],
        'fields' => is_array($data) ? array_values(array_diff(array_keys($data), ['csrf', 'password'])) : [],
    ];
    if (!($exception instanceof SaHttpException)) {
        $entry['exception'] = get_class($exception);
        $entry['where'] = basename($exception->getFile()) . ':' . $exception->getLine();
        if ($exception instanceof PDOException) {
            $entry['sqlstate'] = (string) $exception->getCode();
        }
    }
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if ($line === false || @file_put_contents($dir . '/api-errors.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log('[SMART_ASSESS] ' . ($line ?: 'unloggable API failure'));
    }
}

/** Safe, actionable text for exceptions that carry no specific message. */
function sa_default_error_message(int $status): string
{
    return match ($status) {
        400 => 'The request was not sent correctly. Reload the page and try again.',
        401 => 'Sign-in failed or your session has ended. Check your details and sign in again.',
        403 => 'You are not allowed to do this, or this page is out of date. Reload the page and try again.',
        404 => 'That item no longer exists or this action is not available. Reload the page and try again.',
        405 => 'The request was not sent correctly. Reload the page and try again.',
        413 => 'The upload is too large.',
        422 => 'Some information is missing or invalid. Check the form and try again.',
        default => 'The server could not complete this request.',
    };
}

function sa_request_data(): array
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        throw new SaHttpException(405);
    }

    if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
        if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new SaHttpException(
                413,
                'The upload request exceeds the PHP server limit. Restart PHP with upload_max_filesize=5M and post_max_size=50M.'
            );
        }
        $data = [];
        if (isset($_POST['payload'])) {
            $decoded = json_decode((string) $_POST['payload'], true);
            if (!is_array($decoded)) {
                throw new SaHttpException(400);
            }
            $data = $decoded;
        }
        foreach ($_POST as $key => $value) {
            if ($key !== 'payload' && !array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($decoded)) {
        throw new SaHttpException(400);
    }
    return $decoded;
}

const SA_NOTIFICATION_CATEGORIES = [
    'Missing Document',
    'Additional Requirements',
    'Request Status Update',
    'Land Transfer Status',
    'Completion / Approval',
    'Other Instruction',
];

/** The SMS category staff picked, or null when none was sent. */
function sa_notification_category(array $data): ?string
{
    $value = trim((string) ($data['category'] ?? ''));
    if ($value === '') {
        return null;
    }
    if (!in_array($value, SA_NOTIFICATION_CATEGORIES, true)) {
        throw new SaHttpException(422);
    }
    return $value;
}

/** Human label for a request field, used in validation messages. */
function sa_field_label(string $key): string
{
    return [
        'topic' => 'Subject (used as the AI topic)',
        'details' => 'Context details',
        'title' => 'Subject',
        'body' => 'Message',
        'message' => 'Message',
        'image_url' => 'Image link',
        'scheduled_at' => 'Schedule date and time',
    ][$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/** Trimmed text field. Limits count characters, matching the form's
 *  maxlength: “smart quotes”, ñ and — are 2-3 bytes each, so a byte limit
 *  rejected text the form itself allowed. A 65,535-byte ceiling (a MySQL
 *  TEXT column) still guards storage. */
function sa_text(array $data, string $key, int $max, bool $required = false): ?string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($required && $value === '') {
        throw new SaHttpException(422, 'Fill in the ' . sa_field_label($key) . '.');
    }
    if ($value === '') {
        return null;
    }
    if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
        throw new SaHttpException(422, sa_field_label($key) . ' contains characters that cannot be saved. Retype it and try again.');
    }
    if (mb_strlen($value, 'UTF-8') > $max || strlen($value) > 65535) {
        throw new SaHttpException(422, sa_field_label($key) . ' must be ' . number_format($max) . ' characters or fewer.');
    }
    return $value;
}

function sa_normalize_role(mixed $role): ?int
{
    $value = strtolower(trim((string) $role));
    return match ($value) {
        '2', 'staff', 'assessor', 'assessor staff' => 2,
        '3', 'admin', 'administrator' => 3,
        '4', 'head', 'department head', 'department_head', 'department-head', 'head of department' => 4,
        default => null,
    };
}

function sa_normalize_request_status(mixed $status): string
{
    $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) $status) ?? ''));
    $statuses = [
        'received' => 'Received',
        'submitted' => 'Received',
        'pending' => 'Received',
        'processing' => 'Processing',
        'in progress' => 'Processing',
        'under review' => 'Processing',
        'in review' => 'Processing',
        'for review' => 'Processing',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'out for release' => 'Out for Release',
        'ready for release' => 'Out for Release',
        'ready for pickup' => 'Out for Release',
        'for pickup' => 'Out for Release',
        'ready' => 'Out for Release',
        'for release' => 'Out for Release',
        'timed out' => 'Timed Out',
        'overdue' => 'Timed Out',
        'expired' => 'Timed Out',
    ];
    if (!isset($statuses[$key])) {
        throw new SaHttpException(422);
    }
    return $statuses[$key];
}

function sa_normalize_bridge_request(array $data): array
{
    $aliases = [
        'name' => ['fullName'],
        'role_id' => ['role'],
        'position_title' => ['position'],
        'contact_number' => ['contact'],
        'user_id' => ['id'],
        'announcement_id' => ['id'],
        'body' => ['content'],
        'audience' => ['postTo'],
        'message' => ['content'],
    ];
    foreach ($aliases as $target => $sources) {
        if (array_key_exists($target, $data)) {
            continue;
        }
        foreach ($sources as $source) {
            if (array_key_exists($source, $data)) {
                $data[$target] = $data[$source];
                break;
            }
        }
    }

    if (isset($data['role_id'])) {
        $roleId = sa_normalize_role($data['role_id']);
        if ($roleId === null) {
            throw new SaHttpException(422);
        }
        if (isset($data['audience'])) {
            $audience = strtolower(trim((string) $data['audience']));
            $data['audience'] = match ($audience) {
                'client', 'clients', 'client only', 'clients only' => 'client',
                'staff', 'staff only' => 'staff',
                'both', 'all', 'everyone', 'all users' => 'both',
                default => $data['audience'],
            };
        }
        $data['role_id'] = $roleId;
    }
    if (!isset($data['request_id']) && isset($data['requestRef'])) {
        $data['request_ref'] = trim((string) $data['requestRef']);
    }
    if (!isset($data['request_id']) &&
        in_array((string) ($data['action'] ?? ''), [
            'update_request_status',
            'send_notification',
            'start_processing',
            'complete_processing',
        ], true) &&
        isset($data['id'])) {
        $bridgeId = trim((string) $data['id']);
        if (ctype_digit($bridgeId)) {
            $data['request_id'] = (int) $bridgeId;
        } elseif (preg_match('/^(?:DR|LT)-\d{4}-[A-Z0-9]{1,12}$/i', $bridgeId)) {
            $data['request_ref'] = strtoupper($bridgeId);
        }
    }
    if (!isset($data['status']) && isset($data['requestStatus'])) {
        $data['status'] = $data['requestStatus'];
    }
    if (isset($data['status']) && in_array((string) ($data['action'] ?? ''), ['update_request_status'], true)) {
        $data['status'] = sa_normalize_request_status($data['status']);
    }
    if (!isset($data['scheduled_at']) && (isset($data['scheduleDate']) || isset($data['scheduleTime']))) {
        $date = trim((string) ($data['scheduleDate'] ?? ''));
        $time = trim((string) ($data['scheduleTime'] ?? ''));
        if ($date !== '' && $time !== '') {
            if (preg_match('/^\d{1,2}:\d{2}\s*[AP]M$/i', $time)) {
                $parsedTime = DateTimeImmutable::createFromFormat('!g:i A', strtoupper($time));
                if (!$parsedTime) {
                    throw new SaHttpException(422, 'Enter the schedule time like 9:05 PM.');
                }
                $time = $parsedTime->format('H:i:s');
            } elseif (strlen($time) === 5) {
                $time .= ':00';
            }
            $data['scheduled_at'] = $date . ' ' . $time;
        }
    }
    if (!isset($data['action'])) {
        return $data;
    }
    $actionMap = [
        'create_account' => 'admin_create_user',
        'edit_account' => 'admin_edit_user',
        'update_profile' => 'internal_update_profile',
        'save_announcement' => 'head_save_announcement',
        'archive_account' => 'admin_archive_user',
        'restore_account' => 'admin_restore_user',
        'delete_account' => 'admin_delete_user',
        'toggle_account' => 'admin_toggle_user',
        'delete_announcement' => 'head_delete_announcement',
        'announcement_transition' => 'head_announcement_transition',
    ];
    $data['action'] = $actionMap[(string) $data['action']] ?? $data['action'];
    if ($data['action'] === 'head_save_announcement') {
        $mode = strtolower(trim((string) ($data['publishMode'] ?? $data['publish_mode'] ?? 'draft')));
        $data['announcement_mode'] = match ($mode) {
            'publish', 'publish now', 'publish_now', 'publish immediately', 'now', 'published' => 'publish',
            'schedule', 'scheduled', 'schedule for later' => 'schedule',
            'draft', 'save draft', 'save' => 'draft',
            default => throw new SaHttpException(422, 'Choose Send, Schedule send, or Save Draft.'),
        };
        if ($data['announcement_mode'] === 'schedule' && !isset($data['scheduled_at'])) {
            throw new SaHttpException(422, 'Pick a date and time to schedule this announcement.');
        }
    }
    if ($data['action'] === 'head_announcement_transition') {
        $data['transition'] = match ((string) ($data['transition'] ?? '')) {
            'ann-publish-now' => 'publish',
            'ann-unpublish' => 'unpublish',
            'ann-cancel' => 'cancel',
            default => throw new SaHttpException(422),
        };
        $data['announcement_id'] = $data['announcement_id'] ?? $data['id'] ?? null;
    }
    if ($data['action'] === 'admin_toggle_user' && !isset($data['user_id'])) {
        $data['user_id'] = $data['id'] ?? null;
    }
    if ($data['action'] === 'head_delete_announcement' && !isset($data['announcement_id'])) {
        $data['announcement_id'] = $data['id'] ?? null;
    }
    if ($data['action'] === 'internal_update_profile' && !isset($data['new_password'])) {
        $data['new_password'] = $data['password'] ?? '';
    }
    return $data;
}

function sa_internal_or_fail(array $roles): array
{
    $user = sa_current_internal();
    if (!$user) {
        throw new SaHttpException(401, 'Your session has ended. Reload the page and sign in again.');
    }
    if (!in_array((string) $user['role'], $roles, true)) {
        throw new SaHttpException(403, 'Your account does not have permission to do this. If you switched accounts in another tab, reload this page.');
    }
    return $user;
}

function sa_client_or_fail(): array
{
    $client = sa_current_client();
    if (!$client) {
        throw new SaHttpException(401);
    }
    return $client;
}

function sa_actor_type(array $user): string
{
    return match ((int) $user['role_id']) {
        2 => 'staff',
        3 => 'admin',
        4 => 'head',
        default => throw new SaHttpException(403),
    };
}

function sa_audit(string $type, ?int $id, string $name, string $action, ?string $target = null): void
{
    $stmt = sa_db()->prepare(
        'INSERT INTO audit_log (actor_type, actor_id, actor_name, action, target)
         VALUES (:type, :id, :name, :action, :target)'
    );
    $stmt->execute(['type' => $type, 'id' => $id, 'name' => $name, 'action' => $action, 'target' => $target]);
}

function sa_user_session(string $type, int $id): int
{
    $stmt = sa_db()->prepare(
        'INSERT INTO user_sessions (user_type, user_id, ip_address, user_agent)
         VALUES (:type, :id, :ip, :agent)'
    );
    $stmt->execute([
        'type' => $type,
        'id' => $id,
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        'agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
    ]);
    return (int) sa_db()->lastInsertId();
}

function sa_close_user_session(string $sessionKey): void
{
    $sessionId = filter_var($_SESSION[$sessionKey] ?? null, FILTER_VALIDATE_INT);
    if ($sessionId && $sessionId > 0) {
        $stmt = sa_db()->prepare('UPDATE user_sessions SET logout_at = NOW() WHERE id = :id AND logout_at IS NULL');
        $stmt->execute(['id' => $sessionId]);
    }
    unset($_SESSION[$sessionKey]);
}

function sa_login_client(array $data): array
{
    $email = strtolower((string) sa_text($data, 'email', 150, true));
    $password = (string) ($data['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 4096) {
        throw new SaHttpException(401);
    }
    $stmt = sa_db()->prepare(
        "SELECT id, first_name, last_name, email, contact_number, password_hash
         FROM clients WHERE email = :email AND status = 'Active' LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $client = $stmt->fetch();
    if (!$client || !password_verify($password, $client['password_hash'])) {
        throw new SaHttpException(401);
    }

    sa_close_user_session('sa_client_session_id');
    sa_close_user_session('sa_internal_session_id');
    unset($_SESSION['sa_client_id'], $_SESSION['sa_internal_id']);
    session_regenerate_id(true);
    $_SESSION['sa_client_id'] = (int) $client['id'];
    $_SESSION['sa_client_session_id'] = sa_user_session('client', (int) $client['id']);
    $name = trim($client['first_name'] . ' ' . $client['last_name']);
    sa_audit('client', (int) $client['id'], $name, 'Client login', $client['email']);
    unset($client['password_hash']);
    return ['client' => $client, 'csrf' => sa_csrf_token()];
}

function sa_register_client(array $data): array
{
    $first = sa_text($data, 'first_name', 80, true);
    $last = sa_text($data, 'last_name', 80, true);
    $email = strtolower((string) sa_text($data, 'email', 150, true));
    $contact = sa_text($data, 'contact_number', 20);
    $password = (string) ($data['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || strlen($password) > 4096) {
        throw new SaHttpException(422);
    }
    $pdo = sa_db();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO clients (role_id, first_name, last_name, email, contact_number, password_hash, status)
             VALUES (1, :first, :last, :email, :contact, :hash, 'Active')"
        );
        $stmt->execute([
            'first' => $first,
            'last' => $last,
            'email' => $email,
            'contact' => $contact,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            throw new SaHttpException(409);
        }
        throw $exception;
    }
    return sa_login_client(['email' => $email, 'password' => $password]);
}

function sa_validate_upload(array $file): array
{
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'The uploaded file exceeds the server upload limit. Reduce its size or restart PHP with the configured upload limits.',
            UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please try uploading it again.',
            UPLOAD_ERR_NO_FILE => 'Select a file for every required document.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION =>
                'The server could not receive the uploaded file. Please try again or contact support.',
            default => 'The uploaded file could not be received. Please try again.',
        };
        throw new SaHttpException(422, $message);
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new SaHttpException(422, 'The uploaded file could not be verified. Please select it again.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) {
        throw new SaHttpException(422, 'Each uploaded file must be 5 MB or smaller.');
    }
    $original = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'upload')));
    $original = preg_replace('/[\x00-\x1F\x7F]/u', '', $original) ?? 'upload';
    $original = substr($original, 0, 255);
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($allowed[$mime]) || $extension !== $allowed[$mime] && !($mime === 'image/jpeg' && $extension === 'jpeg')) {
        throw new SaHttpException(422, 'Upload a JPG, PNG, WEBP, or PDF file with a matching file extension.');
    }
    return [$original, $mime, $size, $allowed[$mime]];
}

function sa_submit_request(array $data): array
{
    $flow = (string) ($data['flow'] ?? '');
    if (!in_array($flow, ['docreq', 'landtransfer'], true)) {
        throw new SaHttpException(422);
    }
    $fields = [
        'first_name' => [80, true],
        'middle_name' => [80, false],
        'last_name' => [80, true],
        'contact_number' => [20, true],
        'email' => [150, true],
        'address_line' => [255, true],
        'province' => [80, false],
        'city' => [80, false],
        'zip_code' => [10, true],
        'purpose' => [80, false],
        'arp_number' => [60, true],
        'property_address' => [255, false],
        'barangay' => [100, true],
    ];
    $values = [];
    foreach ($fields as $key => [$limit, $required]) {
        $values[$key] = sa_text($data, $key, $limit, $required);
    }
    $values['province'] = $values['province'] ?? 'Batangas';
    $values['city'] = $values['city'] ?? 'Mabini';
    $values['purpose'] ??= $flow === 'landtransfer' ? 'Land Transfer' : null;
    $values['property_address'] ??= 'Barangay ' . $values['barangay'] . ' - ARP ' . $values['arp_number'];
    if ($values['purpose'] === null) {
        throw new SaHttpException(422);
    }
    if (!filter_var((string) $values['email'], FILTER_VALIDATE_EMAIL)) {
        throw new SaHttpException(422, 'Enter a valid email address.');
    }
    if (!preg_match('/^\+63\d{10}$/', (string) $values['contact_number'])) {
        throw new SaHttpException(422, 'Enter 10 digits after the +63 contact prefix.');
    }
    if (!preg_match('/^\d{4}$/', (string) $values['zip_code'])) {
        throw new SaHttpException(422, 'Enter a four-digit ZIP code.');
    }
    if (!preg_match('/^14-\d{4}-\d{5}$/', (string) $values['arp_number'])) {
        throw new SaHttpException(422, 'Enter the ARP number in 14-XXXX-XXXXX format.');
    }
    $values['document_type'] = $flow === 'docreq' ? sa_text($data, 'document_type', 120, true) : null;
    $values['transfer_type'] = $flow === 'landtransfer' ? sa_text($data, 'transfer_type', 40, true) : null;
    $values['is_owner'] = filter_var($data['is_owner'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $client = sa_current_client();
    $files = [];
    foreach ($_FILES as $field => $file) {
        if (!str_starts_with((string) $field, 'file_')) {
            continue;
        }
        $key = substr((string) $field, 5);
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $key) || !is_array($file)) {
            throw new SaHttpException(422);
        }
        $files[$key] = $file;
    }
    $documents = [];
    $submittedDocuments = $data['documents'] ?? [];
    if (!is_array($submittedDocuments)) {
        throw new SaHttpException(422);
    }
    foreach ($submittedDocuments as $key => $entry) {
        if (is_int($key) && is_array($entry)) {
            $key = (string) ($entry['doc_key'] ?? '');
        }
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $key)) {
            throw new SaHttpException(422);
        }
        $label = is_array($entry) ? sa_text($entry, 'label', 150, true) : (string) $entry;
        if ($label === '' || strlen($label) > 150) {
            throw new SaHttpException(422);
        }
        $documents[$key] = $label;
    }
    foreach ($files as $key => $_file) {
        $documents[$key] ??= $key;
    }
    if (count($documents) > 40) {
        throw new SaHttpException(422);
    }
    $preparedFiles = [];
    foreach ($files as $key => $file) {
        $preparedFiles[$key] = sa_validate_upload($file);
    }

    $pdo = sa_db();
    $storedFiles = [];
    try {
        $pdo->beginTransaction();
        $reference = strtoupper($flow === 'docreq' ? 'DR' : 'LT') . '-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare(
            "INSERT INTO requests
             (client_id, reference_no, flow, first_name, middle_name, last_name, contact_number, email,
              address_line, province, city, zip_code, document_type, transfer_type, purpose, arp_number,
              property_address, barangay, is_owner, status, requirement_complete)
             VALUES
             (:client_id, :reference_no, :flow, :first_name, :middle_name, :last_name, :contact_number, :email,
              :address_line, :province, :city, :zip_code, :document_type, :transfer_type, :purpose, :arp_number,
              :property_address, :barangay, :is_owner, 'Received', 0)"
        );
        $stmt->execute([
            'client_id' => $client['id'] ?? null,
            'reference_no' => $reference,
            'flow' => $flow,
            'first_name' => $values['first_name'],
            'middle_name' => $values['middle_name'],
            'last_name' => $values['last_name'],
            'contact_number' => $values['contact_number'],
            'email' => $values['email'],
            'address_line' => $values['address_line'],
            'province' => $values['province'],
            'city' => $values['city'],
            'zip_code' => $values['zip_code'],
            'document_type' => $values['document_type'],
            'transfer_type' => $values['transfer_type'],
            'purpose' => $values['purpose'],
            'arp_number' => $values['arp_number'],
            'property_address' => $values['property_address'],
            'barangay' => $values['barangay'],
            'is_owner' => $values['is_owner'],
        ]);
        $requestId = (int) $pdo->lastInsertId();
        $privateBase = dirname(__DIR__) . '/private_uploads';
        $uploadDir = $privateBase . '/requests';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Unable to create upload directory.');
        }
        $realUploadDir = realpath($uploadDir);
        $realBase = realpath($privateBase);
        if ($realUploadDir === false || $realBase === false || !str_starts_with($realUploadDir . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Upload directory is outside the configured location.');
        }

        $documentInsert = $pdo->prepare(
            'INSERT INTO request_documents (request_id, doc_key, label, original_name, stored_path, mime_type, file_size, file_status)
             VALUES (:request_id, :doc_key, :label, :original_name, :stored_path, :mime_type, :file_size, :file_status)'
        );
        $validationInsert = $pdo->prepare(
            "INSERT INTO document_validation (request_document_id, validation_status)
             VALUES (:document_id, 'pending')"
        );
        $missing = 0;
        foreach ($documents as $key => $label) {
            if (isset($files[$key])) {
                [$original, $mime, $size, $extension] = $preparedFiles[$key];
                $storedName = $requestId . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
                $destination = $realUploadDir . DIRECTORY_SEPARATOR . $storedName;
                if (!move_uploaded_file((string) $files[$key]['tmp_name'], $destination)) {
                    throw new RuntimeException('Unable to store uploaded file.');
                }
                chmod($destination, 0640);
                $storedFiles[] = $destination;
                $documentInsert->execute([
                    'request_id' => $requestId,
                    'doc_key' => $key,
                    'label' => $label,
                    'original_name' => $original,
                    'stored_path' => 'private_uploads/requests/' . $storedName,
                    'mime_type' => $mime,
                    'file_size' => $size,
                    'file_status' => 'ok',
                ]);
            } else {
                $missing++;
                $documentInsert->execute([
                    'request_id' => $requestId,
                    'doc_key' => $key,
                    'label' => $label,
                    'original_name' => null,
                    'stored_path' => null,
                    'mime_type' => null,
                    'file_size' => null,
                    'file_status' => 'missing',
                ]);
            }
            $validationInsert->execute(['document_id' => (int) $pdo->lastInsertId()]);
        }
        if (count($documents) > 0 && $missing === 0) {
            $pdo->prepare('UPDATE requests SET requirement_complete = 1 WHERE id = :id')->execute(['id' => $requestId]);
        }
        $initial = $pdo->prepare(
            "INSERT INTO request_status_log (request_id, status, actor, sms_body)
             VALUES (:id, 'Received', 'client', :body)"
        );
        $initial->execute([
            'id' => $requestId,
            'body' => 'Your request ' . $reference . ' has been received.',
        ]);
        if ($client) {
            $notification = $pdo->prepare(
                "INSERT INTO notifications (client_id, request_id, type, message, channel, delivery_status)
                 VALUES (:client_id, :request_id, 'request_received', :message, 'system', 'sent')"
            );
            $notification->execute([
                'client_id' => $client['id'],
                'request_id' => $requestId,
                'message' => 'Your request ' . $reference . ' has been received.',
            ]);
        }
        $pdo->commit();

        // Send confirmation after the database transaction succeeds.
        // Email failures must not invalidate a saved request.
        try {
            $sendConfirmation = require dirname(__DIR__) . '/gmail-mailer.php';
            $sendConfirmation([
                'email' => $values['email'],
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'flow' => $flow,
                'reference_no' => $reference,
            ]);
        } catch (Throwable $emailException) {
            error_log(
                '[SMART_ASSESS] Confirmation email failed for '
                . $reference . ': ' . $emailException->getMessage()
            );
        }

        return ['reference_no' => $reference, 'status' => 'Received'];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($storedFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $exception;
    }
}

function sa_update_request_status(array $data): array
{
    $user = sa_internal_or_fail(['STAFF']);
    $id = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id && isset($data['request_ref'])) {
        $lookup = sa_db()->prepare('SELECT id FROM requests WHERE reference_no = :ref');
        $lookup->execute(['ref' => strtoupper((string) $data['request_ref'])]);
        $id = filter_var($lookup->fetchColumn(), FILTER_VALIDATE_INT);
    }
    $status = (string) ($data['status'] ?? '');
    $validStatuses = ['Received', 'Processing', 'Approved', 'Rejected', 'Out for Release', 'Timed Out'];
    if (!$id || !in_array($status, $validStatuses, true)) {
        throw new SaHttpException(422);
    }
    if ($status === 'Processing') {
        return sa_start_request_processing(['request_id' => $id]);
    }
    if (in_array($status, ['Approved', 'Rejected', 'Out for Release'], true)) {
        return sa_complete_request_processing(['request_id' => $id], $status);
    }
    $advisory = sa_text($data, 'advisory', 2000);
    $pdo = sa_db();
    sa_require_request_row_locks($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, client_id, reference_no, status, processing_started_at, processing_staff_id
             FROM requests WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new SaHttpException(404);
        }
        if ($request['processing_staff_id'] !== null &&
            (int) $request['processing_staff_id'] !== (int) $user['id']) {
            throw new SaHttpException(409, 'This request is assigned to another staff member.');
        }
        if ($status === 'Received' && $request['processing_started_at'] !== null) {
            throw new SaHttpException(409, 'A request cannot return to Pending after processing has started.');
        }
        $pdo->prepare('UPDATE requests SET status = :status, advisory = :advisory WHERE id = :id')
            ->execute(['status' => $status, 'advisory' => $advisory, 'id' => $id]);
        $body = 'The status of request ' . $request['reference_no'] . ' is now ' . $status . '.';
        $pdo->prepare(
            "INSERT INTO request_status_log (request_id, status, actor, sms_body)
             VALUES (:id, :status, 'staff', :body)"
        )->execute(['id' => $id, 'status' => $status, 'body' => $body]);
        if ($request['client_id'] !== null) {
            $pdo->prepare(
                "INSERT INTO notifications (client_id, request_id, type, message, channel, delivery_status)
                 VALUES (:client_id, :request_id, 'status_update', :message, 'system', 'sent')"
            )->execute(['client_id' => $request['client_id'], 'request_id' => $id, 'message' => $body]);
        }
        $pdo->prepare('INSERT INTO activity_logs (user_id, action, request_id) VALUES (:user, :action, :request)')
            ->execute(['user' => $user['id'], 'action' => 'Updated status to ' . $status, 'request' => $id]);
        sa_audit('staff', (int) $user['id'], (string) $user['name'], 'Request status changed', $request['reference_no']);
        $pdo->commit();
        return ['request_id' => (int) $id, 'status' => $status];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function sa_processing_request_id(array $data): int
{
    $id = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id && isset($data['request_ref'])) {
        $lookup = sa_db()->prepare('SELECT id FROM requests WHERE reference_no = :ref');
        $lookup->execute(['ref' => strtoupper((string) $data['request_ref'])]);
        $id = filter_var($lookup->fetchColumn(), FILTER_VALIDATE_INT);
    }
    if (!$id || $id < 1) {
        throw new SaHttpException(422);
    }
    return (int) $id;
}

function sa_require_request_row_locks(PDO $pdo): void
{
    $engine = $pdo->query(
        "SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests'"
    )->fetchColumn();
    if (strtoupper((string) $engine) !== 'INNODB') {
        throw new SaHttpException(
            503,
            'Request processing requires the requests table to use the InnoDB storage engine.'
        );
    }
}

function sa_start_request_processing(array $data): array
{
    $user = sa_internal_or_fail(['STAFF']);
    $id = sa_processing_request_id($data);
    $pdo = sa_db();
    sa_require_request_row_locks($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, client_id, reference_no, status, processing_started_at,
                    processing_completed_at, processing_duration_seconds,
                    processing_staff_id, processing_staff_name
             FROM requests WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new SaHttpException(404);
        }
        if (in_array($request['status'], ['Approved', 'Rejected', 'Out for Release', 'Timed Out'], true)) {
            throw new SaHttpException(409, 'Processing cannot be started for a closed request.');
        }
        if ($request['processing_staff_id'] !== null &&
            (int) $request['processing_staff_id'] !== (int) $user['id']) {
            throw new SaHttpException(409, 'This request has already been accepted by another staff member.');
        }
        $isNewAssignment = $request['processing_staff_id'] === null;
        $isNewStart = $request['processing_started_at'] === null;
        if ($isNewAssignment || $isNewStart) {
            $pdo->prepare(
                "UPDATE requests
                 SET processing_staff_id = COALESCE(processing_staff_id, :staff_id),
                     processing_staff_name = COALESCE(processing_staff_name, :staff_name),
                     status = 'Processing',
                     processing_started_at = COALESCE(processing_started_at, CURRENT_TIMESTAMP)
                 WHERE id = :id"
            )->execute([
                'staff_id' => $user['id'],
                'staff_name' => $user['name'],
                'id' => $id,
            ]);
            $request['status'] = 'Processing';
            $request['processing_staff_id'] = (int) $user['id'];
            $request['processing_staff_name'] = (string) $user['name'];
            $startedAtQuery = $pdo->prepare('SELECT processing_started_at FROM requests WHERE id = :id');
            $startedAtQuery->execute(['id' => $id]);
            $startedAt = (string) $startedAtQuery->fetchColumn();
            $event = sprintf(
                'Accepted and started processing by %s (staff ID: %d) at %s.',
                (string) $user['name'],
                (int) $user['id'],
                $startedAt
            );
            $pdo->prepare(
                "INSERT INTO request_status_log (request_id, status, actor, sms_body)
                 VALUES (:id, 'Processing', 'staff', :message)"
            )->execute(['id' => $id, 'message' => $event]);
            $pdo->prepare(
                'INSERT INTO activity_logs (user_id, actor_name, action, request_id)
                 VALUES (:user, :actor_name, :action, :request)'
            )
                ->execute([
                    'user' => $user['id'],
                    'actor_name' => $user['name'],
                    'action' => 'Accepted request and started processing',
                    'request' => $id,
                ]);
            if ($isNewStart && $request['client_id'] !== null) {
                $pdo->prepare(
                    "INSERT INTO notifications (client_id, request_id, type, message, channel, delivery_status)
                     VALUES (:client_id, :request_id, 'status_update', :message, 'system', 'sent')"
                )->execute([
                    'client_id' => $request['client_id'],
                    'request_id' => $id,
                    'message' => 'Your request ' . $request['reference_no'] . ' is now being processed.',
                ]);
            }
            sa_audit(
                'staff',
                (int) $user['id'],
                (string) $user['name'],
                'Request accepted and processing started',
                $request['reference_no']
            );
        }
        $stmt = $pdo->prepare(
            'SELECT processing_started_at, UNIX_TIMESTAMP(processing_started_at) AS processing_started_epoch,
                    processing_completed_at, UNIX_TIMESTAMP(processing_completed_at) AS processing_completed_epoch,
                    processing_duration_seconds, processing_staff_id, processing_staff_name
             FROM requests WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $timing = $stmt->fetch();
        $pdo->commit();
        return ['request_id' => $id, 'status' => $request['status']] + $timing;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function sa_complete_request_processing(array $data, string $status = 'Out for Release'): array
{
    $user = sa_internal_or_fail(['STAFF']);
    if (!in_array($status, ['Approved', 'Rejected', 'Out for Release'], true)) {
        throw new SaHttpException(422);
    }
    $id = sa_processing_request_id($data);
    $pdo = sa_db();
    sa_require_request_row_locks($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, client_id, reference_no, status, processing_started_at,
                    processing_completed_at, processing_duration_seconds,
                    processing_staff_id, processing_staff_name
             FROM requests WHERE id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new SaHttpException(404);
        }
        if ($request['processing_started_at'] === null) {
            throw new SaHttpException(409, 'Start processing before completing this request.');
        }
        if ($request['processing_staff_id'] === null ||
            (int) $request['processing_staff_id'] !== (int) $user['id']) {
            throw new SaHttpException(409, 'Only the staff member assigned to this request can complete it.');
        }
        if ($request['processing_completed_at'] === null) {
            if (in_array($request['status'], ['Approved', 'Rejected', 'Out for Release', 'Timed Out'], true)) {
                throw new SaHttpException(409, 'This request already has a terminal status.');
            }
            $pdo->prepare(
                'UPDATE requests
                 SET status = :status,
                     processing_completed_at = CURRENT_TIMESTAMP,
                     processing_duration_seconds = TIMESTAMPDIFF(SECOND, processing_started_at, CURRENT_TIMESTAMP)
                 WHERE id = :id AND processing_completed_at IS NULL'
            )->execute(['status' => $status, 'id' => $id]);
            $duration = $pdo->prepare(
                'SELECT processing_completed_at, UNIX_TIMESTAMP(processing_completed_at) AS processing_completed_epoch,
                        processing_duration_seconds
                 FROM requests WHERE id = :id'
            );
            $duration->execute(['id' => $id]);
            $timing = $duration->fetch();
            $durationSeconds = (int) $timing['processing_duration_seconds'];
            $hours = intdiv($durationSeconds, 3600);
            $minutes = intdiv($durationSeconds % 3600, 60);
            $seconds = $durationSeconds % 60;
            $event = sprintf(
                'Request processing completed by %s (staff ID: %d) at %s. Final duration: %d hours, %d minutes, %d seconds.',
                (string) $user['name'],
                (int) $user['id'],
                (string) $timing['processing_completed_at'],
                $hours,
                $minutes,
                $seconds
            );
            $pdo->prepare(
                'INSERT INTO request_status_log (request_id, status, actor, sms_body)
                 VALUES (:id, :status, :actor, :message)'
            )->execute(['id' => $id, 'status' => $status, 'actor' => 'staff', 'message' => $event]);
            $pdo->prepare(
                'INSERT INTO activity_logs (user_id, actor_name, action, request_id)
                 VALUES (:user, :actor_name, :action, :request)'
            )
                ->execute([
                    'user' => $user['id'],
                    'actor_name' => $user['name'],
                    'action' => 'Completed request processing',
                    'request' => $id,
                ]);
            if ($request['client_id'] !== null) {
                $pdo->prepare(
                    "INSERT INTO notifications (client_id, request_id, type, message, channel, delivery_status)
                     VALUES (:client_id, :request_id, 'status_update', :message, 'system', 'sent')"
                )->execute([
                    'client_id' => $request['client_id'],
                    'request_id' => $id,
                    'message' => 'Your request ' . $request['reference_no'] . ' is now ' . $status . '.',
                ]);
            }
            sa_audit('staff', (int) $user['id'], (string) $user['name'], 'Request processing completed', $request['reference_no']);
            $request['status'] = $status;
            $request['processing_completed_at'] = $timing['processing_completed_at'];
            $request['processing_completed_epoch'] = $timing['processing_completed_epoch'];
            $request['processing_duration_seconds'] = $timing['processing_duration_seconds'];
        }
        $pdo->commit();
        return [
            'request_id' => $id,
            'status' => $request['status'],
            'processing_started_at' => $request['processing_started_at'],
            'processing_completed_at' => $request['processing_completed_at'],
            'processing_completed_epoch' => $request['processing_completed_epoch'],
            'processing_duration_seconds' => $request['processing_duration_seconds'],
            'processing_staff_id' => $request['processing_staff_id'],
            'processing_staff_name' => $request['processing_staff_name'],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function sa_send_notification(array $data): array
{
    $user = sa_internal_or_fail(['STAFF']);
    $requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$requestId && isset($data['request_ref'])) {
        $lookup = sa_db()->prepare('SELECT id FROM requests WHERE reference_no = :ref');
        $lookup->execute(['ref' => strtoupper((string) $data['request_ref'])]);
        $requestId = filter_var($lookup->fetchColumn(), FILTER_VALIDATE_INT);
    }
    $message = sa_text($data, 'message', 2000, true);
    $category = sa_notification_category($data);
    if (!$requestId) {
        throw new SaHttpException(422);
    }
    $pdo = sa_db();
    $stmt = $pdo->prepare('SELECT id, client_id FROM requests WHERE id = :id');
    $stmt->execute(['id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new SaHttpException(404);
    }
    $notificationId = null;
    if ($request['client_id'] !== null) {
        $insert = $pdo->prepare(
            "INSERT INTO notifications (client_id, request_id, type, message, channel, delivery_status)
             VALUES (:client, :request, 'staff_message', :message, 'system', 'sent')"
        );
        $insert->execute(['client' => $request['client_id'], 'request' => $requestId, 'message' => $message]);
        $notificationId = (int) $pdo->lastInsertId();
    }
    if ($category !== null && sa_has_column('request_status_log', 'sms_category')) {
        $log = $pdo->prepare(
            "INSERT INTO request_status_log (request_id, status, actor, sms_body, sms_category)
             SELECT id, status, 'staff', :message, :category FROM requests WHERE id = :request_id"
        );
        $log->execute(['message' => $message, 'category' => $category, 'request_id' => $requestId]);
    } else {
        $log = $pdo->prepare(
            "INSERT INTO request_status_log (request_id, status, actor, sms_body)
             SELECT id, status, 'staff', :message FROM requests WHERE id = :request_id"
        );
        $log->execute(['message' => $message, 'request_id' => $requestId]);
    }
    sa_audit('staff', (int) $user['id'], (string) $user['name'], 'Client notification sent', (string) $requestId);
    return ['request_id' => (int) $requestId, 'notification_id' => $notificationId];
}

function sa_lookup_request_owner(array $data): array
{
    sa_internal_or_fail(['STAFF']);
    $reference = strtoupper((string) sa_text($data, 'reference', 30, true));
    $stmt = sa_db()->prepare(
        'SELECT r.is_owner, r.first_name, r.middle_name, r.last_name,
                c.first_name AS client_first_name, c.last_name AS client_last_name
         FROM requests r
         LEFT JOIN clients c ON c.id = r.client_id
         WHERE r.reference_no = :reference
         LIMIT 1'
    );
    $stmt->execute(['reference' => $reference]);
    $request = $stmt->fetch();
    if (!$request) {
        return ['ownerName' => 'Owner name unavailable', 'ownerType' => null];
    }

    $isOwner = filter_var($request['is_owner'], FILTER_VALIDATE_BOOLEAN);
    if ($isOwner) {
        $ownerName = trim(implode(' ', array_filter([
            trim((string) ($request['first_name'] ?? '')),
            trim((string) ($request['middle_name'] ?? '')),
            trim((string) ($request['last_name'] ?? '')),
        ], static fn(string $part): bool => $part !== '')));
        if ($ownerName !== '') {
            return ['ownerName' => $ownerName, 'ownerType' => 'Property owner'];
        }
    } else {
        $clientName = trim(implode(' ', array_filter([
            trim((string) ($request['client_first_name'] ?? '')),
            trim((string) ($request['client_last_name'] ?? '')),
        ], static fn(string $part): bool => $part !== '')));
        if ($clientName !== '') {
            return ['ownerName' => $clientName, 'ownerType' => 'Client'];
        }
    }
    return ['ownerName' => 'Owner name unavailable', 'ownerType' => null];
}

function sa_generate_ai_message(array $data): array
{
    $generationType = (string) ($data['generation_type'] ?? '');
    if ($generationType === 'notification') {
        sa_internal_or_fail(['STAFF']);
    } elseif ($generationType === 'announcement') {
        sa_internal_or_fail(['DEPARTMENT_HEAD']);
    } else {
        throw new SaHttpException(422, 'Choose a supported message type.');
    }

    $topic = sa_text($data, 'topic', 200, true);
    $details = sa_text($data, 'details', 1000) ?? '';
    $ollamaUrl = getenv('SMART_ASSESS_OLLAMA_URL');
    $ollamaUrl = $ollamaUrl === false || trim($ollamaUrl) === ''
        ? 'http://127.0.0.1:11434'
        : rtrim(trim($ollamaUrl), '/');
    $urlParts = parse_url($ollamaUrl);
    $ollamaHost = strtolower(trim((string) ($urlParts['host'] ?? ''), '[]'));
    if (!is_array($urlParts) ||
        !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true) ||
        $ollamaHost === '' ||
        isset($urlParts['user']) || isset($urlParts['pass']) ||
        isset($urlParts['query']) || isset($urlParts['fragment']) ||
        (isset($urlParts['path']) && $urlParts['path'] !== '' && $urlParts['path'] !== '/')) {
        throw new SaHttpException(503, 'AI message generation is unavailable. You can enter the message manually.');
    }
    if (strtolower((string) ($urlParts['scheme'] ?? '')) === 'http' &&
        !in_array($ollamaHost, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new SaHttpException(503, 'AI message generation requires HTTPS for non-local endpoints. You can enter the message manually.');
    }
    if (!function_exists('curl_init')) {
        throw new SaHttpException(503, 'AI message generation is unavailable. You can enter the message manually.');
    }

    $isNotification = $generationType === 'notification';
    $lastProblem = 'no usable text';
    // Retry until the draft is Filipino and keeps every number/reference
    // exactly as staff provided it; otherwise fail safely (nothing is shown).
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $message = sa_ollama_generate(
            $ollamaUrl,
            sa_filipino_system_prompt($isNotification),
            sa_filipino_user_prompt($isNotification, $topic, $details, $attempt > 1 ? $lastProblem : null),
            $attempt === 1 ? 0.2 : 0.1
        );
        $message = sa_clean_ai_message($message);
        $maximumLength = $isNotification ? 2000 : 6000;
        if ($message === '' || mb_strlen($message) > $maximumLength) {
            $lastProblem = 'no usable text';
            continue;
        }
        if (preg_match('/\[[^\]]{1,60}\]|\{[^}]{1,60}\}|<[^>]{1,60}>/u', $message)) {
            $lastProblem = 'it contained placeholder text in brackets instead of real information';
            continue;
        }
        if (!sa_is_filipino_text($message)) {
            $lastProblem = 'it was not written in Filipino';
            continue;
        }
        $changed = sa_changed_identifiers($message, $topic . ' ' . $details);
        if ($changed !== []) {
            $lastProblem = 'it changed or invented these numbers/references: ' . implode(', ', $changed);
            continue;
        }
        // Small models like to add "bukas" before the day staff gave ("bukas,
        // Biyernes"); drop just that word and keep the real day.
        $message = preg_replace('/\b(?:bukas|mamaya)\s*[,(]?\s*(?=(?:Lunes|Martes|Miyerkules|Miyerkoles|Huwebes|Biyernes|Sabado|Linggo)\b)/iu', '', $message) ?? $message;
        // ...or to write "bukas" in place of the one day staff gave.
        $givenDays = array_values(array_filter(sa_time_word_hints($topic . ' ' . $details),
            static fn ($f) => in_array($f, ['Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado', 'Linggo'], true)));
        if (count($givenDays) === 1 && !preg_match('/\btomorrow\b/i', $topic . ' ' . $details) &&
            !preg_match('/\b' . $givenDays[0] . '\b/iu', $message)) {
            $message = preg_replace('/\bbukas\b/iu', 'sa ' . $givenDays[0], $message, 1) ?? $message;
            $message = preg_replace('/\bsa sa\b/iu', 'sa', $message) ?? $message;
        }
        $invented = sa_invented_time_words($message, $topic . ' ' . $details);
        if ($invented !== []) {
            $lastProblem = 'it added days or dates that were not given: ' . implode(', ', $invented);
            continue;
        }
        $missing = sa_changed_identifiers($topic . ' ' . $details, $message, true);
        if ($missing !== []) {
            $lastProblem = 'it left out these dates/numbers/references: ' . implode(', ', $missing);
            continue;
        }
        return ['message' => $message];
    }
    error_log('[SMART_ASSESS] AI generation rejected after 3 attempts: ' . $lastProblem);
    throw new SaHttpException(503, 'The AI could not write a suitable Filipino message this time. Please try again or type the message manually.');
}

/** Language and tone rules shared by every AI-generated client-facing text. */
function sa_filipino_system_prompt(bool $isNotification): string
{
    $kind = $isNotification
        ? 'isang maikling SMS na mensahe para sa isang kliyente ng Tanggapan ng Assessor ng Mabini, Batangas'
        : 'isang maikling anunsyo mula sa Tanggapan ng Assessor ng Mabini, Batangas para sa publiko';
    return "Ikaw ay sumusulat ng {$kind}.\n" .
        "MGA PATAKARAN:\n" .
        "1. Isulat ang buong mensahe sa simple, natural, at magalang na Filipino (Tagalog) na madaling maintindihan ng matatanda.\n" .
        "2. Magsimula sa \"Magandang araw po.\" Gumamit ng \"po\". Gamitin ang \"Mangyaring\" kapag may hinihiling na gawin. Magtapos sa \"Maraming salamat po.\"\n" .
        "3. Sabihin LAMANG ang mga impormasyong ibinigay. Huwag magdagdag ng bagong impormasyon, dahilan, paumanhin, araw o petsa (tulad ng \"bukas\" o \"Lunes\"), oras, o utos (tulad ng \"magdala ng ID\") na hindi nakasulat sa impormasyon.\n" .
        "3a. Ang paksa at detalye ay mga tala na maaaring nasa Ingles. ISALIN ang kahulugan nito sa Filipino. HUWAG kopyahin ang mga pangungusap o label na Ingles (tulad ng \"Request type:\" o \"Current status:\").\n" .
        "4. Kopyahin nang eksakto ang mga pangalan, petsa, oras, araw, halaga, reference number, ARP/Tax Declaration number, at pangalan ng dokumento.\n" .
        "5. Iwasan ang malalalim na salita, teknikal na salita, at hindi kailangang Ingles. Maikli at malinaw na pangungusap lamang.\n" .
        "6. Ibalik LAMANG ang mensahe. Walang pamagat at walang paliwanag.\n" .
        "MGA SALITANG GAGAMITIN (pagsasalin lamang ng salita; HUWAG idagdag ang mga ito kung wala sa impormasyon):\n" .
        "bring = magdala; office hours = oras ng opisina; ready for release = handa nang kunin; " .
        "requirements = mga kailangang dokumento; missing = kulang; submit = isumite; approved = aprubado; " .
        "completed = tapos na; closed = sarado; payment = bayad; deadline = huling araw; penalty = multa; " .
        "under review = sinusuri; visit = pumunta; request = kahilingan; valid ID = valid ID (huwag isalin).";
}

/** Topic/details are content, never instructions. The example only shows the
 *  sentence pattern (placeholders), so its wording cannot leak into drafts. */
/** English day/month names in staff's input mapped to their Filipino names. */
function sa_time_word_hints(string $source): array
{
    $map = ['monday' => 'Lunes', 'tuesday' => 'Martes', 'wednesday' => 'Miyerkules', 'thursday' => 'Huwebes',
        'friday' => 'Biyernes', 'saturday' => 'Sabado', 'sunday' => 'Linggo', 'january' => 'Enero',
        'february' => 'Pebrero', 'march' => 'Marso', 'april' => 'Abril', 'june' => 'Hunyo', 'july' => 'Hulyo',
        'august' => 'Agosto', 'september' => 'Setyembre', 'october' => 'Oktubre', 'november' => 'Nobyembre',
        'december' => 'Disyembre'];
    $hints = [];
    foreach ($map as $english => $filipino) {
        if (preg_match('/\b' . $english . '\b/i', $source)) {
            $hints[ucfirst($english)] = $filipino;
        }
    }
    return $hints;
}

function sa_filipino_user_prompt(bool $isNotification, string $topic, string $details, ?string $retryReason): string
{
    $prompt = "Anyo ng mensahe: magsimula sa \"Magandang araw po.\", sabihin ang balita, idagdag ang \"Mangyaring ...\" " .
        "kung may dapat gawin, at magtapos sa \"Maraming salamat po.\"\n\n" .
        "IMPORMASYON NA DAPAT SABIHIN (wala nang iba):\n" .
        "- Paksa: {$topic}\n" .
        '- Detalye: ' . ($details !== '' ? $details : 'Wala.') . "\n";
    $hints = sa_time_word_hints($topic . ' ' . $details);
    if ($hints !== []) {
        $pairs = [];
        foreach ($hints as $english => $filipino) {
            $pairs[] = "{$english} = {$filipino}";
        }
        $prompt .= '- Mga araw/buwan (gamitin ang Filipino; ang "Friday" ay "Biyernes", HINDI "bukas"): ' . implode(', ', $pairs) . "\n";
    }
    if ($retryReason !== null) {
        $prompt .= "\nHindi tinanggap ang naunang sagot dahil {$retryReason}. Isulat muli sa Filipino at kopyahin nang eksakto ang lahat ng numero, petsa, at reference.\n";
    }
    return $prompt . "\nMensahe:";
}

/** One non-streaming Ollama completion. Uses SMART_ASSESS_OLLAMA_MODEL, else
 *  gemma2:9b (much better Filipino); falls back to llama3.2:3b if the chosen
 *  model is not installed. */
function sa_ollama_generate(string $ollamaUrl, string $system, string $prompt, float $temperature, ?string $model = null): string
{
    if ($model === null) {
        $configured = trim((string) getenv('SMART_ASSESS_OLLAMA_MODEL'));
        $model = preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $configured) ? $configured : 'gemma2:9b';
    }
    $payload = json_encode([
        'model' => $model,
        'system' => $system,
        'prompt' => $prompt,
        'stream' => false,
        'options' => ['temperature' => $temperature],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $responseBody = '';
    $curl = curl_init($ollamaUrl . '/api/generate');
    if ($curl === false) {
        throw new SaHttpException(503, 'AI message generation is unavailable. You can enter the message manually.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
            if (strlen($responseBody) + strlen($chunk) > 1_048_576) {
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);
    $result = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($status === 404 && $model !== 'llama3.2:3b') {
        error_log('[SMART_ASSESS] Ollama model ' . $model . ' is not installed; falling back to llama3.2:3b.');
        return sa_ollama_generate($ollamaUrl, $system, $prompt, $temperature, 'llama3.2:3b');
    }
    if ($result === false || $status < 200 || $status >= 300) {
        throw new SaHttpException(503, 'AI message generation is unavailable. You can enter the message manually.');
    }
    try {
        $decoded = json_decode($responseBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new SaHttpException(503, 'AI message generation returned an invalid response. You can enter the message manually.');
    }
    return is_array($decoded) ? trim((string) ($decoded['response'] ?? '')) : '';
}

/** Strip subject/title lines, labels and wrapping quotes the model may add. */
function sa_clean_ai_message(string $message): string
{
    $message = trim($message);
    $message = preg_replace('/^\s*(?:subject|title|paksa|pamagat|mensahe)\s*:[^\r\n]*(?:\r?\n)+/iu', '', $message, 1) ?? $message;
    $message = preg_replace('/^\s*(?:mensahe|message)\s*:\s*/iu', '', $message, 1) ?? $message;
    $message = trim($message);
    if (preg_match('/^([^\r\n]{1,120})\R{2,}/u', $message, $heading) &&
        !preg_match('/[.!?][\'")\]]?$/u', trim($heading[1]))) {
        $message = trim(substr($message, strlen($heading[0])));
    }
    if (preg_match('/^(["“])(.*)(["”])$/su', $message, $quoted)) {
        $message = trim($quoted[2]);
    }
    return $message;
}

/** Heuristic: Filipino function words must clearly outnumber English ones. */
function sa_is_filipino_text(string $text): bool
{
    $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) < 4) {
        return false;
    }
    $filipino = ['ang', 'ng', 'mga', 'sa', 'po', 'opo', 'na', 'ay', 'at', 'para', 'ito', 'iyon', 'kayo', 'inyong', 'inyo',
        'namin', 'natin', 'kami', 'tayo', 'hindi', 'lamang', 'lang', 'mangyaring', 'salamat', 'maraming', 'magandang',
        'araw', 'ninyo', 'ninyong', 'upang', 'kung', 'nang', 'din', 'rin', 'pa', 'may', 'wala', 'aming', 'ating', 'si',
        'ni', 'kay', 'ko', 'mo', 'niya', 'dahil', 'pero', 'ngunit', 'kapag', 'ngayon', 'bukas', 'tanggapan', 'opisina',
        'pong', 'nito', 'iyong', 'ninyo', 'po.', 'muli', 'lahat', 'bawat', 'tulad', 'habang', 'hanggang', 'mula'];
    $english = ['the', 'and', 'of', 'to', 'is', 'are', 'will', 'be', 'your', 'you', 'please', 'for', 'with', 'this',
        'that', 'we', 'our', 'on', 'in', 'has', 'have', 'been', 'thank', 'office', 'regarding', 'kindly', 'dear', 'an'];
    $fil = 0;
    $eng = 0;
    foreach ($words as $word) {
        if (in_array($word, $filipino, true)) {
            $fil++;
        } elseif (in_array($word, $english, true)) {
            $eng++;
        }
    }
    if (!($fil >= 3 && $fil >= 2 * $eng && $fil / count($words) >= 0.15)) {
        return false;
    }
    // No sentence may be mostly English (e.g. a copied English note).
    $englishMarkers = array_merge($english, ['can', 'now', 'through', 'starting', 'until', 'before', 'after', 'their',
        'they', 'it', 'as', 'by', 'or', 'not', 'if', 'current', 'status', 'type', 'needing', 'action', 'clients',
        'client', 'book', 'appointments', 'appointment', 'website', 'visit', 'bring', 'submit', 'request', 'must',
        'should', 'would', 'here', 'there', 'when', 'what', 'which', 'who', 'all', 'any', 'also', 'from', 'about']);
    foreach (preg_split('/(?<=[.!?])\s+|\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
        $sw = preg_split('/[^\p{L}]+/u', mb_strtolower($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sf = count(array_filter($sw, static fn ($w) => in_array($w, $filipino, true)));
        $se = count(array_filter($sw, static fn ($w) => in_array($w, $englishMarkers, true)));
        if ($se >= 3 && $se > $sf) {
            return false;
        }
    }
    return true;
}

/** Day/month/relative-day words in the draft (Filipino or English) whose
 *  meaning is not present in staff's input, e.g. "bukas" for "on Friday". */
function sa_invented_time_words(string $message, string $source): array
{
    $groups = [
        ['bukas', 'tomorrow'], ['kahapon', 'yesterday'], ['mamaya', 'later today', 'tonight'],
        ['lunes', 'monday'], ['martes', 'tuesday'], ['miyerkules', 'miyerkoles', 'wednesday'],
        ['huwebes', 'thursday'], ['biyernes', 'friday'], ['sabado', 'saturday'], ['linggo', 'sunday'],
        ['enero', 'january'], ['pebrero', 'february'], ['marso', 'march'], ['abril', 'april'],
        ['mayo', 'may'], ['hunyo', 'june'], ['hulyo', 'july'], ['agosto', 'august'],
        ['setyembre', 'september'], ['oktubre', 'october'], ['nobyembre', 'november'], ['disyembre', 'december'],
    ];
    $msg = ' ' . mb_strtolower(preg_replace('/[^\p{L}\s]+/u', ' ', $message) ?? '') . ' ';
    $src = ' ' . mb_strtolower(preg_replace('/[^\p{L}\s]+/u', ' ', $source) ?? '') . ' ';
    $invented = [];
    foreach ($groups as $group) {
        foreach ($group as $word) {
            // "may" is also a common Filipino word ("there is"); only check it as a month next to a number.
            if ($word === 'may' || $word === 'linggo') {
                continue;
            }
            if (str_contains($msg, ' ' . $word . ' ')) {
                $present = false;
                foreach ($group as $alt) {
                    if (str_contains($src, ' ' . $alt . ' ')) {
                        $present = true;
                    }
                }
                if (!$present) {
                    $invented[] = $word;
                }
            }
        }
    }
    return array_values(array_unique($invented));
}

/** Number-bearing tokens (reference numbers, ARP numbers, dates, amounts,
 *  times) in the draft that do not appear verbatim in staff's input. */
function sa_changed_identifiers(string $message, string $source, bool $ignoreOrdinals = false): array
{
    preg_match_all('/[\p{L}\d]*\d[\p{L}\d\-\/.,:]*/u', $message, $matches);
    $normalizedSource = mb_strtolower($source);
    $changed = [];
    foreach ($matches[0] as $token) {
        $token = rtrim($token, '.,:-/');
        if ($token === '' || mb_strlen($token) < 2) {
            continue;
        }
        // "1st quarter" may correctly become "unang quarter" in Filipino.
        if ($ignoreOrdinals && preg_match('/^\d+(?:st|nd|rd|th)$/i', $token)) {
            continue;
        }
        if (!str_contains($normalizedSource, mb_strtolower($token))) {
            $changed[] = $token;
        }
    }
    return array_values(array_unique($changed));
}

function sa_admin_user_change(array $data, string $action): array
{
    $actor = sa_internal_or_fail(['ADMIN']);
    $pdo = sa_db();
    $targetId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'create') {
        $name = sa_text($data, 'name', 150, true);
        $username = sa_text($data, 'username', 80, true);
        $email = strtolower((string) sa_text($data, 'email', 150, true));
        $password = (string) ($data['password'] ?? '');
        $role = sa_normalize_role($data['role_id'] ?? null);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !preg_match('/^[A-Za-z0-9._-]{3,80}$/', (string) $username) ||
            strlen($password) < 10 || strlen($password) > 4096 ||
            !in_array($role, [2, 3, 4], true)) {
            throw new SaHttpException(422);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO users (role_id, name, username, email, contact_number, position_title, password_hash, status)
             VALUES (:role, :name, :username, :email, :contact, :position, :hash, 'Active')"
        );
        try {
            $stmt->execute([
                'role' => $role,
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'contact' => sa_text($data, 'contact_number', 20),
                'position' => sa_text($data, 'position_title', 80),
                'hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new SaHttpException(409);
            }
            throw $exception;
        }
        $id = (int) $pdo->lastInsertId();
        sa_audit('admin', (int) $actor['id'], (string) $actor['name'], 'Internal account created', (string) $id);
        return ['user_id' => $id];
    }

    if (!$targetId || $targetId < 1) {
        throw new SaHttpException(422);
    }
    $find = $pdo->prepare('SELECT id, name FROM users WHERE id = :id');
    $find->execute(['id' => $targetId]);
    $target = $find->fetch();
    if (!$target) {
        throw new SaHttpException(404);
    }
    if ($action === 'edit') {
        $name = sa_text($data, 'name', 150, true);
        $username = sa_text($data, 'username', 80, true);
        $email = strtolower((string) sa_text($data, 'email', 150, true));
        $role = sa_normalize_role($data['role_id'] ?? null);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !preg_match('/^[A-Za-z0-9._-]{3,80}$/', (string) $username) ||
            !in_array($role, [2, 3, 4], true)) {
            throw new SaHttpException(422);
        }
        $status = strtolower((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new SaHttpException(422);
        }
        $password = (string) ($data['password'] ?? '');
        if ($password !== '' && (strlen($password) < 10 || strlen($password) > 4096)) {
            throw new SaHttpException(422);
        }
        $params = [
            'id' => $targetId,
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'status' => ucfirst($status),
            'contact' => sa_text($data, 'contact_number', 20),
            'position' => sa_text($data, 'position_title', 80),
        ];
        $sql = 'UPDATE users SET name = :name, username = :username, email = :email, role_id = :role,
                status = :status,
                contact_number = :contact, position_title = :position';
        if ($password !== '') {
            $sql .= ', password_hash = :hash';
            $params['hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE id = :id AND deleted_at IS NULL';
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new SaHttpException(409);
            }
            throw $exception;
        }
        sa_audit('admin', (int) $actor['id'], (string) $actor['name'], 'Internal account edited', (string) $targetId);
    } elseif ($action === 'status') {
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['Active', 'Inactive'], true) || $targetId === (int) $actor['id'] && $status !== 'Active') {
            throw new SaHttpException(422);
        }
        $pdo->prepare('UPDATE users SET status = :status WHERE id = :id AND deleted_at IS NULL')
            ->execute(['status' => $status, 'id' => $targetId]);
        sa_audit('admin', (int) $actor['id'], (string) $actor['name'], 'Internal account status changed', (string) $targetId);
    } elseif ($action === 'archive') {
        if ($targetId === (int) $actor['id']) {
            throw new SaHttpException(422);
        }
        $pdo->prepare(
            'UPDATE users SET archived_at = NOW(), archived_by = :actor, status = \'Inactive\'
             WHERE id = :id AND archived_at IS NULL AND deleted_at IS NULL'
        )->execute(['actor' => $actor['id'], 'id' => $targetId]);
        sa_audit('admin', (int) $actor['id'], (string) $actor['name'], 'Internal account archived', (string) $targetId);
    } elseif ($action === 'restore') {
        $pdo->prepare(
            "UPDATE users SET archived_at = NULL, archived_by = NULL, restored_at = NOW(),
                    restored_by = :actor, status = 'Active'
             WHERE id = :id AND archived_at IS NOT NULL AND deleted_at IS NULL"
        )->execute(['actor' => $actor['id'], 'id' => $targetId]);
        sa_audit('admin', (int) $actor['id'], (string) $actor['name'], 'Internal account restored', (string) $targetId);
    } elseif ($action === 'delete') {
        if ($targetId === (int) $actor['id']) {
            throw new SaHttpException(422);
        }
        $pdo->prepare(
            "UPDATE users SET deleted_at = NOW(), deleted_by = :actor, status = 'Inactive'
             WHERE id = :id AND deleted_at IS NULL"
        )->execute(['actor' => $actor['id'], 'id' => $targetId]);
        sa_audit('admin', (int) $actor['id'], (string) $actor['name'], 'Internal account soft-deleted', (string) $targetId);
    }
    return ['user_id' => (int) $targetId];
}

function sa_admin_toggle_user(array $data): array
{
    $actor = sa_internal_or_fail(['ADMIN']);
    $id = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1 || $id === (int) $actor['id']) {
        throw new SaHttpException(422);
    }
    $pdo = sa_db();
    $stmt = $pdo->prepare(
        "UPDATE users SET status = IF(status = 'Active', 'Inactive', 'Active')
         WHERE id = :id AND archived_at IS NULL AND deleted_at IS NULL"
    );
    $stmt->execute(['id' => $id]);
    if ($stmt->rowCount() !== 1) {
        throw new SaHttpException(404);
    }
    $status = $pdo->prepare('SELECT status FROM users WHERE id = :id');
    $status->execute(['id' => $id]);
    $newStatus = (string) $status->fetchColumn();
    sa_audit('admin', (int) $actor['id'], (string) $actor['name'], 'Internal account status toggled', (string) $id);
    return ['user_id' => (int) $id, 'status' => $newStatus];
}

function sa_internal_update_profile(array $data): array
{
    $user = sa_internal_or_fail(['STAFF', 'ADMIN', 'DEPARTMENT_HEAD']);
    $name = sa_text($data, 'name', 150, true);
    $username = sa_text($data, 'username', 80);
    $contact = sa_text($data, 'contact_number', 20);
    $position = array_key_exists('position_title', $data)
        ? sa_text($data, 'position_title', 80)
        : ($user['position_title'] ?? null);
    $email = strtolower((string) sa_text($data, 'email', 150, true));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new SaHttpException(422);
    }
    if ($username !== null && !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
        throw new SaHttpException(422);
    }
    $newPassword = (string) ($data['new_password'] ?? '');
    if ($newPassword !== '' && (strlen($newPassword) < 10 || strlen($newPassword) > 4096)) {
        throw new SaHttpException(422);
    }

    $pdo = sa_db();
    $current = $pdo->prepare('SELECT username, password_hash FROM users WHERE id = :id');
    $current->execute(['id' => $user['id']]);
    $stored = $current->fetch();
    if (!$stored) {
        throw new SaHttpException(401);
    }
    if ($newPassword !== '') {
        $currentPassword = (string) ($data['current_password'] ?? '');
        if ($currentPassword === '' || !password_verify($currentPassword, $stored['password_hash'])) {
            throw new SaHttpException(403);
        }
    }
    $params = [
        'id' => $user['id'],
        'name' => $name,
        'email' => $email,
        'username' => $username ?? $stored['username'],
        'contact' => $contact,
        'position' => $position,
    ];
    $sql = 'UPDATE users SET name = :name, email = :email, username = :username,
            contact_number = :contact, position_title = :position';
    if ($newPassword !== '') {
        $sql .= ', password_hash = :hash';
        $params['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }
    $sql .= ' WHERE id = :id AND archived_at IS NULL AND deleted_at IS NULL';
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            throw new SaHttpException(409);
        }
        throw $exception;
    }
    sa_audit(sa_actor_type($user), (int) $user['id'], (string) $user['name'], 'Internal profile updated');
    return ['user_id' => (int) $user['id']];
}

function sa_announcement_change(array $data, string $action): array
{
    $actor = sa_internal_or_fail(['DEPARTMENT_HEAD']);
    $pdo = sa_db();
    $id = filter_var($data['announcement_id'] ?? null, FILTER_VALIDATE_INT);
    $needsContent = in_array($action, ['create', 'edit'], true);
    if ($needsContent) {
        $rawTitle = trim((string) ($data['title'] ?? ''));
        $rawBody = trim((string) ($data['body'] ?? ''));
        if ($rawTitle === '') {
            throw new SaHttpException(422, 'Add a subject for the announcement.');
        }
        if (mb_strlen($rawTitle) > 200 || str_contains($rawTitle, "\0")) {
            throw new SaHttpException(422, 'The subject must be 200 characters or fewer.');
        }
        if ($rawBody === '') {
            throw new SaHttpException(422, 'Write a message for the announcement.');
        }
        if (strlen($rawBody) > 60000 || str_contains($rawBody, "\0")) {
            throw new SaHttpException(422, 'The message is too long. Shorten it and try again.');
        }
        if (!in_array((string) ($data['audience'] ?? 'both'), ['client', 'staff', 'both'], true)) {
            throw new SaHttpException(422, 'Choose who should receive this announcement.');
        }
    }
    $title = $needsContent ? trim((string) $data['title']) : sa_text($data, 'title', 200);
    $body = $needsContent ? trim((string) $data['body']) : sa_text($data, 'body', 60000);
    $audience = (string) ($data['audience'] ?? 'both');
    $startDate = (string) ($data['start_date'] ?? date('Y-m-d'));
    $endDate = sa_text($data, 'end_date', 10);
    $imageUrl = sa_text($data, 'image_url', 500);
    if ($needsContent && (!in_array($audience, ['client', 'staff', 'both'], true) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || ($endDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)))) {
        throw new SaHttpException(422);
    }
    if ($needsContent) {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end = $endDate === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if (!$start || $start->format('Y-m-d') !== $startDate ||
            ($endDate !== null && (!$end || $end->format('Y-m-d') !== $endDate || $end < $start))) {
            throw new SaHttpException(422);
        }
        if ($imageUrl !== null && !preg_match('~^(?:https://|/)~i', $imageUrl)) {
            throw new SaHttpException(422);
        }
    }
    if (in_array($action, ['edit', 'delete', 'publish', 'schedule'], true) && (!$id || $id < 1)) {
        throw new SaHttpException(422);
    }
    if ($id && $action !== 'create') {
        $exists = $pdo->prepare('SELECT id FROM announcements WHERE id = :id');
        $exists->execute(['id' => $id]);
        if (!$exists->fetchColumn()) {
            throw new SaHttpException(404);
        }
    }
    if ($action === 'create') {
        $status = 'Draft';
        $scheduledAt = null;
        $publishedAt = null;
        $stmt = $pdo->prepare(
            'INSERT INTO announcements
             (title, body, author, audience, start_date, end_date, image_url, status, scheduled_at, published_at)
             VALUES (:title, :body, :author, :audience, :start, :end, :image, :status, NULL, NULL)'
        );
        $stmt->execute([
            'title' => $title,
            'body' => $body,
            'author' => $actor['name'],
            'audience' => $audience,
            'start' => $startDate,
            'end' => $endDate,
            'image' => $imageUrl,
            'status' => $status,
        ]);
        $id = (int) $pdo->lastInsertId();
    } elseif ($action === 'edit') {
        $stmt = $pdo->prepare(
            'UPDATE announcements SET title = :title, body = :body, audience = :audience,
             start_date = :start, end_date = :end, image_url = :image WHERE id = :id'
        );
        $stmt->execute([
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'start' => $startDate,
            'end' => $endDate,
            'image' => $imageUrl,
            'id' => $id,
        ]);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM announcements WHERE id = :id')->execute(['id' => $id]);
    } elseif ($action === 'publish') {
        $pdo->prepare(
            "UPDATE announcements SET status = 'Published', scheduled_at = NULL, published_at = NOW()
             WHERE id = :id"
        )->execute(['id' => $id]);
    } elseif ($action === 'schedule') {
        $scheduledAt = sa_text($data, 'scheduled_at', 19, true);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $scheduledAt, sa_schedule_timezone());
        if (!$date || $date->format('Y-m-d H:i:s') !== $scheduledAt) {
            throw new SaHttpException(422, 'Choose a valid schedule date and time.');
        }
        if ($date <= new DateTimeImmutable('now', sa_schedule_timezone())) {
            throw new SaHttpException(422, 'The scheduled time has already passed. Pick a future date and time (Philippine time).');
        }
        $pdo->prepare(
            "UPDATE announcements SET status = 'Scheduled', scheduled_at = :scheduled, published_at = NULL
             WHERE id = :id"
        )->execute(['scheduled' => $scheduledAt, 'id' => $id]);
    }
    sa_audit('head', (int) $actor['id'], (string) $actor['name'], 'Announcement ' . $action, (string) $id);
    return ['announcement_id' => (int) $id];
}

function sa_announcement_save(array $data): array
{
    // Create/edit + publish/schedule is one unit: a rejected schedule must
    // not leave a half-saved draft behind.
    $pdo = sa_db();
    $pdo->beginTransaction();
    try {
        $saved = sa_announcement_save_steps($data);
        $pdo->commit();
        return $saved;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function sa_announcement_save_steps(array $data): array
{
    $id = filter_var($data['announcement_id'] ?? null, FILTER_VALIDATE_INT);
    $result = sa_announcement_change($data, $id ? 'edit' : 'create');
    $id = (int) $result['announcement_id'];
    $mode = (string) ($data['announcement_mode'] ?? 'draft');
    if ($mode === 'publish') {
        sa_announcement_change(['announcement_id' => $id], 'publish');
    } elseif ($mode === 'schedule') {
        sa_announcement_change([
            'announcement_id' => $id,
            'scheduled_at' => $data['scheduled_at'] ?? '',
        ], 'schedule');
    } elseif ($mode === 'draft') {
        sa_db()->prepare(
            "UPDATE announcements SET status = 'Draft', scheduled_at = NULL, published_at = NULL
             WHERE id = :id"
        )->execute(['id' => $id]);
    }
    return ['announcement_id' => $id, 'status' => $mode === 'schedule' ? 'Scheduled' : ($mode === 'publish' ? 'Published' : 'Draft')];
}

function sa_announcement_transition(array $data): array
{
    $actor = sa_internal_or_fail(['DEPARTMENT_HEAD']);
    $id = filter_var($data['announcement_id'] ?? null, FILTER_VALIDATE_INT);
    $transition = (string) ($data['transition'] ?? '');
    $statuses = ['publish' => 'Published', 'unpublish' => 'Draft', 'cancel' => 'Cancelled'];
    if (!$id || $id < 1 || !isset($statuses[$transition])) {
        throw new SaHttpException(422, 'That announcement action is not valid. Reload the page and try again.');
    }
    $pdo = sa_db();
    $stmt = $pdo->prepare(
        'UPDATE announcements SET status = :status,
            scheduled_at = NULL,
            published_at = CASE WHEN :published = 1 THEN NOW() ELSE NULL END
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $statuses[$transition],
        'published' => $transition === 'publish' ? 1 : 0,
        'id' => $id,
    ]);
    if ($stmt->rowCount() < 1) {
        $exists = $pdo->prepare('SELECT id FROM announcements WHERE id = :id');
        $exists->execute(['id' => $id]);
        if (!$exists->fetchColumn()) {
            throw new SaHttpException(404);
        }
    }
    sa_audit('head', (int) $actor['id'], (string) $actor['name'], 'Announcement ' . $transition, (string) $id);
    return ['announcement_id' => (int) $id, 'status' => $statuses[$transition]];
}

function sa_mark_notification_read(array $data): array
{
    $client = sa_client_or_fail();
    $id = filter_var($data['notification_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        throw new SaHttpException(422);
    }
    $stmt = sa_db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND client_id = :client');
    $stmt->execute(['id' => $id, 'client' => $client['id']]);
    return ['updated' => $stmt->rowCount() > 0];
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? '');
        if ($action === 'public_bootstrap') {
            sa_json_response(['ok' => true, 'data' => sa_public_bootstrap()]);
        }
        if ($action === 'internal_bootstrap') {
            sa_internal_or_fail(['STAFF', 'ADMIN', 'DEPARTMENT_HEAD']);
            sa_json_response(['ok' => true, 'data' => sa_internal_bootstrap()]);
        }
        if ($action === 'track') {
            $request = sa_track_request((string) ($_GET['ref'] ?? ''));
            if (!$request) {
                sa_json_response(['error' => 'Request could not be completed.'], 404);
            }
            sa_json_response(['ok' => true, 'data' => ['request' => $request], 'request' => $request]);
        }
        throw new SaHttpException(404);
    }

    $data = sa_request_data();
    if (!isset($data['action']) && isset($_GET['action'])) {
        $data['action'] = (string) $_GET['action'];
    }
    $data = sa_normalize_bridge_request($data);
    $action = (string) ($data['action'] ?? '');
    sa_verify_csrf(isset($data['csrf']) ? (string) $data['csrf'] : null);
    $result = match ($action) {
        'submit_request' => sa_submit_request($data),
        'client_register' => sa_register_client($data),
        'client_login' => sa_login_client($data),
        'client_logout' => (function (): array {
            sa_client_or_fail();
            sa_close_user_session('sa_client_session_id');
            unset($_SESSION['sa_client_id']);
            session_regenerate_id(true);
            return ['logged_out' => true, 'csrf' => sa_csrf_token()];
        })(),
        'internal_login' => (function () use ($data): array {
            $username = sa_text($data, 'username', 80, true);
            $password = (string) ($data['password'] ?? '');
            if ($password === '' || strlen($password) > 4096) {
                throw new SaHttpException(401);
            }
            $stmt = sa_db()->prepare(
                "SELECT u.id, u.role_id, u.name, u.username, u.password_hash, r.name AS role
                 FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE u.username = :username AND u.status = 'Active'
                   AND u.archived_at IS NULL AND u.deleted_at IS NULL LIMIT 1"
            );
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password_hash']) ||
                !in_array((int) $user['role_id'], [2, 3, 4], true)) {
                throw new SaHttpException(401);
            }
            sa_close_user_session('sa_internal_session_id');
            sa_close_user_session('sa_client_session_id');
            unset($_SESSION['sa_client_id'], $_SESSION['sa_client_session_id']);
            session_regenerate_id(true);
            $_SESSION['sa_internal_id'] = (int) $user['id'];
            $_SESSION['sa_internal_session_id'] = sa_user_session(sa_actor_type($user), (int) $user['id']);
            sa_audit(sa_actor_type($user), (int) $user['id'], (string) $user['name'], 'Internal login', (string) $user['username']);
            unset($user['password_hash']);
            return ['user' => $user, 'csrf' => sa_csrf_token()];
        })(),
        'internal_logout' => (function (): array {
            $user = sa_internal_or_fail(['STAFF', 'ADMIN', 'DEPARTMENT_HEAD']);
            sa_close_user_session('sa_internal_session_id');
            unset($_SESSION['sa_internal_id']);
            sa_audit(sa_actor_type($user), (int) $user['id'], (string) $user['name'], 'Internal logout');
            session_regenerate_id(true);
            return ['logged_out' => true, 'csrf' => sa_csrf_token()];
        })(),
        'update_request_status' => sa_update_request_status($data),
        'start_processing' => sa_start_request_processing($data),
        'complete_processing' => sa_complete_request_processing($data),
        'send_notification' => sa_send_notification($data),
        'lookup_request_owner' => sa_lookup_request_owner($data),
        'generate_ai_message' => sa_generate_ai_message($data),
        'admin_create_user' => sa_admin_user_change($data, 'create'),
        'admin_edit_user' => sa_admin_user_change($data, 'edit'),
        'admin_user_status' => sa_admin_user_change($data, 'status'),
        'admin_archive_user' => sa_admin_user_change($data, 'archive'),
        'admin_restore_user' => sa_admin_user_change($data, 'restore'),
        'admin_delete_user' => sa_admin_user_change($data, 'delete'),
        'admin_toggle_user' => sa_admin_toggle_user($data),
        'internal_update_profile' => sa_internal_update_profile($data),
        'head_save_announcement' => sa_announcement_save($data),
        'head_announcement_transition' => sa_announcement_transition($data),
        'head_create_announcement' => sa_announcement_change($data, 'create'),
        'head_edit_announcement' => sa_announcement_change($data, 'edit'),
        'head_delete_announcement' => sa_announcement_change($data, 'delete'),
        'head_publish_announcement' => sa_announcement_change($data, 'publish'),
        'head_schedule_announcement' => sa_announcement_change($data, 'schedule'),
        'mark_notification_read' => sa_mark_notification_read($data),
        default => throw new SaHttpException(404),
    };
    $response = ['ok' => true, 'data' => $result];
    if (isset($result['request']) && is_array($result['request'])) {
        $response['request'] = $result['request'];
    } elseif ($action === 'submit_request') {
        $response['request'] = ['reference_no' => $result['reference_no'] ?? null];
    }
    sa_json_response($response);
} catch (SaHttpException $exception) {
    $failedAction = isset($action) ? (string) $action
        : (string) ((isset($data) && is_array($data) ? ($data['action'] ?? '') : '') ?: ($_GET['action'] ?? ''));
    sa_log_api_failure($exception->statusCode, $failedAction, $exception, isset($data) && is_array($data) ? $data : null);
    $message = $exception->getMessage() !== 'Request could not be completed.'
        ? $exception->getMessage()
        : sa_default_error_message($exception->statusCode);
    sa_json_response(['error' => $message . ' (Ref: ' . sa_request_ref() . ')', 'ref' => sa_request_ref()], $exception->statusCode);
} catch (Throwable $exception) {
    $failedAction = isset($action) ? (string) $action
        : (string) ((isset($data) && is_array($data) ? ($data['action'] ?? '') : '') ?: ($_GET['action'] ?? ''));
    sa_log_api_failure(500, $failedAction, $exception, isset($data) && is_array($data) ? $data : null);
    sa_json_response([
        'error' => 'The server hit an unexpected problem and nothing was saved. Please try again; if it keeps happening, report reference '
            . sa_request_ref() . ' to the system administrator.',
        'ref' => sa_request_ref(),
    ], 500);
}
