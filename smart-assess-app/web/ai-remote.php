<?php
declare(strict_types=1);

/*
 * SMART ASSESS remote AI worker endpoint (machine to machine; no browser, no session).
 *
 * A remote worker (bin/ai-remote-pull.php) connects OUT to this endpoint over HTTPS to
 * claim AI jobs, download one document at a time, and return validated results. See
 * app/services/AiRemoteQueue.php and docs/AI_DOCUMENT_VERIFICATION.md ("Remote pull worker").
 *
 * DISABLED BY DEFAULT. Unless the web server sets SMART_ASSESS_AI_REMOTE_ENABLED=1 and its
 * own SMART_ASSESS_AI_REMOTE_SECRET (at least 32 characters, never the AWS worker's secret),
 * every request gets the same 404 as a page that does not exist. Even when enabled, no job
 * is claimed unless 'remote' is the selected processor (bin/ai-processor.php) and the
 * Privacy Policy effective date is set: the normal eligibility rules apply unchanged.
 *
 * Layers, in order: optional address allowlist (SMART_ASSESS_AI_REMOTE_ALLOWED_IPS; never the
 * only protection) -> POST + JSON + size -> signature (current secret, or during a rotation
 * SMART_ASSESS_AI_REMOTE_SECRET_NEXT; timestamp within 5 minutes) -> nonce never seen before ->
 * maintenance gate for claim/complete/fail -> processor selected -> one live claim at a time
 * and hourly claim/download limits (AiRemoteQueue). Every answer after authentication is signed,
 * with the secret that verified the request, over its nonce, status and body.
 *
 * Never logs or returns document text, addresses or secrets; never changes a request's status.
 */

define('SA_NO_SESSION', true);
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/services/AiRemoteQueue.php';

const SA_REMOTE_MAX_BODY_BYTES = 1048576;
const SA_REMOTE_PATH_SUFFIX = '/ai-remote.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

$plain = static function (int $status, string $error): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $error]);
    exit;
};

if (getenv('SMART_ASSESS_AI_REMOTE_ENABLED') !== '1') {
    $plain(404, 'not_found');
}
// Fail closed (review L-A): without a private, application-owned monitoring folder
// (smart-assess-app/logs/ai-remote-monitor, 0700, not a link) the endpoint does not run.
if (!AiRemoteMonitor::ready()) {
    error_log('[SMART_ASSESS_REMOTE] The monitoring folder cannot be secured (missing, a link, not owned by the web server or not private); refusing every request.');
    $plain(404, 'not_found');
}
// Optional extra layer: a refused address gets the same 404 as a disabled endpoint.
if (!AiRemoteQueue::ipAllowed((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) getenv('SMART_ASSESS_AI_REMOTE_ALLOWED_IPS'))) {
    AiRemoteMonitor::note('address_refused');
    $plain(404, 'not_found');
}
// The current secret and, only during a rotation, the next one. Neither may be the AWS worker's.
$signers = array_values(array_filter([RemoteWorkerSignature::fromEnvironment('SMART_ASSESS_AI_REMOTE_SECRET'),
    RemoteWorkerSignature::fromEnvironment('SMART_ASSESS_AI_REMOTE_SECRET_NEXT')]));
if (RemoteWorkerSignature::fromEnvironment('SMART_ASSESS_AI_REMOTE_SECRET') === null) {
    error_log('[SMART_ASSESS_REMOTE] Enabled but SMART_ASSESS_AI_REMOTE_SECRET is missing or shorter than 32 characters; refusing every request.');
    $plain(404, 'not_found');
}
$awsSecret = getenv('SMART_ASSESS_AI_WORKER_SECRET');
foreach ($signers as $candidate) {
    if (is_string($awsSecret) && trim($awsSecret) !== '' && $candidate->sameSecretAs($awsSecret)) {
        error_log('[SMART_ASSESS_REMOTE] A remote secret equals the AWS worker secret; refusing every request. Use separate secrets.');
        $plain(404, 'not_found');
    }
}
// Time-boxed (review H-A): outside the agreed window (SMART_ASSESS_AI_REMOTE_ENABLED_UNTIL unset,
// invalid, past, or more than 31 days ahead) every request gets the same 404 as a disabled
// endpoint. A correctly signed claim outside the window is counted separately for alerting; this
// never touches the database.
if (AiRemoteQueue::enabledUntil(time()) === null) {
    $kind = 'outside_window';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) <= SA_REMOTE_MAX_BODY_BYTES) {
        $windowBody = (string) file_get_contents('php://input', false, null, 0, SA_REMOTE_MAX_BODY_BYTES);
        $windowPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        foreach ($signers as $candidate) {
            if ($candidate->verifyRequest('POST', $windowPath, $windowBody, (string) ($_SERVER['HTTP_X_SA_REMOTE_TIMESTAMP'] ?? ''),
                (string) ($_SERVER['HTTP_X_SA_REMOTE_NONCE'] ?? ''), (string) ($_SERVER['HTTP_X_SA_REMOTE_SIGNATURE'] ?? ''), time())
                && (json_decode($windowBody, true)['op'] ?? null) === 'claim') {
                $kind = 'claim_outside_window';
                break;
            }
        }
    }
    AiRemoteMonitor::note($kind);
    $plain(404, 'not_found');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $plain(405, 'method_not_allowed');
}
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (!str_ends_with($path, SA_REMOTE_PATH_SUFFIX)) {
    $plain(404, 'not_found');
}
if (!preg_match('~^application/json(?:\s*;|$)~i', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))) {
    $plain(415, 'unsupported_media_type');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > SA_REMOTE_MAX_BODY_BYTES) {
    $plain(413, 'too_large');
}
$body = (string) file_get_contents('php://input', false, null, 0, SA_REMOTE_MAX_BODY_BYTES + 1);
if (strlen($body) > SA_REMOTE_MAX_BODY_BYTES) {
    $plain(413, 'too_large');
}

$header = static fn (string $name): string => (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
$nonce = $header(RemoteWorkerSignature::HEADER_NONCE);
$now = time();
$signer = null;
foreach ($signers as $candidate) {
    if ($candidate->verifyRequest('POST', $path, $body, $header(RemoteWorkerSignature::HEADER_TIMESTAMP), $nonce,
        $header(RemoteWorkerSignature::HEADER_SIGNATURE), $now)) {
        $signer = $candidate;
        break;
    }
}
if ($signer === null) {
    AiRemoteMonitor::note('unauthenticated');   // at most one summary log line per minute
    $plain(401, 'unauthorized');
}

// From here on every answer is signed for this request's nonce, with the secret that verified it.
$respond = static function (int $status, array $data) use ($signer, $nonce): never {
    $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    http_response_code($status);
    header('Content-Type: application/json');
    header(RemoteWorkerSignature::HEADER_RESPONSE_SIGNATURE . ': ' . $signer->signResponse($nonce, $status, $json));
    echo $json;
    exit;
};

try {
    $pdo = sa_db();
    if (!AiVerification::available($pdo) || !AiVerification::processorSelectionInstalled($pdo)) {
        $respond(503, ['error' => 'not_installed']);
    }
    if (!AiRemoteQueue::rememberNonce($pdo, $nonce, $now)) {
        AiRemoteQueue::recordEvent($pdo, 'replay', null, $now);
        AiRemoteMonitor::note('replay');
        $respond(409, ['error' => 'replayed']);
    }
} catch (Throwable) {
    error_log('[SMART_ASSESS_REMOTE] The database is not available.');
    $respond(503, ['error' => 'database_unavailable']);
}

$request = json_decode($body, true, 16);
$op = is_array($request) ? ($request['op'] ?? null) : null;
$exact = static fn (array $keys): bool => is_array($request) && array_keys($request) === $keys;
$jobId = is_array($request) && is_int($request['job_id'] ?? null) ? $request['job_id'] : 0;
$token = is_array($request) && is_string($request['claim_token'] ?? null) ? $request['claim_token'] : '';
$appRoot = dirname(__DIR__);

try {
    $processor = AiVerification::processor($pdo);
    if ($op === 'health' && $exact(['op'])) {
        $respond(200, ['ok' => true, 'processor' => $processor, 'ai_enabled' => AiVerification::privacyEffectiveFrom() !== null,
            'maintenance' => sa_maintenance_active()]);
    }
    // While a deployment holds the maintenance gate, nothing is claimed, stored or failed
    // (downloads are read-only and stay possible).
    if (in_array($op, ['claim', 'complete', 'fail'], true) && sa_maintenance_active()) {
        AiRemoteQueue::recordEvent($pdo, 'maintenance', $jobId > 0 ? $jobId : null, $now);
        AiRemoteMonitor::note('maintenance');
        $respond(503, ['error' => 'maintenance']);
    }
    if ($processor !== AiVerification::PROCESSOR_REMOTE) {
        $respond(503, ['error' => 'processor_not_selected', 'processor' => $processor]);
    }
    if ($op === 'claim' && $exact(['op'])) {
        $claimed = AiRemoteQueue::claim($pdo, $appRoot, AiRemoteQueue::leaseSeconds(), $now);
        if (isset($claimed['error'])) {
            if ($claimed['error'] === 'rate_limited') {
                AiRemoteMonitor::note('rate_limited');
                $respond(429, ['error' => 'rate_limited']);
            }
            $respond(503, ['error' => $claimed['error']]);
        }
        if (isset($claimed['job']['id'])) {
            error_log('[SMART_ASSESS_REMOTE] Job ' . (int) $claimed['job']['id'] . ' claimed by the remote worker.');
        }
        $respond(200, $claimed);
    }
    if ($op === 'document' && $exact(['op', 'job_id', 'claim_token', 'key']) && is_string($request['key'])) {
        $document = AiRemoteQueue::document($pdo, $appRoot, $jobId, $token, $request['key'], $now);
        if (isset($document['error'])) {
            if ($document['error'] === 'rate_limited') {
                AiRemoteMonitor::note('rate_limited');
                $respond(429, ['error' => 'rate_limited']);
            }
            $respond(in_array($document['error'], ['unknown', 'unknown_document'], true) ? 404 : 409, ['error' => $document['error']]);
        }
        // Streamed: the signature covers the file's SHA-256, sent ahead of the body.
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $document['size']);
        header(RemoteWorkerSignature::HEADER_BODY_SHA256 . ': ' . $document['sha256']);
        header(RemoteWorkerSignature::HEADER_RESPONSE_SIGNATURE . ': ' . $signer->signResponseHash($nonce, 200, $document['sha256']));
        readfile($document['path']);
        exit;
    }
    if ($op === 'complete' && $exact(['op', 'job_id', 'claim_token', 'result'])) {
        $completed = AiRemoteQueue::complete($pdo, $appRoot, $jobId, $token, $request['result'], $now);
        $status = match ($completed['result']) {
            'stored', 'already_stored' => 200,
            'rejected' => 422,
            'unknown' => 404,
            'processor_not_selected' => 503,
            default => 409,
        };
        error_log('[SMART_ASSESS_REMOTE] Job ' . $jobId . ': ' . $completed['result'] . '.');
        $respond($status, $completed);
    }
    if ($op === 'fail' && $exact(['op', 'job_id', 'claim_token', 'code']) && is_string($request['code'])) {
        $failed = AiRemoteQueue::fail($pdo, $jobId, $token, $request['code'], $now);
        $status = match ($failed['result']) {
            'recorded' => 200,
            'invalid_code' => 422,
            'unknown' => 404,
            default => 409,
        };
        error_log('[SMART_ASSESS_REMOTE] Job ' . $jobId . ' failure reported: ' . preg_replace('/[^a-z_]/', '', $request['code']) . ' (' . $failed['result'] . ').');
        $respond($status, $failed);
    }
    $respond(400, ['error' => 'bad_request']);
} catch (Throwable $exception) {
    error_log('[SMART_ASSESS_REMOTE] Internal error (' . get_class($exception) . ').');
    $respond(500, ['error' => 'internal_error']);
}
