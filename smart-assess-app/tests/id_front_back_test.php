<?php
declare(strict_types=1);

/*
 * Client National ID upload (only the National ID is accepted: PhilSys card or ePhilID; no ID type is
 * chosen), with separate FRONT and BACK photos for the Property Owner's ID (ownerId / ownerIdBack) and
 * the Authorized Representative's ID (requesterId / requesterIdBack). No database server and no network: the automatic-check manifest runs against an
 * in-memory SQLite copy of its two tables, the client page runs in headless Chrome (a test-only hook
 * is added to a temporary copy, never to the page), and the Staff page's own functions run in Node.
 * Every person, ID and image is fictional and generated here.
 *
 *  - only the National ID is accepted: no ID type dropdown or field remains (page, submit script, drafts, server);
 *  - a back side never reaches the automatic check, and neither does an ID type stored by an older request;
 *  - front and back are separate files: choosing, replacing or removing one never touches the other;
 *  - both sides are required for every National ID (page and server);
 *  - Staff are warned when the automatic check identifies another ID type on a National-ID-only request,
 *    and older requests keep showing the type their applicant selected (marked "not verified");
 *  - the new parts keep WCAG AA contrast (4.5:1) in the light and the dark theme;
 *  - front and back sit side by side on desktop and tablet and stack on phones;
 *  - Staff see each back side next to its front, and the selected type marked "not verified".
 *
 *   php tests/id_front_back_test.php     (needs Google Chrome and Node for the page checks; exits 2 without them)
 *   SA_SCREENSHOT_DIR=/some/dir php tests/id_front_back_test.php   also saves desktop, tablet and phone screenshots there
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$app = dirname(__DIR__);
$project = dirname($app);
require_once $app . '/app/services/AiVerification.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 600)];
};
$html = (string) file_get_contents($project . '/smart-assess.html');
$internal = (string) file_get_contents($project . '/smart-assess-internal.html');
$bridge = (string) file_get_contents($app . '/web/assets/php-bridge.js');
$api = (string) file_get_contents($app . '/web/api.php');
$drafts = (string) file_get_contents($app . '/web/drafts.php');
$work = sys_get_temp_dir() . '/sa-id-front-back-' . bin2hex(random_bytes(4));
mkdir($work . '/private_uploads/requests', 0700, true);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($work)));

// ================= 1. National ID only: no ID type is chosen =================
$check('1. only the National ID is accepted (server: the type and how it is described)', DocumentRequirements::ACCEPTED_ID_TYPE === 'philsys_national_id'
    && DocumentRequirements::ACCEPTED_ID_DESCRIPTION === 'National ID (PhilSys card or ePhilID)' && isset(DocumentAnalysis::ID_KINDS['philsys_national_id']));
$check('1. the page has no ID type dropdown and names the accepted ID with the server\'s description', !str_contains($html, 'ID_TYPE_OPTIONS') && !str_contains($html, 'Select Type of Valid ID')
    && !str_contains($html, 'ownerIdType') && !str_contains($html, 'requesterIdType') && str_contains($html, "const ACCEPTED_ID = 'National ID (PhilSys card or ePhilID)';"));
$check('1. no ID type field remains in the submit script or in drafts', !str_contains($bridge, 'owner_id_type') && !str_contains($bridge, 'requester_id_type')
    && !str_contains($drafts, 'ownerIdType') && !str_contains($drafts, 'requesterIdType'));

// ================= 2. Requirements: every National ID's front and back =================
$owner = DocumentRequirements::requirementsFor('docreq', true, true);
$check('2. a new owner submission needs the National ID\'s front and back', $owner === ['ownerId' => 'Property Owner National ID', 'ownerIdBack' => 'Property Owner National ID (back side)'], $owner);
$repDoc = DocumentRequirements::requirementsFor('docreq', false, true);
$check('2. a representative: both National IDs front and back, and the letter', array_keys($repDoc) === ['ownerId', 'requesterId', 'authLetter', 'ownerIdBack', 'requesterIdBack']
    && $repDoc['requesterId'] === 'Authorized Representative National ID' && $repDoc['requesterIdBack'] === 'Authorized Representative National ID (back side)', $repDoc);
$rep = DocumentRequirements::requirementsFor('landtransfer', false, true);
$check('2. a representative (Land Transfer): both National IDs front and back, the letter and the five documents, with the same ID labels', array_keys($rep) === ['ctcTdOrTitle', 'notarialDeed', 'vicinityMap',
    'certNoImprovement', 'taxClearance', 'ownerId', 'requesterId', 'authLetter', 'ownerIdBack', 'requesterIdBack'] && $rep['ownerId'] === 'Property Owner National ID', $rep);
$check('2. the automatic check\'s list is unchanged: fronts only', array_keys(DocumentRequirements::requirementsFor('docreq', false)) === ['ownerId', 'requesterId', 'authLetter']
    && array_column((new DocumentRequirements())->evaluate('docreq', true, ['ownerId' => ['mime' => 'image/png', 'size' => 10]])['items'], 'key') === ['ownerId']);
$check('2. a back side is an ID image: JPG or PNG only, the same 5 MB rule', DocumentRequirements::allowedMimeTypes('ownerIdBack') === ['image/jpeg', 'image/png']
    && DocumentRequirements::allowedMimeTypes('requesterIdBack') === ['image/jpeg', 'image/png'] && in_array('ownerIdBack', DocumentRequirements::IDENTITY_KEYS, true)
    && in_array('requesterIdBack', DocumentRequirements::allKeys(), true));
$check('2. drafts may hold both back sides', str_contains($drafts, "'ownerIdBack', 'requesterIdBack',"));
$check('2. a stored label tells which rule applies: the National ID labels (front or back) do, the earlier labels never',
    DocumentRequirements::isNationalIdLabel('Property Owner National ID') && DocumentRequirements::isNationalIdLabel('Authorized Representative National ID (back side)')
    && !DocumentRequirements::isNationalIdLabel('Valid Government-Issued ID') && !DocumentRequirements::isNationalIdLabel('Valid ID of Requester')
    && !DocumentRequirements::isNationalIdLabel('Valid ID of Property Owner (back side)') && !DocumentRequirements::isNationalIdLabel(''));
$check('2. an older request\'s stored type is shown to Staff only when it is one of the registry\'s types', DocumentRequirements::idTypeHintLabel('umid') === 'UMID'
    && DocumentRequirements::idTypeHintLabel('school_or_company_id') === null && DocumentRequirements::idTypeHintLabel('<b>x</b>') === null
    && DocumentRequirements::idTypeHintLabel(null) === null && DocumentRequirements::idTypeHintLabel(['umid']) === null);

// ================= 3. Submission (source): both sides of every National ID, no ID type =================
$check('3. the server requires every National ID\'s front and back before anything is saved', str_contains($api, "DocumentRequirements::requirementsFor(\$flow, (bool) \$values['is_owner'], true);")
    && str_contains($api, '$missingSides[] = $documents[$side];') && str_contains($api, "'Please upload clear photos of both the front and the back of your National ID: '"));
$check('3. no ID type is read or stored for a new submission (a type sent by an older page is ignored)', !str_contains($api, 'sa_id_type_hints') && !str_contains($api, "\$data['owner_id_type']")
    && str_contains($api, "'INSERT INTO request_documents (request_id, doc_key, label, original_name, stored_path, mime_type, file_size, file_status)\n"));
$check('3. a file that is not required is still refused', str_contains($api, "foreach (array_keys(\$files) as \$key) {\n        if (!isset(\$documents[\$key])) {"));
$check('3. the missing-document messages tell the applicant to reload the page if the upload place is not shown', substr_count($api, 'please reload this page and try again.') === 2);
$check('3. the submit script\'s address carries a version (a hash of its content), so an old cached copy is not used', str_contains((string) file_get_contents($app . '/web/prototype.php'),
    "<script src=\"/assets/php-bridge.js?v=' . \$bridgeVersion . '\"></script>'")
    && str_contains((string) file_get_contents($app . '/web/prototype.php'), "\$bridgeVersion = is_file(\$bridgeFile) ? substr((string) hash_file('sha256', \$bridgeFile), 0, 12) : '0';"));
$check('3. every file is stored under its own key (front and back never share a row or a file name)', str_contains($api, 'foreach ($documents as $key => $label) {')
    && str_contains($api, "\$storedName = \$itemId . '_' . bin2hex(random_bytes(16)) . '.' . \$extension;"));
$check('3. Staff receive, per file, whether it was stored under the National-ID-only rule', str_contains((string) file_get_contents($app . '/web/internal.php'),
    "'nationalIdOnly' => DocumentRequirements::isNationalIdLabel((string) (\$document['label'] ?? '')),"));
$migration = (string) file_get_contents($app . '/database/migrations/20261121_request_document_id_type_hint.sql');
$sql = trim((string) preg_replace('/^--.*$/m', '', $migration));
$check('3. the earlier migration (the column that keeps older requests\' types) is unchanged: one nullable column, only when missing', str_contains($sql, "'ALTER TABLE request_documents ADD COLUMN id_type_hint VARCHAR(40) NULL DEFAULT NULL AFTER file_status'")
    && str_contains($sql, "SET @sql := IF(@has_id_type_hint = 0,") && substr_count($sql, 'ALTER TABLE') === 1 && !preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE)\b/i', $sql), $sql);

// ================= 4. The automatic check never receives a back side or the selected type =================
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE requests (id INTEGER PRIMARY KEY, flow TEXT, is_owner INTEGER, first_name TEXT, middle_name TEXT, last_name TEXT, address_line TEXT, city TEXT, province TEXT, zip_code TEXT, arp_number TEXT, document_type TEXT, transfer_type TEXT)');
$pdo->exec('CREATE TABLE request_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INTEGER, doc_key TEXT, label TEXT, stored_path TEXT, mime_type TEXT, file_size INTEGER, file_status TEXT, id_type_hint TEXT)');
$png = static function (string $text): string {
    $im = imagecreatetruecolor(320, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, 240, 244, 248));
    imagestring($im, 5, 20, 90, $text, imagecolorallocate($im, 20, 30, 40));
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
};
$insert = $pdo->prepare('INSERT INTO request_documents (request_id, doc_key, label, stored_path, mime_type, file_size, file_status, id_type_hint) VALUES (?,?,?,?,?,?,?,?)');
foreach ([[1, 'docreq', 1], [2, 'docreq', 0]] as [$id, $flow, $isOwner]) {
    $pdo->prepare('INSERT INTO requests VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id, $flow, $isOwner, 'ROSA', '', 'FICTIONAL', '', '', '', '', '', 'Certification of Assessment', '']);
    $keys = $isOwner ? ['ownerId' => 'philhealth_id', 'ownerIdBack' => null] : ['requesterId' => 'umid', 'requesterIdBack' => null, 'ownerId' => 'passport', 'authLetter' => null];
    foreach ($keys as $key => $hint) {
        $name = "{$id}_" . bin2hex(random_bytes(8)) . '.png';
        file_put_contents("{$work}/private_uploads/requests/{$name}", $png('SAMPLE ' . strtoupper($key)));
        $insert->execute([$id, $key, $key, "private_uploads/requests/{$name}", 'image/png', filesize("{$work}/private_uploads/requests/{$name}"), 'ok', $hint]);
    }
}
$owner = AiVerification::jobManifest($pdo, ['request_id' => 1], $work);
$repManifest = AiVerification::jobManifest($pdo, ['request_id' => 2], $work);
$check('4. the automatic check receives the front only (owner): the back side is never sent', array_column($owner['documents'], 'key') === ['ownerId']
    && array_column($owner['requirements']['items'], 'key') === ['ownerId'] && !isset($owner['labels']['ownerIdBack']), array_column($owner['documents'], 'key'));
$check('4. representative: the two fronts and the letter, as before; no back side', array_column($repManifest['documents'], 'key') === ['requesterId', 'ownerId', 'authLetter']
    && $repManifest['application_findings'] === [], array_column($repManifest['documents'], 'key'));
$check('4. the selected ID type is not in what the check receives', !str_contains((string) json_encode([$owner['declared'], $owner['documents'], $repManifest['declared']]), 'philhealth_id')
    && array_keys($owner['declared']) === ['flow', 'is_owner', 'first_name', 'middle_name', 'last_name', 'address_line', 'city', 'province', 'zip_code', 'arp_number', 'document_type', 'transfer_type']);

// ================= 5. Staff page: back side next to its front; an older request's type marked "not verified" =================
// (These requests carry the earlier labels and a stored applicant-selected type: requests from before the
// National-ID-only rule. They are never held to that rule.)
$between = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    if ($from === false) {
        return '';
    }
    $to = strpos($source, $end, $from + strlen($start));
    return $to === false ? '' : substr($source, $from, $to - $from);
};
$fn = static fn (string $name): string => $between($internal, 'function ' . $name . '(', "\n}\n") . "\n}\n";
$node = trim((string) shell_exec('command -v node'));
$labels = ['ownerId' => 'Valid Government-Issued ID'];
$worker = DocumentAnalysis::validateWorkerResult(['version' => 4, 'documents' => [['key' => 'ownerId', 'ocr_status' => 'ok', 'ocr_error' => null, 'readability' => 'good',
    'pages' => 1, 'method' => 'image_ocr', 'text_characters' => 200, 'name_match' => 'exact', 'kind_evidence' => ['philhealth_id']]], 'rule_findings' => [],
    'model' => ['status' => 'ok', 'name' => 'demo', 'digest' => null, 'output' => ['documents' => [['key' => 'ownerId', 'document_kind' => 'philhealth_id', 'holder_name' => 'FICTIONAL, ROSA',
        'holder_name_clear' => true, 'property_number' => '', 'expected_type_match' => 'yes', 'missing_fields' => []]], 'findings' => [], 'manual_review_recommended' => false]],
    'ocr_engine' => 'tesseract (eng)'], ['ownerId']);
$classified = DocumentAnalysis::classify(['items' => [['key' => 'ownerId', 'label' => $labels['ownerId'], 'status' => 'uploaded_valid_format']]], $worker, $labels);
$files = [['docId' => 11, 'key' => 'ownerId', 'label' => 'Valid Government-Issued ID', 'name' => 'front.png', 'mime' => 'image/png', 'size' => 100, 'status' => 'ok', 'available' => true,
        'validation' => 'Pending', 'idTypeHint' => 'PhilHealth ID'],
    ['docId' => 12, 'key' => 'ownerIdBack', 'label' => 'Valid Government-Issued ID (back side)', 'name' => 'back.png', 'mime' => 'image/png', 'size' => 100, 'status' => 'ok', 'available' => true,
        'validation' => 'Pending', 'idTypeHint' => null]];
$ai = ['status' => 'completed', 'outcome' => $classified['outcome'], 'reason' => $classified['reason'], 'findings' => [], 'documents' => $classified['documents'], 'reviewed' => false];
if ($node === '') {
    fwrite(STDERR, "Node was not found: the Staff page checks were not run.\n");
    exit(2);
}
$script = 'function esc(s){ return String(s==null?"":s).replace(/[&<>"\']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];}); }'
    . 'function ic(){ return ""; } function fmtDateTime(s){ return String(s); }'
    . $between($internal, 'var AI_CHECK_STATES = {', 'function aiCheckState(r, kind){')
    . $fn('aiCheckState') . $fn('aiDurationText') . $fn('renderDocCheckDetail') . $fn('docCheckDisclaimer')
    . $fn('dcNameHtml') . $between($internal, 'var DC_DIGIT_LETTERS', 'function dcNameParts(') . $fn('dcNameParts') . $fn('dcEditDistance') . $fn('dcNameCompare')
    . $fn('dcImportantIssues')
    . $between($internal, 'var DC_VERDICT = {', 'function dcContext(r){') . $fn('dcContext') . $fn('dcUnread') . $fn('dcCheck') . $fn('dcKindCheck') . $fn('dcKindCheckTitles') . $fn('dcNameCheck')
    . $between($internal, 'var DC_LAND_DOCUMENTS', 'function dcDocChecks(') . $fn('dcDocChecks') . $fn('dcAllPassed') . $fn('dcRequestChecks') . $fn('dcChecksHtml') . $fn('drDocCard')
    . 'var files = ' . json_encode($files) . '; var ai = ' . json_encode($ai) . ';'
    . 'var details = {firstName:"Rosa", middleName:"", lastName:"Fictional", isOwner:true, arpNumber:""};'
    . 'var out = {};'
    . 'var r = {id:"DR-DEMO-1", applicant:"Rosa Fictional", ai:ai, aiEligibility:{eligible:true}, details:details, files:files};'
    . 'out.done = renderDocCheckDetail({r:r, kind:"doc", type:"Certification of Assessment", state:aiCheckState(r, "doc")});'
    . 'var r2 = Object.assign({}, r, {ai:null}); out.before = renderDocCheckDetail({r:r2, kind:"doc", type:"Certification of Assessment", state:aiCheckState(r2, "doc")});'
    . 'process.stdout.write(JSON.stringify(out));';
$jsFile = $work . '/staff.js';
file_put_contents($jsFile, $script);
$staff = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($jsFile) . ' 2>&1'), true);
$staff = is_array($staff) ? $staff : [];
$done = (string) ($staff['done'] ?? '');
$titles = preg_match_all('#<article class="dr-doc[^"]*">.*?<h3>(.*?)</h3>#s', $done, $mm) ? $mm[1] : [];
$check('5. after the automatic check, the back side is still shown, right after its front', $titles === ['Valid Government-Issued ID', 'Valid Government-Issued ID (back side)'], $titles);
$check('5. the back side says it is not part of the automatic check', str_contains($done, 'Back side of the ID: not part of the automatic check. Review the original.'));
$check('5. both sides open through request-file.php (doc 11 and doc 12), nothing else', str_contains($done, 'request-file.php?doc=11&amp;ref=DR-DEMO-1') && str_contains($done, 'request-file.php?doc=12&amp;ref=DR-DEMO-1'));
$check('5. the selected type is shown once, as the applicant\'s choice, not verified', substr_count($done, 'ID type selected by the applicant: <strong>PhilHealth ID</strong> (not verified; compare it with the original)') === 1);
$check('5. before any check, both sides are listed for review', substr_count((string) ($staff['before'] ?? ''), '<article class="dr-doc') === 2, $staff['before'] ?? '');

// 5b. Selected type versus the type the automatic check confirmed (worker version 5 id_check)
$v5 = static function (string $type, string $state) use ($labels): array {
    $worker = DocumentAnalysis::validateWorkerResult(['version' => 5, 'documents' => [['key' => 'ownerId', 'ocr_status' => 'ok', 'ocr_error' => null, 'readability' => 'good',
        'pages' => 1, 'method' => 'image_ocr', 'text_characters' => 200, 'name_match' => 'exact', 'kind_evidence' => [$type],
        'id_check' => ['type_state' => $state, 'type' => $type, 'ocr_state' => 'readable', 'name_state' => 'read', 'fields' => [], 'missing_required' => [], 'expiry' => 'not_applicable',
            'needs_staff_review' => false]]], 'rule_findings' => [],
        'model' => ['status' => 'ok', 'name' => 'demo', 'digest' => null, 'output' => ['documents' => [['key' => 'ownerId', 'document_kind' => $type, 'holder_name' => 'FICTIONAL, ROSA',
            'holder_name_clear' => true, 'property_number' => '', 'expected_type_match' => 'yes', 'missing_fields' => []]], 'findings' => [], 'manual_review_recommended' => false]],
        'ocr_engine' => 'tesseract (eng)'], ['ownerId']);
    $classified = DocumentAnalysis::classify(['items' => [['key' => 'ownerId', 'label' => $labels['ownerId'], 'status' => 'uploaded_valid_format']]], $worker, $labels);
    return ['status' => 'completed', 'outcome' => $classified['outcome'], 'reason' => $classified['reason'], 'findings' => [], 'documents' => $classified['documents'], 'reviewed' => false];
};
$front = static fn (?string $code): array => array_replace($files[0], ['idTypeHint' => DocumentRequirements::idTypeHintLabel($code),
    'idTypeHintCode' => DocumentRequirements::idTypeHintLabel($code) === null ? null : $code]);
$cases = [
    'match' => [$v5('philhealth_id', 'confirmed'), $front('philhealth_id')],
    'mismatchUmid' => [$v5('umid', 'confirmed'), $front('passport')],
    'mismatchPhilhealth' => [$v5('philhealth_id', 'confirmed'), $front('drivers_license')],
    'unknownStored' => [$v5('umid', 'confirmed'), $front('bogus_type')],
    'notConfirmed' => [$v5('umid', 'title_only'), $front('passport')],
    'noAutomaticType' => [$ai, $front('passport')],
];
$script2 = substr($script, 0, (int) strpos($script, 'var files = '))
    . 'var cases = ' . json_encode(array_map(static fn (array $c): array => ['ai' => $c[0], 'files' => [$c[1], $files[1]]], $cases)) . '; var out = {};'
    . 'Object.keys(cases).forEach(function(k){ var r = {id:"DR-DEMO-1", applicant:"Rosa Fictional", ai:cases[k].ai, aiEligibility:{eligible:true},'
    . ' details:{firstName:"Rosa", middleName:"", lastName:"Fictional", isOwner:true, arpNumber:""}, files:cases[k].files};'
    . ' out[k] = renderDocCheckDetail({r:r, kind:"doc", type:"Certification of Assessment", state:aiCheckState(r, "doc")}); });'
    . 'process.stdout.write(JSON.stringify(out));';
file_put_contents($jsFile, $script2);
$warn = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($jsFile) . ' 2>&1'), true);
$warn = is_array($warn) ? $warn : [];
$warning = static fn (string $html): int => substr_count($html, 'class="dr-id-hint dr-id-mismatch"');
$check('5b. a mismatch (Passport selected, UMID identified) is a clear warning for Staff', str_contains((string) ($warn['mismatchUmid'] ?? ''),
    '<strong>Applicant selected Passport, but the document was identified as UMID. Please verify the original ID.</strong>') && $warning((string) ($warn['mismatchUmid'] ?? '')) === 1, $warn['mismatchUmid'] ?? '');
$check('5b. another mismatch names both types (Driver\'s License selected, PhilHealth ID identified)', str_contains((string) ($warn['mismatchPhilhealth'] ?? ''),
    'Applicant selected Philippine Driver&#39;s License, but the document was identified as PhilHealth ID. Please verify the original ID.'));
$check('5b. a matching type gives no warning, only a note that it matches (still verify the original)', $warning((string) ($warn['match'] ?? 'x')) === 0
    && str_contains((string) ($warn['match'] ?? ''), 'ID type selected by the applicant: <strong>PhilHealth ID</strong>. It matches the type identified on the ID; still verify the original.'));
$check('5b. an unknown stored type is never shown (no line, no warning)', $warning((string) ($warn['unknownStored'] ?? 'x')) === 0
    && !str_contains((string) ($warn['unknownStored'] ?? 'x'), 'ID type selected by the applicant') && !str_contains((string) ($warn['unknownStored'] ?? 'x'), 'bogus'));
$check('5b. without a confirmed type (title only, or an older result) there is nothing to compare: the plain "not verified" line, no warning',
    $warning((string) ($warn['notConfirmed'] ?? 'x')) === 0 && $warning((string) ($warn['noAutomaticType'] ?? 'x')) === 0
    && str_contains((string) ($warn['notConfirmed'] ?? ''), 'ID type selected by the applicant: <strong>Passport</strong> (not verified; compare it with the original)')
    && str_contains((string) ($warn['noAutomaticType'] ?? ''), 'ID type selected by the applicant: <strong>Passport</strong> (not verified; compare it with the original)'));
$check('5b. the warning never approves, rejects or changes anything (no action control, the outcome is the check\'s own)', !preg_match('/data-action="(approve|reject)/', (string) ($warn['mismatchUmid'] ?? ''))
    && ($cases['mismatchUmid'][0]['outcome'] ?? '') === ($v5('umid', 'confirmed')['outcome'] ?? 'x'));

// 5c. National ID only: an ID the automatic check identifies as another government ID is a mismatch for Staff
$natFiles = [array_replace($files[0], ['label' => 'Property Owner National ID', 'idTypeHint' => null, 'idTypeHintCode' => null, 'nationalIdOnly' => true]),
    array_replace($files[1], ['label' => 'Property Owner National ID (back side)', 'nationalIdOnly' => true])];
$oldFiles = [array_replace($files[0], ['idTypeHint' => null, 'idTypeHintCode' => null, 'nationalIdOnly' => false]), array_replace($files[1], ['nationalIdOnly' => false])];
$natCases = [
    'natUmid' => [$v5('umid', 'confirmed'), $natFiles],
    'natLicense' => [$v5('drivers_license', 'confirmed'), $natFiles],
    'natPhilsys' => [$v5('philsys_national_id', 'confirmed'), $natFiles],
    'natTitleOnly' => [$v5('philsys_national_id', 'title_only'), $natFiles],
    'oldUmid' => [$v5('umid', 'confirmed'), $oldFiles],
];
$script3 = substr($script, 0, (int) strpos($script, 'var files = '))
    . 'var cases = ' . json_encode(array_map(static fn (array $c): array => ['ai' => $c[0], 'files' => $c[1]], $natCases)) . '; var out = {};'
    . 'Object.keys(cases).forEach(function(k){ var r = {id:"DR-DEMO-1", applicant:"Rosa Fictional", ai:cases[k].ai, aiEligibility:{eligible:true},'
    . ' details:{firstName:"Rosa", middleName:"", lastName:"Fictional", isOwner:true, arpNumber:""}, files:cases[k].files};'
    . ' var ctx = dcContext(r); var d = (cases[k].ai.documents || []).filter(function(x){ return x.key==="ownerId"; })[0]; var c = dcKindCheck(d, true, ctx);'
    . ' out[k] = {verdict:c.verdict, detected:c.detected, reason:c.reason, html:renderDocCheckDetail({r:r, kind:"doc", type:"Certification of Assessment", state:aiCheckState(r, "doc")})}; });'
    . 'process.stdout.write(JSON.stringify(out));';
file_put_contents($jsFile, $script3);
$nat = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($jsFile) . ' 2>&1'), true);
$nat = is_array($nat) ? $nat : [];
$check('5c. National ID only: an ID identified as a UMID is a Mismatch, naming it and the rule, for Staff to check', ($nat['natUmid']['verdict'] ?? '') === 'mismatch'
    && ($nat['natUmid']['reason'] ?? '') === 'This looks like UMID, not a National ID. Only the National ID (PhilSys card or ePhilID) is accepted. Check the original.', $nat['natUmid'] ?? $nat);
$check('5c. the same for a Driver\'s License', ($nat['natLicense']['verdict'] ?? '') === 'mismatch' && str_contains((string) ($nat['natLicense']['reason'] ?? ''), 'not a National ID'), $nat['natLicense'] ?? null);
$check('5c. a confirmed National ID is Correct (no warning)', ($nat['natPhilsys']['verdict'] ?? '') === 'correct' && !str_contains((string) ($nat['natPhilsys']['html'] ?? 'not a National ID'), 'not a National ID'), $nat['natPhilsys'] ?? null);
$check('5c. a National ID read by its title only stays "Unable to verify" (as before), not a mismatch', ($nat['natTitleOnly']['verdict'] ?? '') === 'unable', $nat['natTitleOnly'] ?? null);
$check('5c. an older request (any government ID was accepted) never gets the National ID warning', ($nat['oldUmid']['verdict'] ?? '') === 'correct'
    && !str_contains((string) ($nat['oldUmid']['html'] ?? 'not a National ID'), 'not a National ID'), $nat['oldUmid'] ?? null);
$check('5c. the mismatch only informs: no approve or reject control, the check\'s own outcome', !preg_match('/data-action="(approve|reject)/', (string) ($nat['natUmid']['html'] ?? ''))
    && str_contains((string) ($nat['natUmid']['html'] ?? ''), 'not a National ID'));

// ================= 6. Client page in headless Chrome =================
$chrome = getenv('CHROME_BIN') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
if (!is_file($chrome)) {
    fwrite(STDERR, "Google Chrome was not found (set CHROME_BIN): the page checks were not run.\n");
    exit(2);
}
// upload() returns a promise that settles when the page's own FileReader has finished (its loadend event),
// so the checks wait for the real read, never for a timer. Added to the temporary copy only.
$hook = 'window.__SA = {get state(){ return state; }, render:function(){ render(); }, nextStep:function(){ nextStep(); }, submitRequest:function(){ submitRequest(); },'
    . ' upload:function(k,f){ window.__saRead=null; processUploadedFile(k,f); return window.__saRead || Promise.resolve(); }, setDocItems:setDocItems,'
    . ' applyDraft:function(f,st,mx,fields,files){ draftApplyFields(f,st,mx,fields,files); }};' . "\n" . 'window.SMART_ASSESS_FORM = function(){';
$page0 = str_replace('window.SMART_ASSESS_FORM = function(){', $hook, $html);
$page0 = str_replace("  reader.readAsDataURL(file);", "  window.__saRead = new Promise(function(done){ reader.addEventListener('loadend', function(){ setTimeout(done, 0); }); });\n  reader.readAsDataURL(file);", $page0);
$check('6. the test hook could be added to the temporary copy', $page0 !== $html && str_contains($page0, 'window.__saRead = new Promise'));
// Fictional "ID side" images drawn here with GD (no real document), handed to the page as File objects.
$sideImage = static function (string $label, array $rgb): string {
    $im = imagecreatetruecolor(640, 404);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    imagefilledrectangle($im, 24, 24, 615, 379, imagecolorallocate($im, 255, 255, 255));
    $ink = imagecolorallocate($im, 31, 59, 87);
    imagestring($im, 5, 48, 70, 'SAMPLE ID', $ink);
    imagestring($im, 5, 48, 110, $label, $ink);
    imagestring($im, 4, 48, 330, 'FICTIONAL - NOT A REAL ID', $ink);
    ob_start();
    imagepng($im);
    return base64_encode((string) ob_get_clean());
};
$images = ['FRONT SIDE' => $sideImage('FRONT SIDE', [47, 111, 79]), 'BACK SIDE' => $sideImage('BACK SIDE', [59, 91, 138]), 'NEW BACK' => $sideImage('NEW BACK', [138, 91, 59])];
$fake = 'var FAKE=' . json_encode($images) . ';'
    . 'function fakeId(label){ var b=atob(FAKE[label]), a=new Uint8Array(b.length); for(var i=0;i<b.length;i++){ a[i]=b.charCodeAt(i); }'
    . ' var f=new File([a], label.toLowerCase().replace(/ /g,"-")+".png", {type:"image/png"}); f.__b64=FAKE[label]; return Promise.resolve(f); }'
    // Headless Chrome's virtual time can end while a real file read is pending, so for these fictional
    // files only, FileReader returns the data URL of the bytes already known here (the same result the
    // browser would give). The page's own upload code (processUploadedFile) runs unchanged.
    . '(function(){ var Real=window.FileReader; window.FileReader=function(){ var self=this, done=[];'
    . ' this.addEventListener=function(type, cb){ if(type==="loadend") done.push(cb); };'
    . ' this.readAsDataURL=function(file){ if(file && file.__b64){ self.result="data:"+file.type+";base64,"+file.__b64;'
    . '  setTimeout(function(){ if(self.onload) self.onload({target:self}); done.forEach(function(cb){ cb(); }); }, 0); return; }'
    . '  var r=new Real(); r.onload=function(e){ self.result=r.result; if(self.onload) self.onload(e); }; r.onloadend=function(){ done.forEach(function(cb){ cb(); }); }; r.readAsDataURL(file); }; }; })();'
;
// The page runs inside an iframe of the exact width (headless Chrome's own window is at least 500 px
// wide), as tests/multi_document_ui_test.php does; the outer page copies the result out of the frame.
$run = static function (string $name, string $js, int $width = 1366, ?string $shot = null) use ($page0, $work, $chrome, $fake): array {
    $page = $page0 . "\n<style>*{animation:none!important;transition:none!important}</style>"
        . '<script>' . $fake . 'setTimeout(function(){var out={};var S=__SA, state=S.state, fd=state.formData;'
        . 'var done=function(){var p=document.createElement("pre");p.id="__out";p.style.display="none";p.textContent=JSON.stringify(out);document.body.appendChild(p);};'
        . 'Promise.resolve().then(function(){' . $js . '}).catch(function(e){out.error=String(e)}).then(function(){setTimeout(done,150);});},300);</script>';
    file_put_contents("{$work}/{$name}.html", $page);
    file_put_contents("{$work}/{$name}-frame.html", '<html><body style="margin:0;background:#888"><iframe id="f" src="' . $name . '.html" style="border:0;display:block;width:' . $width . 'px;height:1800px"></iframe>'
        . '<script>(function poll(n){ var d=document.getElementById("f").contentDocument, p=d && d.getElementById("__out");'
        . ' if(p || n>400){ var o=document.createElement("pre"); o.id="__res"; o.textContent=p ? p.textContent : "{}"; document.body.appendChild(o); } else { setTimeout(function(){ poll(n+1); }, 50); } })(0);</script></body></html>');
    $size = max($width, 500) . ',1800';
    $flags = ' --headless=new --disable-gpu --hide-scrollbars --allow-file-access-from-files --virtual-time-budget=30000 --window-size=' . $size;
    if ($shot !== null) {
        shell_exec(escapeshellarg($chrome) . $flags . ' --screenshot=' . escapeshellarg($shot) . ' ' . escapeshellarg("file://{$work}/{$name}-frame.html") . ' 2>/dev/null');
    }
    $dom = (string) shell_exec(escapeshellarg($chrome) . $flags . ' --dump-dom ' . escapeshellarg("file://{$work}/{$name}-frame.html") . ' 2>/dev/null');
    return preg_match('#<pre id="__res">(.*?)</pre>#s', $dom, $mm) ? (array) json_decode(html_entity_decode($mm[1]), true) : ['error' => 'no output'];
};
$base = 'state.view="wizard"; state.flow="docreq"; state.maxStep=4; fd.firstName="Rosa"; fd.lastName="Fictional"; fd.contact="+639170000001"; fd.addressLine="1 Demo St.";'
    . ' fd.requesterBarangay="POBLACION"; fd.barangay="POBLACION"; fd.arp="14-0001-00045"; S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"}]); state.step=3;';
$layout = 'var blocks=[].slice.call(document.querySelectorAll(".id-card-block")); out.blocks=blocks.length;'
    . ' out.sides=blocks.map(function(b){ var s=b.querySelectorAll(".id-side"); var a=s[0].getBoundingClientRect(), c=s[1].getBoundingClientRect();'
    . ' return {sideBySide: Math.abs(a.top-c.top)<2 && c.left>a.right-1, stacked: c.top>=a.bottom-1, equal: Math.abs(a.width-c.width)<2,'
    . ' labels:[s[0].querySelector(".id-side-label").textContent, s[1].querySelector(".id-side-label").textContent]}; });'
    . ' out.scrollX = document.documentElement.scrollWidth > window.innerWidth + 1; out.viewport = window.innerWidth;';
$shotDir = getenv('SA_SCREENSHOT_DIR') ?: null;

// 6.1 No dropdown; the accepted ID is named; layout (owner, desktop)
$g = $run('desktop', $base . 'S.render();' . $layout
    . ' out.selects=document.querySelectorAll(".id-card-block select").length; out.title=(document.querySelector(".id-card-title")||{}).textContent;'
    . ' out.note=(document.querySelector(".id-sides-note")||{}).textContent; out.head=(document.querySelector(".wizard-card-head h2")||{}).textContent;'
    . ' out.lead=(document.querySelector(".wizard-card-head p")||{}).textContent;', 1366);
$check('6.1 owner: one National ID block, no ID type dropdown, and the accepted ID named', ($g['blocks'] ?? 0) === 1 && ($g['selects'] ?? 1) === 0
    && ($g['title'] ?? '') === 'Property Owner National ID' && ($g['note'] ?? '') === 'Accepted ID: National ID (PhilSys card or ePhilID). Upload clear photos of its front and back.', $g);
$check('6.1 Step 3 says only the National ID is accepted, front and back', ($g['head'] ?? '') === 'National ID Verification'
    && ($g['lead'] ?? '') === 'Only the National ID is accepted (PhilSys card or ePhilID), front and back.', $g);
$check('6.1 desktop: FRONT and BACK side by side, equal widths', ($g['sides'][0]['sideBySide'] ?? false) === true && ($g['sides'][0]['equal'] ?? false) === true
    && ($g['sides'][0]['labels'] ?? null) === ['FRONT SIDE OF ID*', 'BACK SIDE OF ID*'] && ($g['scrollX'] ?? true) === false, $g);

// 6.2 Representative at tablet and phone widths
$repBase = $base . 'fd.isOwner=false; S.render();';
$g = $run('tablet', $repBase . $layout, 820);
$check('6.2 tablet (820 px), representative: two ID blocks (representative\'s and owner\'s), front and back side by side', ($g['viewport'] ?? 0) === 820 && ($g['blocks'] ?? 0) === 2
    && ($g['sides'][0]['sideBySide'] ?? false) && ($g['sides'][1]['sideBySide'] ?? false) && ($g['scrollX'] ?? true) === false, $g);
$g = $run('phone', $repBase . $layout, 390);
$check('6.2 phone (390 px): front and back stack vertically, no sideways scrolling', ($g['viewport'] ?? 0) === 390 && ($g['blocks'] ?? 0) === 2 && ($g['sides'][0]['stacked'] ?? false) && ($g['sides'][1]['stacked'] ?? false)
    && !($g['sides'][0]['sideBySide'] ?? true) && ($g['scrollX'] ?? true) === false, $g);

// 6.3 Independent uploads: choose, replace and remove one side without touching the other
$files = 'var name=function(k){ return (S.state.formData.files[k]||{}).name||null; };';
$g = $run('uploads', $base . 'S.render();' . $files
    . ' return Promise.all([fakeId("FRONT SIDE"), fakeId("BACK SIDE"), fakeId("NEW BACK")]).then(function(f){'
    . '  return Promise.all([S.upload("ownerId", f[0]), S.upload("ownerIdBack", f[1])]).then(function(){'
    . '   out.both=[name("ownerId"), name("ownerIdBack")]; out.previews=[].slice.call(document.querySelectorAll(".id-side-preview img")).map(function(i){ return i.alt; });'
    . '   out.frontPreview=/^data:image\/png/.test((S.state.formData.files.ownerId||{}).preview||""); out.backPreview=/^data:image\/png/.test((S.state.formData.files.ownerIdBack||{}).preview||"");'
    . '   out.distinct=S.state.formData.files.ownerId.preview!==S.state.formData.files.ownerIdBack.preview;'
    . '   return S.upload("ownerIdBack", f[2]); }).then(function(){ out.replaced=[name("ownerId"), name("ownerIdBack")];'
    . '   document.querySelector("[data-remove-file=ownerIdBack]").click(); out.removed=[name("ownerId"), name("ownerIdBack")];'
    . '   out.backButton=!!document.querySelector("[data-choose-file=ownerIdBack]"); out.frontButtons=!!document.querySelector("[data-replace-file=ownerId]") && !!document.querySelector("[data-remove-file=ownerId]");'
    . '   out.inputs=[].slice.call(document.querySelectorAll(".id-side input[type=file]")).map(function(i){ return [i.getAttribute("data-file-input"), i.getAttribute("accept")]; }); }); });', 1366);
$check('6.3 front and back upload independently, each with its own preview', ($g['both'] ?? null) === ['front-side.png', 'back-side.png'] && ($g['frontPreview'] ?? false) && ($g['backPreview'] ?? false)
    && ($g['distinct'] ?? false) && ($g['previews'] ?? null) === ['Preview of the front side of id', 'Preview of the back side of id'], $g);
$check('6.3 replacing the back keeps the front', ($g['replaced'] ?? null) === ['front-side.png', 'new-back.png'], $g);
$check('6.3 removing the back keeps the front, which still offers Replace and Remove', ($g['removed'] ?? null) === ['front-side.png', null] && ($g['backButton'] ?? false) && ($g['frontButtons'] ?? false), $g);
$check('6.3 each side has its own JPG/PNG file input', ($g['inputs'] ?? null) === [['ownerId', '.jpg,.jpeg,.png,image/jpeg,image/png,image/jpg'], ['ownerIdBack', '.jpg,.jpeg,.png,image/jpeg,image/png,image/jpg']], $g);

// 6.4 Required photos (both sides); the review
$g = $run('required', $base . 'S.render(); S.nextStep(); out.errors=Object.keys(S.state.errors); out.step=S.state.step;'
    . ' out.backError=(document.getElementById("err-ownerIdBack")||{}).textContent;'
    . ' fd.files={ownerId:{name:"front.png",size:10,type:"image/png",valid:true}}; S.state.errors={}; S.render(); S.nextStep(); out.frontOnlyStep=S.state.step; out.frontOnlyErrors=Object.keys(S.state.errors);'
    . ' fd.files.ownerIdBack={name:"back.png",size:10,type:"image/png",valid:true}; S.state.errors={}; S.nextStep(); out.okStep=S.state.step;'
    . ' out.review=[].slice.call(document.querySelectorAll(".review-table tr")).map(function(r){ return r.textContent; }).filter(function(t){ return /National ID|ID Type/.test(t); });', 1366);
$check('6.4 without photos the step does not continue: front and back are each required (no ID type to choose)', ($g['step'] ?? 0) === 3
    && ($g['errors'] ?? null) === ['ownerId', 'ownerIdBack'] && ($g['backError'] ?? '') === 'Property Owner National ID (back side) upload is required.', $g);
$check('6.4 the front alone is not enough; with both sides it continues', ($g['frontOnlyStep'] ?? 0) === 3 && ($g['frontOnlyErrors'] ?? null) === ['ownerIdBack'] && ($g['okStep'] ?? 0) === 4, $g);
$check('6.4 the review shows both sides and no ID type row', ($g['review'] ?? null) === ['Property Owner National IDfront.png', 'Property Owner National ID (back side)back.png'], $g);

// 6.5 Representative: both National IDs need both sides
$g = $run('repRequired', $repBase . 'S.nextStep(); out.errors=Object.keys(S.state.errors).sort();', 1366);
$check('6.5 representative: each National ID needs its front and back; the letter is still required', ($g['errors'] ?? null) === ['authLetter', 'ownerId', 'ownerIdBack',
    'requesterId', 'requesterIdBack'], $g);

// 6.6 Land Transfer uses the same ID blocks
$g = $run('land', 'state.view="wizard"; state.flow="landtransfer"; state.maxStep=4; state.step=3; S.render(); out.blocks=document.querySelectorAll(".id-card-block").length;'
    . ' out.docs=document.querySelectorAll(".doc-upload-list [data-upload]").length; out.sides=[].slice.call(document.querySelectorAll(".id-side")).map(function(s){ return s.getAttribute("data-upload"); });', 1366);
$check('6.6 Land Transfer: the same ID block (front and back), the five documents unchanged', ($g['blocks'] ?? 0) === 1 && ($g['docs'] ?? 0) === 5 && ($g['sides'] ?? null) === ['ownerId', 'ownerIdBack'], $g);

// 6.6b Representative Land Transfer: the whole Step 3 flow (two ID blocks, the letter, the five documents)
$ltDocs = 'var docs=["ctcTdOrTitle","notarialDeed","vicinityMap","certNoImprovement","taxClearance"];';
$g = $run('landRep', 'state.view="wizard"; state.flow="landtransfer"; state.maxStep=4; fd.firstName="Rosa"; fd.lastName="Fictional"; fd.contact="+639170000001"; fd.addressLine="1 Demo St.";'
    . ' fd.requesterBarangay="POBLACION"; fd.barangay="POBLACION"; fd.arp="14-0001-00045"; fd.transferType="Sale"; fd.isOwner=false; state.step=3; S.render();' . $ltDocs
    . ' out.blocks=[].slice.call(document.querySelectorAll(".id-card-block .id-card-title")).map(function(e){ return e.textContent; });'
    . ' out.letter=!!document.querySelector(".id-upload-grid [data-upload=authLetter]"); out.docs=document.querySelectorAll(".doc-upload-list [data-upload]").length;'
    . ' S.nextStep(); out.errors=Object.keys(S.state.errors).sort();'
    . ' var ok={name:"x.png",size:10,type:"image/png",valid:true};'
    . ' ["requesterId","requesterIdBack","ownerId","ownerIdBack","authLetter"].concat(docs).forEach(function(k){ fd.files[k]=Object.assign({}, ok, {name:k+".png"}); });'
    . ' S.state.errors={}; S.nextStep(); out.step=S.state.step;'
    . ' out.review=[].slice.call(document.querySelectorAll(".review-table tr")).map(function(r){ return r.textContent; }).filter(function(t){ return /National ID|ID Type/.test(t); });', 1366);
$check('6.6b representative Land Transfer: the representative\'s and the owner\'s National ID blocks, the letter and the five documents', ($g['blocks'] ?? null) === ['Authorized Representative National ID', 'Property Owner National ID']
    && ($g['letter'] ?? false) === true && ($g['docs'] ?? 0) === 5, $g);
$check('6.6b nothing uploaded: every document and both sides of each National ID are required', ($g['errors'] ?? null) === ['authLetter', 'certNoImprovement', 'ctcTdOrTitle',
    'notarialDeed', 'ownerId', 'ownerIdBack', 'requesterId', 'requesterIdBack', 'taxClearance', 'vicinityMap'], $g);
$check('6.6b complete: it continues to the review, which lists both sides of both National IDs (no ID type rows)', ($g['step'] ?? 0) === 4 && ($g['review'] ?? null) === [
    'Authorized Representative National IDrequesterId.png', 'Authorized Representative National ID (back side)requesterIdBack.png',
    'Property Owner National IDownerId.png', 'Property Owner National ID (back side)ownerIdBack.png'], $g);

// 6.6c A saved draft restored in the browser: both photos come back; an ID type saved by an older page is ignored
$saved = '[{doc_key:"ownerId", name:"front.png", size:120, type:"image/png"}, {doc_key:"ownerIdBack", name:"back.png", size:130, type:"image/png"}]';
$g = $run('draftRestore', 'S.applyDraft("docreq", 3, 3, {firstName:"Rosa", lastName:"Fictional", contact:"+639170000001", addressLine:"1 Demo St.", requesterBarangay:"POBLACION",'
    . ' barangay:"POBLACION", arp:"14-0001-00045", documentType:"Certification of Assessment", purpose:"Personal Copy", ownerIdType:"<b>umid</b>"}, ' . $saved . '); S.render();'
    . ' out.selects=document.querySelectorAll(".id-card-block select").length; out.injected=document.querySelector(".id-card-block").innerHTML.indexOf("umid")>-1;'
    . ' out.sides=[].slice.call(document.querySelectorAll(".id-side .upl-file")).map(function(e){ return e.textContent; });'
    . ' out.saved=[].slice.call(document.querySelectorAll(".id-side .upl-ok")).map(function(e){ return e.textContent; });'
    . ' S.nextStep(); out.step=S.state.step; out.errors=Object.keys(S.state.errors);', 1366);
$check('6.6c a restored draft shows both saved photos and continues; an ID type saved by an older page is ignored (never shown)', ($g['selects'] ?? 1) === 0
    && ($g['injected'] ?? true) === false && ($g['sides'] ?? null) === ['front.png', 'back.png'] && ($g['saved'] ?? null) === ['Saved', 'Saved']
    && ($g['step'] ?? 0) === 4 && ($g['errors'] ?? null) === [], $g);

// 6.6d Contrast (WCAG AA, 4.5:1) of the ID panel's text, measured in the rendered page at desktop width.
// The colour behind each text is worked out from the page itself: each ancestor's background colour,
// translucent ones blended over the next, up to the first opaque one.
$contrast = 'function rgba(c){ var m=c.match(/rgba?\(([^)]+)\)/); if(m){ var p=m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return [p[0],p[1],p[2],p.length>3?p[3]:1]; }'
    . ' m=c.match(/color\(srgb ([^)]+)\)/); if(m){ var q=m[1].split(/[ \/]+/).filter(Boolean).map(Number); return [q[0]*255,q[1]*255,q[2]*255,q.length>3?q[3]:1]; } return [0,0,0,0]; }'
    . ' function behind(el){ var stack=[]; for(var e=el; e; e=e.parentElement){ var c=rgba(getComputedStyle(e).backgroundColor); if(c[3]>0){ stack.push(c); if(c[3]>=1) break; } }'
    . '  var out=[255,255,255]; for(var i=stack.length-1;i>=0;i--){ var a=stack[i][3]; out=[0,1,2].map(function(j){ return stack[i][j]*a+out[j]*(1-a); }); } return out; }'
    . ' function lum(c){ return [0,1,2].reduce(function(s,j,i){ var v=c[j]/255; v=v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4); return s+[0.2126,0.7152,0.0722][i]*v; },0); }'
    . ' function ratio(el){ var t=rgba(getComputedStyle(el).color), b=behind(el), x=lum(t), y=lum(b); return Math.round(((Math.max(x,y)+0.05)/(Math.min(x,y)+0.05))*100)/100; }'
    . ' function measure(){ var q=function(s){ return document.querySelector(s); };'
    . '  return {panelNote: ratio(q(".id-guidance-panel > .reference-note")), sidesNote: ratio(q(".id-sides-note")),'
    . '   title: ratio(q(".id-card-title")), sideLabel: ratio(q(".id-side-label")), meta: ratio(q(".id-side .upl-meta span"))}; }';
$themeRun = static fn (string $theme): string => 'document.documentElement.setAttribute("data-theme","' . $theme . '");' . $base . 'S.render();'
    . $contrast . ' out.r=measure();';
$dark = $run('contrastDark', $themeRun('dark'), 1366);
$light = $run('contrastLight', $themeRun('light'), 1366);
$aa = static fn (array $r): bool => $r !== [] && min($r) >= 4.5;
echo 'INFO  measured contrast, desktop: dark ' . json_encode($dark['r'] ?? null) . ' | light ' . json_encode($light['r'] ?? null) . "\n";
$check('6.6d dark theme, desktop: every ID panel text keeps at least 4.5:1 (the guidance note, the accepted-ID instruction, titles, labels)', $aa((array) ($dark['r'] ?? [])), $dark);
$check('6.6d light theme, desktop: the same texts keep at least 4.5:1', $aa((array) ($light['r'] ?? [])), $light);
$check('6.6d the dark panel follows the system setting too (same rule as the chosen dark theme)', str_contains($html,
    '@media (prefers-color-scheme: dark) and (min-width:1025px){ :root:not([data-theme="light"]) .id-guidance-panel{ background:var(--surface-2);')
    && str_contains($html, ':root[data-theme="dark"] .id-guidance-panel{ background:var(--surface-2);'));

// 6.7 Text styles: the new parts reuse the page's existing typography
$css = $between($html, '/* ---- National ID: front and back photos.', '@media (prefers-reduced-motion:reduce){ .id-side');
$check('6.7 the new styles change no font (no font-family, font-size or font shorthand)', $css !== '' && !preg_match('/font(-family|-size)?\s*:/', $css), $css === '' ? 'css block not found' : '');
[$outsideSupports] = explode('@supports', (string) preg_replace('#/\*.*?\*/#s', '', $css), 2) + [1 => ''];
$check('6.7 without color-mix() or backdrop-filter the cards are the opaque theme surface (translucency and blur only inside @supports)',
    str_contains($outsideSupports, 'background:var(--surface);') && str_contains($outsideSupports, 'background:var(--surface-2);')
    && !str_contains($outsideSupports, 'backdrop-filter') && !str_contains($outsideSupports, 'color-mix')
    && str_contains($css, '@supports (background:color-mix(in srgb, red 50%, transparent)) and ((-webkit-backdrop-filter:blur(1px)) or (backdrop-filter:blur(1px))){'));
$check('6.7 the card colours come from the theme (light and dark), never a fixed white', !preg_match('/rgba\(255,\s*255,\s*255,\s*\.[5-9]/', $css) && !str_contains($css, '#fff'));

// 6.8 Screenshots (optional, outside the repository)
if ($shotDir !== null && is_dir($shotDir)) {
    $shotJs = $base . 'S.render(); return Promise.all([fakeId("FRONT SIDE"), fakeId("BACK SIDE")]).then(function(f){ '
        . ' return Promise.all([S.upload("ownerId", f[0]), S.upload("ownerIdBack", f[1])]); }).then(function(){ var p=document.querySelector(".id-guidance-panel"); if(p) window.scrollTo(0, p.getBoundingClientRect().top + window.scrollY - 12); })';
    foreach (['desktop' => 1366, 'tablet' => 820, 'phone' => 390] as $label => $width) {
        $run('shot-' . $label, $shotJs, $width, rtrim($shotDir, '/') . "/id-upload-{$label}.png");
    }
    // The same at desktop width in the light theme (the page follows the visitor's system setting).
    $run('shot-desktop-light', 'document.documentElement.setAttribute("data-theme","light");' . $shotJs, 1366, rtrim($shotDir, '/') . '/id-upload-desktop-light.png');
}

foreach ($results as [$passed, $label, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? "\n      " . $detail : '') . "\n";
}
$failed = array_filter($results, static fn (array $r): bool => !$r[0]);
printf("%d / %d passed\n", count($results) - count($failed), count($results));
exit($failed === [] ? 0 : 1);
