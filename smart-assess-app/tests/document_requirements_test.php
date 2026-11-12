<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/services/DocumentRequirements.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $result) use (&$passed, &$failed): void {
    printf("%s %s\n", $result ? 'PASS' : 'FAIL', $name);
    $result ? $passed++ : $failed++;
};
$service = new DocumentRequirements();
$validIdFile = ['mime' => 'image/jpeg', 'size' => 1024];
$validSupportingFile = ['mime' => 'application/pdf', 'size' => 1024];
$validOwnerFiles = ['ownerId' => $validIdFile];
$validRepresentativeFiles = [
    'ownerId' => $validIdFile,
    'requesterId' => $validIdFile,
    'authLetter' => $validIdFile,
];
$landFiles = [
    'ctcTdOrTitle' => $validSupportingFile,
    'notarialDeed' => $validSupportingFile,
    'vicinityMap' => $validSupportingFile,
    'certNoImprovement' => $validSupportingFile,
    'taxClearance' => $validSupportingFile,
];

$ownerDocreq = $service->evaluate('docreq', true, $validOwnerFiles);
$check('docreq owner requires only ownerId and accepts a valid-format upload',
    $ownerDocreq['complete'] &&
    array_column($ownerDocreq['items'], 'key') === ['ownerId'] &&
    $ownerDocreq['items'][0]['status'] === DocumentRequirements::STATUS_UPLOADED_VALID_FORMAT);

$representativeDocreq = $service->evaluate('docreq', false, $validRepresentativeFiles);
$check('docreq representative requires requesterId, ownerId, and authLetter',
    $representativeDocreq['complete'] &&
    array_column($representativeDocreq['items'], 'key') === ['ownerId', 'requesterId', 'authLetter']);

$ownerLand = $service->evaluate('landtransfer', true, $landFiles + $validOwnerFiles);
$check('landtransfer owner requires all five land documents and ownerId',
    $ownerLand['complete'] &&
    array_column($ownerLand['items'], 'key') === [
        'ctcTdOrTitle', 'notarialDeed', 'vicinityMap', 'certNoImprovement', 'taxClearance', 'ownerId',
    ]);

$representativeLand = $service->evaluate('landtransfer', false, $landFiles + $validRepresentativeFiles);
$check('landtransfer representative requires all five land documents and all representative documents',
    $representativeLand['complete'] &&
    array_column($representativeLand['items'], 'key') === [
        'ctcTdOrTitle', 'notarialDeed', 'vicinityMap', 'certNoImprovement', 'taxClearance',
        'ownerId', 'requesterId', 'authLetter',
    ]);

$missingRepresentative = $service->evaluate('docreq', false, ['ownerId' => $validIdFile]);
$check('missing representative files are reported by required key',
    !$missingRepresentative['complete'] &&
    $missingRepresentative['missing'] === ['requesterId', 'authLetter'] &&
    $missingRepresentative['invalidFormat'] === []);

$missingLand = $service->evaluate('landtransfer', true, []);
$check('missing land-transfer files report every required key',
    !$missingLand['complete'] &&
    $missingLand['missing'] === [
        'ctcTdOrTitle', 'notarialDeed', 'vicinityMap', 'certNoImprovement', 'taxClearance', 'ownerId',
    ]);

$invalidUpload = $service->evaluate('docreq', true, [
    'ownerId' => ['mime' => 'application/zip', 'size' => 1024],
]);
$check('uploaded unsupported MIME type is invalid format, not missing',
    !$invalidUpload['complete'] &&
    $invalidUpload['missing'] === [] &&
    $invalidUpload['invalidFormat'] === ['ownerId'] &&
    $invalidUpload['items'][0]['status'] === DocumentRequirements::STATUS_UPLOADED_INVALID_FORMAT);

$boundaryUploads = $service->evaluate('docreq', true, [
    'ownerId' => ['mime' => 'image/jpeg', 'size' => 5 * 1024 * 1024],
]);
$check('file size of 5 MB is invalid format',
    $boundaryUploads['invalidFormat'] === ['ownerId']);

$belowBoundary = $service->evaluate('docreq', true, [
    'ownerId' => ['mime' => 'image/png', 'size' => (5 * 1024 * 1024) - 1],
]);
$check('allowed MIME type just below 5 MB is valid format',
    $belowBoundary['complete'] &&
    $belowBoundary['items'][0]['status'] === DocumentRequirements::STATUS_UPLOADED_VALID_FORMAT);

$identityMimeResults = [];
foreach (['image/jpeg', 'image/png'] as $mime) {
    $result = $service->evaluate('docreq', true, [
        'ownerId' => ['mime' => $mime, 'size' => 1024],
    ]);
    $identityMimeResults[$mime] = $result['complete'];
}
$check('JPG and PNG identity documents are accepted',
    $identityMimeResults['image/jpeg'] && $identityMimeResults['image/png']);

$invalidIdentityMimeResults = [];
foreach (['application/pdf', 'image/webp'] as $mime) {
    $result = $service->evaluate('docreq', true, [
        'ownerId' => ['mime' => $mime, 'size' => 1024],
    ]);
    $invalidIdentityMimeResults[$mime] = $result['invalidFormat'] === ['ownerId'];
}
$check('PDF and WEBP identity documents are invalid format',
    $invalidIdentityMimeResults['application/pdf'] && $invalidIdentityMimeResults['image/webp']);

$supportingMimeResults = [];
foreach (['application/pdf', 'image/webp'] as $mime) {
    $result = $service->evaluate('landtransfer', true, array_replace($landFiles, [
        'ownerId' => $validIdFile,
        'ctcTdOrTitle' => ['mime' => $mime, 'size' => 1024],
    ]));
    $supportingMimeResults[$mime] = $result['complete'];
}
$check('PDF and WEBP land-transfer supporting documents are accepted',
    $supportingMimeResults['application/pdf'] && $supportingMimeResults['image/webp']);

$scope = $service->evaluate('docreq', true, $validOwnerFiles);
$check('result clearly limits validation to file format and size',
    str_contains($scope['validationScope'], 'content and authenticity are not assessed'));

foreach ([
    'unsupported request type' => static fn() => $service->evaluate('unknown', true, []),
    'non-string request type' => static fn() => $service->evaluate(['docreq'], true, []),
    'non-boolean ownership' => static fn() => $service->evaluate('docreq', 1, []),
    'non-array uploaded files' => static fn() => $service->evaluate('docreq', true, 'ownerId'),
    'non-string uploaded-file key' => static fn() => $service->evaluate('docreq', true, [0 => $validIdFile]),
    'malformed file metadata' => static fn() => $service->evaluate('docreq', true, ['ownerId' => ['mime' => 'image/jpeg']]),
    'non-integer file size' => static fn() => $service->evaluate('docreq', true, ['ownerId' => ['mime' => 'image/jpeg', 'size' => '1024']]),
] as $case => $run) {
    try {
        $run();
        $rejected = false;
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check("malformed input rejects {$case}", $rejected);
}

printf("%d / %d document requirement checks passed\n", $passed, $passed + $failed);
exit($failed === 0 ? 0 : 1);
