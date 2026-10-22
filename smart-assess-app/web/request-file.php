<?php
declare(strict_types=1);

/*
 * Streams one document a client uploaded with a request, to signed-in staff
 * only (the role that reviews requests). The file is found by its document ID
 * AND the request's reference number together, never by file name; the stored
 * path must sit inside private_uploads/requests, and only real JPG/PNG/WEBP/PDF
 * content is served (detected from the file itself, never executed).
 *
 *   GET /request-file.php?doc=<document id>&ref=<reference>[&download=1]
 */

require_once __DIR__ . '/bootstrap.php';

$fail = static function (int $status, string $text): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $text;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $fail(405, 'Method not allowed.');
}
$user = sa_current_internal();
if (!$user) {
    $fail(401, 'Sign in to view this document.');
}
if ((int) $user['role_id'] !== 2) {
    $fail(403, 'Your account cannot view client documents.');
}

$documentId = filter_var($_GET['doc'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reference = strtoupper(trim((string) ($_GET['ref'] ?? '')));
if (!$documentId || !preg_match('/^(?:DR|LT)-\d{4}-[A-Z0-9]{1,12}$/', $reference)) {
    $fail(404, 'Document not found.');
}

$stmt = sa_db()->prepare(
    'SELECT rd.stored_path, rd.original_name, rd.file_status
     FROM request_documents rd
     JOIN requests r ON r.id = rd.request_id
     WHERE rd.id = :document_id AND r.reference_no = :reference
     LIMIT 1'
);
$stmt->execute(['document_id' => $documentId, 'reference' => $reference]);
$document = $stmt->fetch();
$storedPath = (string) ($document['stored_path'] ?? '');
if (!$document || $document['file_status'] === 'missing' ||
    !preg_match('~^private_uploads/requests/[A-Za-z0-9_]+\.(?:pdf|jpg|jpeg|png|webp)$~', $storedPath)) {
    $fail(404, 'This document was not uploaded or is no longer available.');
}

$base = realpath(dirname(__DIR__) . '/private_uploads/requests');
$path = realpath(dirname(__DIR__) . '/' . $storedPath);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    $fail(404, 'This document is missing from storage.');
}

$types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!is_string($mime) || !isset($types[$mime])) {
    $fail(415, 'This file type cannot be displayed.');
}

// Download name: the client's original name, reduced to safe characters.
$name = pathinfo((string) ($document['original_name'] ?? ''), PATHINFO_FILENAME);
$name = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name), ' ._');
$name = ($name !== '' ? substr($name, 0, 80) : 'document') . '.' . $types[$mime];
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
if ($mime !== 'application/pdf') {
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
}
readfile($path);
