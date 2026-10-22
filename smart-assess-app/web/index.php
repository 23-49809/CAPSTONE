<?php
declare(strict_types=1);

require_once __DIR__ . '/prototype.php';

$publicData = sa_public_bootstrap();
$toPublicRequest = static function (array $request): array {
    $documents = sa_db()->prepare(
        'SELECT rd.label, rd.file_status, dv.validation_status
         FROM request_documents rd
         LEFT JOIN document_validation dv ON dv.request_document_id = rd.id
         WHERE rd.request_id = :request_id
         ORDER BY rd.id'
    );
    $documents->execute(['request_id' => $request['id']]);
    $missing = [];
    foreach ($documents->fetchAll() as $document) {
        if ($document['file_status'] === 'missing' || $document['file_status'] === 'flagged' ||
            $document['validation_status'] === 'invalid') {
            $missing[] = (string) $document['label'];
        }
    }
    if (!$request['requirement_complete'] && $missing === []) {
        $missing[] = 'Requirements pending review';
    }
    $timestamps = [];
    foreach ($request['history'] ?? [] as $entry) {
        // First entry per status = when the request reached it (later entries
        // with the same status are staff messages, not status changes).
        $timestamps[(string) $entry['status']] ??= (string) $entry['created_at'];
    }
    return [
        'id' => (string) $request['reference_no'],
        'flow' => (string) $request['flow'],
        'applicant' => trim((string) $request['first_name'] . ' ' . (string) $request['last_name']),
        'docType' => (string) ($request['document_type'] ?? $request['transfer_type'] ?? ''),
        'status' => (string) $request['status'],
        'requirementComplete' => (bool) $request['requirement_complete'],
        'missing' => $missing,
        'timestamps' => $timestamps,
        'submittedAt' => (string) $request['created_at'],
    ];
};
$page = [
    'view' => 'landing',
    'lastSubmission' => null,
    'track' => ['query' => '', 'result' => null, 'searched' => false],
];

$submittedReference = trim((string) ($_GET['submitted'] ?? ''));
$trackingReference = trim((string) ($_GET['track'] ?? ''));
if ($submittedReference !== '') {
    $request = sa_track_request($submittedReference);
    if ($request !== null) {
        $page['view'] = 'confirmation';
        $page['lastSubmission'] = $toPublicRequest($request);
    }
} elseif ($trackingReference !== '') {
    $request = sa_track_request($trackingReference);
    $page['view'] = 'track';
    $page['track'] = [
        'query' => strtoupper($trackingReference),
        'result' => $request === null ? null : $toPublicRequest($request),
        'searched' => true,
    ];
}
$page['announcements'] = array_map(static function (array $announcement): array {
    return [
        'id' => (string) $announcement['id'],
        'title' => (string) $announcement['title'],
        'content' => (string) $announcement['body'],
        'datePosted' => (string) $announcement['start_date'],
        'postTo' => (string) $announcement['audience'],
        'status' => 'Published',
        'scheduledAt' => null,
        'publishedAt' => $announcement['published_at'] ?? null,
        'expiresAt' => $announcement['expires_at'] ?? null,
    ];
}, $publicData['announcements'] ?? []);
$page['serverNow'] = sa_manila_now();

sa_emit_prototype('smart-assess.html', $page, 'client');
