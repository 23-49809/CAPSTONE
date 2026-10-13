<?php
declare(strict_types=1);

require_once __DIR__ . '/prototype.php';

$page = sa_internal_bootstrap();
$user = $page['user'] ?? null;
$roleById = [2 => 'staff', 3 => 'admin', 4 => 'head'];
$toAccount = static function (array $account) use ($roleById): array {
    $roleId = (int) ($account['role_id'] ?? 0);
    return [
        'id' => (string) ($account['id'] ?? ''),
        'fullName' => (string) ($account['name'] ?? ''),
        'username' => (string) ($account['username'] ?? ''),
        'email' => (string) ($account['email'] ?? ''),
        'contact' => (string) ($account['contact_number'] ?? ''),
        'position' => (string) ($account['position_title'] ?? $account['role'] ?? ''),
        'role' => $roleById[$roleId] ?? 'staff',
        'status' => strtolower((string) ($account['status'] ?? 'Inactive')),
        'dateCreated' => substr((string) ($account['created_at'] ?? ''), 0, 10),
    ];
};

$page['session'] = $user ? ['username' => (string) $user['username']] : null;
$page['accounts'] = array_map(
    $toAccount,
    $page['accounts'] ?? ($user ? [$user] : [])
);
$page['archivedAccounts'] = array_map($toAccount, $page['archives'] ?? []);
$page['docRequests'] = [];
$page['landRequests'] = [];
$documentsByRequest = [];
foreach ($page['documents'] ?? [] as $document) {
    $validation = strtolower((string) ($document['validation_status'] ?? 'pending'));
    $documentsByRequest[(int) $document['request_id']][] = [
        'name' => (string) $document['label'],
        'submitted' => ($document['file_status'] ?? 'missing') === 'missing' ? 'Missing' : 'Submitted',
        'validation' => match ($validation) {
            'valid' => 'Valid',
            'invalid' => 'Invalid',
            default => 'Pending Review',
        },
        'remarks' => (string) ($document['remarks'] ?? ''),
    ];
}
$requestByReference = [];
foreach ($page['requests'] ?? [] as $request) {
    $requestId = (int) $request['id'];
    $reference = (string) $request['reference_no'];
    $record = [
        'id' => $reference,
        'requestId' => $requestId,
        'applicant' => trim((string) $request['first_name'] . ' ' .
            (string) ($request['middle_name'] ?? '') . ' ' . (string) $request['last_name']),
        'barangay' => (string) $request['barangay'],
        'docType' => (string) ($request['document_type'] ?? ''),
        'purpose' => (string) $request['purpose'],
        'transferType' => (string) ($request['transfer_type'] ?? ''),
        'tctNo' => (string) $request['arp_number'],
        'submitted' => (string) $request['created_at'],
        'status' => (string) $request['status'],
        'processingStartedAt' => $request['processing_started_at'] === null
            ? null : (string) $request['processing_started_at'],
        'processingStartedEpoch' => $request['processing_started_epoch'] === null
            ? null : (int) $request['processing_started_epoch'],
        'processingCompletedAt' => $request['processing_completed_at'] === null
            ? null : (string) $request['processing_completed_at'],
        'processingCompletedEpoch' => $request['processing_completed_epoch'] === null
            ? null : (int) $request['processing_completed_epoch'],
        'processingDurationSeconds' => $request['processing_duration_seconds'] === null
            ? null : (int) $request['processing_duration_seconds'],
        'processingStaffId' => $request['processing_staff_id'] === null
            ? null : (int) $request['processing_staff_id'],
        'processingStaffName' => $request['processing_staff_name'] === null
            ? null : (string) $request['processing_staff_name'],
        'checklist' => $documentsByRequest[$requestId] ?? [],
        'history' => [],
    ];
    $requestByReference[$reference] = $record;
    if ($request['flow'] === 'docreq') {
        $page['docRequests'][] = $record;
    } else {
        $page['landRequests'][] = $record;
    }
}
foreach ($page['notifications'] ?? [] as $notification) {
    $reference = (string) ($notification['reference_no'] ?? '');
    if (isset($requestByReference[$reference])) {
        $requestByReference[$reference]['history'][] = [
            'status' => (string) $notification['status'],
            'message' => (string) ($notification['message'] ?? ''),
            'when' => (string) $notification['created_at'],
        ];
    }
}
foreach (['docRequests', 'landRequests'] as $requestList) {
    foreach ($page[$requestList] as &$request) {
        $request['history'] = $requestByReference[$request['id']]['history'] ?? [];
    }
    unset($request);
}
$page['announcements'] = array_map(static function (array $announcement): array {
    return [
        'id' => (string) $announcement['id'],
        'title' => (string) $announcement['title'],
        'content' => (string) ($announcement['body'] ?? ''),
        'datePosted' => substr((string) ($announcement['created_at'] ?? ''), 0, 10),
        'postedBy' => (string) ($announcement['author'] ?? ''),
        'postTo' => (string) ($announcement['audience'] ?? 'both'),
        'status' => (string) ($announcement['status'] ?? 'Draft'),
        'scheduledAt' => $announcement['scheduled_at'] ?? null,
        'publishedAt' => $announcement['published_at'] ?? null,
    ];
}, $page['announcements'] ?? []);
if ($user && (int) $user['role_id'] !== 4) {
    $page['announcements'] = array_map(static function (array $announcement): array {
        return [
            'id' => (string) $announcement['id'],
            'title' => (string) $announcement['title'],
            'content' => (string) $announcement['body'],
            'datePosted' => (string) $announcement['start_date'],
            'postedBy' => (string) $announcement['author'],
            'postTo' => (string) $announcement['audience'],
            'status' => (string) $announcement['status'],
            'scheduledAt' => $announcement['scheduled_at'] ?? null,
            'publishedAt' => $announcement['published_at'] ?? null,
        ];
    }, sa_db()->query(
        "SELECT id, title, body, author, audience, start_date, status, scheduled_at, published_at
         FROM announcements
         WHERE status = 'Published' AND audience IN ('staff', 'both')
           AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY COALESCE(published_at, created_at) DESC"
    )->fetchAll());
}
$page['reports'] = [];
$reportDate = date('Y-m-d');
$documentRequests = $page['docRequests'];
$landRequests = $page['landRequests'];
$allRequests = array_merge($documentRequests, $landRequests);
$reportStats = static function (array $requests): array {
    $completed = count(array_filter($requests, static fn(array $request): bool =>
        in_array($request['status'], ['Approved', 'Out for Release'], true)
    ));
    $rejected = count(array_filter($requests, static fn(array $request): bool =>
        $request['status'] === 'Rejected'
    ));
    $timedOut = count(array_filter($requests, static fn(array $request): bool =>
        $request['status'] === 'Timed Out'
    ));
    $durations = array_values(array_filter(
        array_column($requests, 'processingDurationSeconds'),
        static fn(mixed $duration): bool => $duration !== null
    ));
    $assigned = count(array_filter(
        $requests,
        static fn(array $request): bool => $request['processingStaffId'] !== null
    ));
    return [
        'total' => count($requests),
        'completed' => $completed,
        'pending' => count($requests) - $completed - $rejected - $timedOut,
        'rejected' => $rejected,
        'timedOut' => $timedOut,
        'assigned' => $assigned,
        'unassigned' => count($requests) - $assigned,
        'completedWithDuration' => count($durations),
        'averageDurationSeconds' => $durations === []
            ? null : (int) round(array_sum($durations) / count($durations)),
        'requestDetails' => array_map(static fn(array $request): array => [
            'reference' => $request['id'],
            'applicant' => $request['applicant'],
            'status' => $request['status'],
            'assignedProcessor' => $request['processingStaffId'] === null
                ? null : (string) ($request['processingStaffName'] ?? 'Staff account #' . $request['processingStaffId']),
            'assignedProcessorId' => $request['processingStaffId'],
            'startedAt' => $request['processingStartedAt'],
            'completedAt' => $request['processingCompletedAt'],
            'durationSeconds' => $request['processingDurationSeconds'],
        ], $requests),
    ];
};
$requirementCounts = ['checked' => 0, 'complete' => 0, 'incomplete' => 0, 'invalid' => 0];
$barangayCounts = [];
foreach ($allRequests as $request) {
    $checklist = $request['checklist'];
    $hasMissing = count(array_filter($checklist, static fn(array $item): bool => $item['submitted'] === 'Missing')) > 0;
    $hasInvalid = count(array_filter($checklist, static fn(array $item): bool => $item['validation'] === 'Invalid')) > 0;
    if ($checklist !== []) {
        $requirementCounts['checked']++;
        if ($hasInvalid) {
            $requirementCounts['invalid']++;
        } elseif ($hasMissing) {
            $requirementCounts['incomplete']++;
        } else {
            $requirementCounts['complete']++;
        }
    }
    $barangayCounts[$request['barangay']] = ($barangayCounts[$request['barangay']] ?? 0) + 1;
}
arsort($barangayCounts);
$topBarangay = (string) (array_key_first($barangayCounts) ?? '—');
$page['reports'] = [
    ['id' => 'RPT-DOC', 'title' => 'Document Request Summary', 'type' => 'document', 'dateGenerated' => $reportDate, 'stats' => $reportStats($documentRequests)],
    ['id' => 'RPT-LAND', 'title' => 'Land Transfer Summary', 'type' => 'land-transfer', 'dateGenerated' => $reportDate, 'stats' => $reportStats($landRequests)],
    ['id' => 'RPT-REQ', 'title' => 'Requirement Checker Results', 'type' => 'requirement', 'dateGenerated' => $reportDate, 'stats' => $requirementCounts],
    ['id' => 'RPT-SUM', 'title' => 'Barangay Request Breakdown', 'type' => 'summary', 'dateGenerated' => $reportDate, 'stats' => [
        'barangays' => count($barangayCounts),
        'topBarangay' => $topBarangay,
        'topCount' => (int) ($barangayCounts[$topBarangay] ?? 0),
    ]],
];
$page['adminProcessingStats'] = [
    'documents' => $reportStats($documentRequests),
    'landTransfers' => $reportStats($landRequests),
];
$page['timeLogs'] = [];
$page['notifs'] = array_map(static function (array $notification): array {
    $clientName = trim((string) ($notification['client_name'] ?? ''));
    return [
        'id' => (string) ($notification['id'] ?? ''),
        'requestRef' => (string) ($notification['reference_no'] ?? ''),
        'clientName' => $clientName !== '' ? $clientName : 'Client information unavailable',
        'category' => 'Request Status Update',
        'message' => (string) ($notification['message'] ?? ''),
        'sentBy' => (string) ($notification['actor'] ?? 'system'),
        'sentAt' => (string) ($notification['created_at'] ?? ''),
    ];
}, $page['notifications'] ?? []);
$page['todayClock'] = [];

$requestedView = (string) ($_GET['view'] ?? 'dashboard');
$allowedViews = [
    'dashboard', 'staff-accounts', 'archived-accounts', 'create-account',
    'staff-info', 'profile', 'announcements', 'reports', 'timestamp',
    'document-requests', 'land-transfer-requests', 'requirement-results',
    'notifications',
];
if (!in_array($requestedView, $allowedViews, true)) {
    $requestedView = 'dashboard';
}
$page['view'] = $user ? $requestedView : 'dashboard';
$page['sidebarOpen'] = false;
$page['modal'] = null;
$page['confirm'] = null;
$page['toasts'] = [];
$page['search'] = [
    'staffAccounts' => '',
    'docRequests' => '',
    'landRequests' => '',
    'reqResults' => '',
    'reports' => '',
    'announcements' => '',
];
$page['filters'] = [
    'staffAccountsStatus' => 'all',
    'docStatus' => 'all',
    'landStatus' => 'all',
    'reqResult' => 'all',
    'reportType' => 'all',
    'annStatus' => 'all',
];
$page['pages'] = [];

sa_emit_prototype('smart-assess-internal.html', $page, 'internal');
