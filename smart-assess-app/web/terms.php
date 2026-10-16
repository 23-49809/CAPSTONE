<?php
declare(strict_types=1);

/*
 * Terms and Conditions acceptance for guest applications.
 *
 * The popup shown before a Document Request / Land Transfer form calls
 * terms_accept when the client ticks the box and presses Continue. That
 * records the acceptance (time, terms version, application type) and returns
 * a random token, which the page keeps with the application (including saved
 * drafts). sa_terms_consume() then requires a valid, current, unused token at
 * final submission, so acceptance cannot be skipped by editing the page.
 *
 * Bump SA_TERMS_VERSION (and TERMS_VERSION in smart-assess.html) whenever the
 * text changes; acceptances of an older version are then refused.
 */

const SA_TERMS_VERSION = '2026-10-16';
const SA_TERMS_VALID_DAYS = 30;   // same as the longest a draft can live

function sa_terms_enabled(): bool
{
    return sa_has_column('terms_acceptances', 'token_hash');
}

function sa_terms_hash(string $token): string
{
    return hash('sha256', 'smart-assess-terms|' . $token);
}

function sa_terms_accept(array $data): array
{
    if (!sa_terms_enabled()) {
        throw new SaHttpException(503, 'Accepting the Terms and Conditions is not set up yet. Ask the administrator to run the 20261015 terms migration.');
    }
    $flow = (string) ($data['flow'] ?? '');
    if (!in_array($flow, ['docreq', 'landtransfer'], true)) {
        throw new SaHttpException(422, 'Choose Document Request or Land Transfer first.');
    }
    if (!filter_var($data['agreed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        throw new SaHttpException(422, 'Tick the box to agree to the Terms and Conditions.');
    }
    if ((string) ($data['terms_version'] ?? '') !== SA_TERMS_VERSION) {
        throw new SaHttpException(409, 'The Terms and Conditions were updated. Reload the page to read the current version.');
    }
    if (sa_drafts_enabled()) {
        sa_draft_rate_check('terms', 60, 60, 'Too many attempts from this connection. Please wait a while and try again.');
        sa_draft_rate_hit('terms');
    }
    $token = bin2hex(random_bytes(24));
    sa_db()->prepare(
        'INSERT INTO terms_acceptances (token_hash, terms_version, flow, client_hash) VALUES (:h, :v, :f, :c)'
    )->execute(['h' => sa_terms_hash($token), 'v' => SA_TERMS_VERSION, 'f' => $flow, 'c' => sa_draft_client_hash()]);
    return ['terms_token' => $token, 'terms_version' => SA_TERMS_VERSION];
}

/**
 * Inside the submission transaction: lock the acceptance, check it is
 * current, for this application type, recent and unused; returns its id.
 */
function sa_terms_require(PDO $pdo, array $data, string $flow): int
{
    $missing = 'Please read and accept the Terms and Conditions before submitting your request.';
    if (!sa_terms_enabled()) {
        throw new SaHttpException(503, 'Terms and Conditions acceptance is not set up yet. Ask the administrator to run the 20261015 terms migration.');
    }
    // "terms_required" / "terms_outdated" tell the page to show the Terms
    // popup again and retry with a fresh acceptance (see php-bridge.js).
    $token = (string) ($data['terms_token'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        throw new SaHttpException(422, $missing, 'terms_required');
    }
    $stmt = $pdo->prepare(
        'SELECT id, terms_version, flow, request_id, accepted_at > DATE_SUB(NOW(), INTERVAL ' . SA_TERMS_VALID_DAYS . ' DAY) AS fresh
         FROM terms_acceptances WHERE token_hash = :h LIMIT 1 FOR UPDATE'
    );
    $stmt->execute(['h' => sa_terms_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || $row['flow'] !== $flow || (int) $row['fresh'] !== 1) {
        throw new SaHttpException(422, $missing, 'terms_required');
    }
    if ($row['terms_version'] !== SA_TERMS_VERSION) {
        throw new SaHttpException(422, 'The Terms and Conditions were updated since you accepted them. Please review and accept the current version, then submit again.', 'terms_outdated');
    }
    if ($row['request_id'] !== null) {
        throw new SaHttpException(409, 'This application was already submitted. Use Track Request to check its status.');
    }
    return (int) $row['id'];
}

function sa_terms_link(PDO $pdo, int $acceptanceId, int $requestId): void
{
    $pdo->prepare('UPDATE terms_acceptances SET request_id = :r, used_at = NOW() WHERE id = :id')
        ->execute(['r' => $requestId, 'id' => $acceptanceId]);
}
