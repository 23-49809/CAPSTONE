<?php
declare(strict_types=1);

/*
 * Guest application drafts: auto-save and resume for Document Request and
 * Land Transfer without an account.
 *
 * - A draft is identified only by a random 20-character recovery code
 *   (100 bits from random_bytes). The database stores its SHA-256 hash, so a
 *   leaked table cannot be turned into working resume links, and there are no
 *   sequential/predictable draft IDs anywhere in URLs or responses.
 * - Drafts live in request_drafts / request_draft_files, apart from
 *   `requests`: staff never see them and no notification is sent until the
 *   final submission (sa_submit_request), which marks the draft submitted so
 *   a repeated submission returns the same reference instead of a duplicate.
 * - Drafts expire 7 days after the last save and at most 30 days after
 *   creation; sa_draft_cleanup() deletes expired rows and their files.
 * - Every action is POST + CSRF (checked in api.php), server-validated and
 *   rate limited per client (hashed IP).
 */

const SA_DRAFT_IDLE_DAYS = 7;
const SA_DRAFT_MAX_DAYS = 30;
const SA_DRAFT_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 symbols, no 0/O/1/I
const SA_DRAFT_CODE_LENGTH = 20;

/** Draft form fields the client may save, with their maximum lengths. */
const SA_DRAFT_FIELDS = [
    'firstName' => 80, 'middleName' => 80, 'lastName' => 80, 'contact' => 20, 'email' => 150,
    'addressLine' => 255, 'province' => 80, 'city' => 80, 'zip' => 10, 'requesterBarangay' => 100,
    'documentType' => 120, 'transferType' => 40, 'purpose' => 80, 'arp' => 20, 'barangay' => 100,
    'termsToken' => 48,   // Terms and Conditions acceptance, so a resumed draft is not asked again
];
/** Documents a draft may hold (same keys the final submission uses). */
const SA_DRAFT_DOC_KEYS = [
    'ownerId', 'requesterId', 'authLetter',
    'ctcTdOrTitle', 'notarialDeed', 'vicinityMap', 'certNoImprovement', 'taxClearance',
];

function sa_drafts_enabled(): bool
{
    return sa_has_column('request_drafts', 'token_hash') && sa_has_column('request_draft_files', 'doc_key');
}

function sa_drafts_require(): void
{
    if (!sa_drafts_enabled()) {
        throw new SaHttpException(503, 'Saving applications on the server is not set up yet. Your progress is kept on this device only.');
    }
}

function sa_draft_new_code(): string
{
    $code = '';
    foreach (str_split(random_bytes(SA_DRAFT_CODE_LENGTH)) as $byte) {
        $code .= SA_DRAFT_CODE_ALPHABET[ord($byte) & 31];   // 256 is a multiple of 32: unbiased
    }
    return $code;
}

/** Accepts the code with or without spaces/dashes, any case; null if malformed. */
function sa_draft_normalize_code(mixed $raw): ?string
{
    $code = strtoupper(preg_replace('/[\s-]+/', '', (string) $raw) ?? '');
    if (strlen($code) !== SA_DRAFT_CODE_LENGTH || strspn($code, SA_DRAFT_CODE_ALPHABET) !== SA_DRAFT_CODE_LENGTH) {
        return null;
    }
    return $code;
}

function sa_draft_format_code(string $code): string
{
    return implode('-', str_split($code, 5));
}

function sa_draft_hash(string $code): string
{
    return hash('sha256', 'smart-assess-draft|' . $code);
}

function sa_draft_client_hash(): string
{
    return hash('sha256', 'smart-assess-draft-client|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/** Throws 429 when this client already used $limit actions of $bucket in the window. */
function sa_draft_rate_check(string $bucket, int $limit, int $windowMinutes, string $message): void
{
    $stmt = sa_db()->prepare(
        'SELECT COUNT(*) FROM request_draft_rate
         WHERE client_hash = :c AND bucket = :b AND created_at > DATE_SUB(NOW(), INTERVAL ' . $windowMinutes . ' MINUTE)'
    );
    $stmt->execute(['c' => sa_draft_client_hash(), 'b' => $bucket]);
    if ((int) $stmt->fetchColumn() >= $limit) {
        throw new SaHttpException(429, $message);
    }
}

function sa_draft_rate_hit(string $bucket): void
{
    sa_db()->prepare('INSERT INTO request_draft_rate (client_hash, bucket) VALUES (:c, :b)')
        ->execute(['c' => sa_draft_client_hash(), 'b' => $bucket]);
}

function sa_draft_dir(): string
{
    $base = dirname(__DIR__) . '/private_uploads';
    $dir = $base . '/drafts';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create the draft upload directory.');
    }
    $real = realpath($dir);
    $realBase = realpath($base);
    if ($real === false || $realBase === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $realBase . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Draft upload directory is outside the configured location.');
    }
    return $real;
}

/** Absolute path of a stored draft file, or null if it escapes the draft directory. */
function sa_draft_file_path(string $storedPath): ?string
{
    if (!preg_match('~^private_uploads/drafts/[A-Za-z0-9_]+\.(pdf|jpg|png|webp)$~', $storedPath)) {
        return null;
    }
    return dirname(__DIR__) . '/' . $storedPath;
}

/** Deletes expired drafts (and drafts submitted over a day ago) with their files. */
function sa_draft_cleanup(int $limit = 50): int
{
    $pdo = sa_db();
    $ids = $pdo->query(
        'SELECT id FROM request_drafts
         WHERE expires_at <= NOW() OR (submitted_at IS NOT NULL AND submitted_at <= DATE_SUB(NOW(), INTERVAL 1 DAY))
         ORDER BY expires_at LIMIT ' . max(1, $limit)
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        sa_draft_delete((int) $id);
    }
    $pdo->exec('DELETE FROM request_draft_rate WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    return count($ids);
}

function sa_draft_delete(int $draftId): void
{
    $pdo = sa_db();
    $files = $pdo->prepare('SELECT stored_path FROM request_draft_files WHERE draft_id = :id');
    $files->execute(['id' => $draftId]);
    foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        $path = sa_draft_file_path((string) $stored);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
    $pdo->prepare('DELETE FROM request_drafts WHERE id = :id')->execute(['id' => $draftId]);
}

/**
 * The draft for a recovery code. Throws 404 (unknown), 410 (expired). A
 * submitted draft is returned so callers can report its reference number.
 */
function sa_draft_by_code(mixed $rawCode, bool $forUpdate = false): array
{
    $code = sa_draft_normalize_code($rawCode);
    if ($code === null) {
        throw new SaHttpException(404, 'That recovery code is not valid. Check it and try again.');
    }
    $stmt = sa_db()->prepare(
        'SELECT *, expires_at <= NOW() AS is_expired FROM request_drafts WHERE token_hash = :h LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['h' => sa_draft_hash($code)]);
    $draft = $stmt->fetch();
    if (!$draft) {
        throw new SaHttpException(404, 'No saved application matches this code. It may have expired or been submitted already.');
    }
    if ((int) $draft['is_expired'] === 1 && $draft['submitted_reference'] === null) {
        throw new SaHttpException(410, 'This saved application has expired. Saved applications are kept for ' . SA_DRAFT_IDLE_DAYS . ' days after the last change. Please start a new application.');
    }
    $draft['code'] = $code;
    return $draft;
}

/** Server-side validation of saved draft fields: known keys, strings, length limits. */
function sa_draft_clean_fields(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        throw new SaHttpException(422, 'The application data could not be saved. Reload the page and try again.');
    }
    $clean = [];
    foreach (SA_DRAFT_FIELDS as $key => $max) {
        if (!array_key_exists($key, $raw) || $raw[$key] === null) {
            continue;
        }
        if (!is_scalar($raw[$key])) {
            throw new SaHttpException(422, 'The application data could not be saved. Reload the page and try again.');
        }
        $value = trim((string) $raw[$key]);
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") || mb_strlen($value, 'UTF-8') > $max) {
            throw new SaHttpException(422, 'One of the fields is too long or contains characters that cannot be saved.');
        }
        $clean[$key] = $value;
    }
    $clean['isOwner'] = !array_key_exists('isOwner', $raw) || filter_var($raw['isOwner'], FILTER_VALIDATE_BOOLEAN);
    return $clean;
}

function sa_draft_files_meta(int $draftId): array
{
    $stmt = sa_db()->prepare(
        'SELECT doc_key, original_name, mime_type, file_size FROM request_draft_files WHERE draft_id = :id ORDER BY id'
    );
    $stmt->execute(['id' => $draftId]);
    return array_map(static fn (array $f): array => [
        'doc_key' => (string) $f['doc_key'],
        'name' => (string) $f['original_name'],
        'type' => (string) $f['mime_type'],
        'size' => (int) $f['file_size'],
    ], $stmt->fetchAll());
}

function sa_draft_summary(array $draft): array
{
    $expires = sa_db()->prepare('SELECT expires_at, updated_at FROM request_drafts WHERE id = :id');
    $expires->execute(['id' => $draft['id']]);
    $times = $expires->fetch() ?: [];
    return [
        'code' => sa_draft_format_code((string) $draft['code']),
        'revision' => (int) $draft['revision'],
        'saved_at' => (string) ($times['updated_at'] ?? ''),
        'expires_at' => (string) ($times['expires_at'] ?? ''),
    ];
}

/** Bounded sliding expiry: 7 days from now, never beyond 30 days from creation. */
function sa_draft_expiry_sql(): string
{
    return 'LEAST(DATE_ADD(created_at, INTERVAL ' . SA_DRAFT_MAX_DAYS . ' DAY), DATE_ADD(NOW(), INTERVAL ' . SA_DRAFT_IDLE_DAYS . ' DAY))';
}

/** Create or update a draft. Client revisions only move forward, so a delayed
 *  retry of an older save can never overwrite newer data. */
function sa_draft_save(array $data): array
{
    sa_drafts_require();
    $flow = (string) ($data['flow'] ?? '');
    if (!in_array($flow, ['docreq', 'landtransfer'], true)) {
        throw new SaHttpException(422, 'Choose Document Request or Land Transfer first.');
    }
    $step = filter_var($data['step'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]) ?: 1;
    $maxStep = filter_var($data['max_step'] ?? $step, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]) ?: $step;
    $maxStep = max($step, $maxStep);
    $revision = filter_var($data['revision'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2000000000]]);
    $fields = json_encode(sa_draft_clean_fields($data['fields'] ?? []), JSON_UNESCAPED_UNICODE);
    $pdo = sa_db();

    $code = $data['draft_token'] ?? '';
    if ((string) $code === '') {
        sa_draft_rate_check('create', 20, 60, 'Too many new applications were started from this connection. Please wait a while and try again.');
        sa_draft_cleanup();
        $code = sa_draft_new_code();
        $pdo->prepare(
            'INSERT INTO request_drafts (token_hash, flow, step, max_step, form_data, revision, expires_at)
             VALUES (:h, :flow, :step, :max, :data, :rev, DATE_ADD(NOW(), INTERVAL ' . SA_DRAFT_IDLE_DAYS . ' DAY))'
        )->execute(['h' => sa_draft_hash($code), 'flow' => $flow, 'step' => $step, 'max' => $maxStep, 'data' => $fields, 'rev' => max(1, (int) $revision)]);
        sa_draft_rate_hit('create');
        $draft = sa_draft_by_code($code);
        return ['created' => true, 'submitted' => false] + sa_draft_summary($draft);
    }

    $draft = sa_draft_by_code($code);
    if ($draft['submitted_reference'] !== null) {
        return ['submitted' => true, 'reference_no' => (string) $draft['submitted_reference']];
    }
    if ($draft['flow'] !== $flow) {
        throw new SaHttpException(422, 'This saved application belongs to a different request type.');
    }
    if ($revision !== false && (int) $revision > 0 && (int) $revision <= (int) $draft['revision']) {
        return ['created' => false, 'submitted' => false, 'stale' => true] + sa_draft_summary($draft);
    }
    $pdo->prepare(
        'UPDATE request_drafts SET step = :step, max_step = GREATEST(max_step, :max), form_data = :data,
                revision = GREATEST(revision + 1, :rev), updated_at = NOW(), expires_at = ' . sa_draft_expiry_sql() . '
         WHERE id = :id AND submitted_at IS NULL'
    )->execute(['step' => $step, 'max' => $maxStep, 'data' => $fields, 'rev' => (int) $revision, 'id' => $draft['id']]);
    if (random_int(1, 20) === 1) {
        sa_draft_cleanup();
    }
    return ['created' => false, 'submitted' => false] + sa_draft_summary(sa_draft_by_code($code));
}

function sa_draft_upload(array $data): array
{
    sa_drafts_require();
    $draft = sa_draft_by_code($data['draft_token'] ?? '');
    if ($draft['submitted_reference'] !== null) {
        return ['submitted' => true, 'reference_no' => (string) $draft['submitted_reference']];
    }
    $key = (string) ($data['doc_key'] ?? '');
    if (!in_array($key, SA_DRAFT_DOC_KEYS, true)) {
        throw new SaHttpException(422, 'That document type cannot be uploaded.');
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new SaHttpException(422, 'Select a file to upload.');
    }
    sa_draft_rate_check('upload', 80, 60, 'Too many uploads from this connection. Please wait a few minutes and try again.');
    [$original, $mime, $size, $extension] = sa_validate_upload($_FILES['file']);
    $dir = sa_draft_dir();
    $storedName = 'd' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file((string) $_FILES['file']['tmp_name'], $dir . DIRECTORY_SEPARATOR . $storedName)) {
        throw new RuntimeException('Unable to store the draft file.');
    }
    chmod($dir . DIRECTORY_SEPARATOR . $storedName, 0640);
    sa_draft_rate_hit('upload');
    $pdo = sa_db();
    $old = $pdo->prepare('SELECT stored_path FROM request_draft_files WHERE draft_id = :d AND doc_key = :k');
    $old->execute(['d' => $draft['id'], 'k' => $key]);
    $oldPath = $old->fetchColumn();
    $pdo->prepare(
        'INSERT INTO request_draft_files (draft_id, doc_key, original_name, stored_path, mime_type, file_size)
         VALUES (:d, :k, :n, :p, :m, :s)
         ON DUPLICATE KEY UPDATE original_name = VALUES(original_name), stored_path = VALUES(stored_path),
                                 mime_type = VALUES(mime_type), file_size = VALUES(file_size), created_at = NOW()'
    )->execute(['d' => $draft['id'], 'k' => $key, 'n' => $original, 'p' => 'private_uploads/drafts/' . $storedName, 'm' => $mime, 's' => $size]);
    if (is_string($oldPath) && ($path = sa_draft_file_path($oldPath)) !== null && is_file($path)) {
        @unlink($path);
    }
    $pdo->prepare('UPDATE request_drafts SET updated_at = NOW(), expires_at = ' . sa_draft_expiry_sql() . ' WHERE id = :id')
        ->execute(['id' => $draft['id']]);
    return ['submitted' => false, 'file' => ['doc_key' => $key, 'name' => $original, 'type' => $mime, 'size' => $size]];
}

function sa_draft_remove_file(array $data): array
{
    sa_drafts_require();
    $draft = sa_draft_by_code($data['draft_token'] ?? '');
    $key = (string) ($data['doc_key'] ?? '');
    if (!in_array($key, SA_DRAFT_DOC_KEYS, true)) {
        throw new SaHttpException(422, 'That document type cannot be removed.');
    }
    $stmt = sa_db()->prepare('SELECT stored_path FROM request_draft_files WHERE draft_id = :d AND doc_key = :k');
    $stmt->execute(['d' => $draft['id'], 'k' => $key]);
    $stored = $stmt->fetchColumn();
    if (is_string($stored) && ($path = sa_draft_file_path($stored)) !== null && is_file($path)) {
        @unlink($path);
    }
    sa_db()->prepare('DELETE FROM request_draft_files WHERE draft_id = :d AND doc_key = :k')->execute(['d' => $draft['id'], 'k' => $key]);
    return ['removed' => true];
}

/** Resume: returns the saved fields, step and file list for a recovery code.
 *  Failed lookups are rate limited so codes cannot be guessed. */
function sa_draft_load(array $data): array
{
    sa_drafts_require();
    sa_draft_rate_check('load_fail', 10, 15, 'Too many incorrect recovery codes. Please wait 15 minutes and try again.');
    try {
        $draft = sa_draft_by_code($data['draft_token'] ?? '');
    } catch (SaHttpException $e) {
        if (in_array($e->statusCode, [404, 410], true)) {
            sa_draft_rate_hit('load_fail');
        }
        throw $e;
    }
    if ($draft['submitted_reference'] !== null) {
        return ['submitted' => true, 'reference_no' => (string) $draft['submitted_reference']];
    }
    $fields = json_decode((string) $draft['form_data'], true);
    return [
        'submitted' => false,
        'flow' => (string) $draft['flow'],
        'step' => (int) $draft['step'],
        'max_step' => (int) $draft['max_step'],
        'fields' => is_array($fields) ? $fields : [],
        'files' => sa_draft_files_meta((int) $draft['id']),
    ] + sa_draft_summary($draft);
}

/** Streams one saved draft file (for the upload preview after resuming). */
function sa_draft_file(array $data): never
{
    sa_drafts_require();
    $draft = sa_draft_by_code($data['draft_token'] ?? '');
    $key = (string) ($data['doc_key'] ?? '');
    $stmt = sa_db()->prepare('SELECT stored_path, mime_type FROM request_draft_files WHERE draft_id = :d AND doc_key = :k');
    $stmt->execute(['d' => $draft['id'], 'k' => $key]);
    $file = $stmt->fetch();
    $path = $file ? sa_draft_file_path((string) $file['stored_path']) : null;
    if ($path === null || !is_file($path)) {
        throw new SaHttpException(404, 'That saved file is no longer available. Please upload it again.');
    }
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="document"');
    readfile($path);
    exit;
}

function sa_draft_discard(array $data): array
{
    sa_drafts_require();
    $draft = sa_draft_by_code($data['draft_token'] ?? '');
    if ($draft['submitted_reference'] === null) {
        sa_draft_delete((int) $draft['id']);
    }
    return ['discarded' => true];
}
