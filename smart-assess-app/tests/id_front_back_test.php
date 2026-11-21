<?php
declare(strict_types=1);

/*
 * Client valid-ID upload: "Select Type of Valid ID" plus separate FRONT and BACK photos for the
 * Property Owner's ID (ownerId / ownerIdBack) and the Authorized Representative's ID (requesterId /
 * requesterIdBack). No database server and no network: the automatic-check manifest runs against an
 * in-memory SQLite copy of its two tables, the client page runs in headless Chrome (a test-only hook
 * is added to a temporary copy, never to the page), and the Staff page's own functions run in Node.
 * Every person, ID and image is fictional and generated here.
 *
 *  - the ID types offered are exactly the recognition registry's government IDs (server and page agree);
 *  - the selected type is a hint only: it never reaches the automatic check, and a back side never does;
 *  - front and back are separate files: choosing, replacing or removing one never touches the other;
 *  - both sides are required for every ID type, a passport included (page and server); the type is required;
 *  - Staff are warned when the selected type differs from the type the automatic check identified;
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

// ================= 1. The ID types offered come from the recognition registry =================
$options = DocumentRequirements::idTypeOptions();
$check('1. the options are exactly the government IDs the registry recognises (IdDocumentTypes::PRECEDENCE), 14 of them',
    count($options) === 14 && array_diff(IdDocumentTypes::PRECEDENCE, array_keys($options)) === [] && array_diff(array_keys($options), IdDocumentTypes::PRECEDENCE) === [], $options);
$check('1. labels and order are the registry\'s own (DocumentAnalysis::ID_KINDS); no school, company or "other" ID is offered',
    array_values(array_intersect(array_keys(DocumentAnalysis::ID_KINDS), array_keys($options))) === array_keys($options)
    && !isset($options['school_or_company_id']) && !isset($options['other_id'])
    && array_intersect_key(DocumentAnalysis::ID_KINDS, $options) === $options);
preg_match('/const ID_TYPE_OPTIONS = \[(.*?)\];/s', $html, $m);
preg_match_all("/\['([a-z_]+)','((?:[^'\\\\]|\\\\.)*)'\]/", $m[1] ?? '', $pairs, PREG_SET_ORDER);
$pageOptions = [];
foreach ($pairs as $pair) {
    $pageOptions[$pair[1]] = stripcslashes($pair[2]);
}
$check('1. the page offers the same codes, labels and order as the server (no invented type)', $pageOptions === $options, [$pageOptions, $options]);

// ================= 2. Requirements: front and back for every ID type =================
$owner = DocumentRequirements::requirementsFor('docreq', true, ['ownerId' => 'philhealth_id']);
$check('2. a new owner submission needs the ID\'s front and back', array_keys($owner) === ['ownerId', 'ownerIdBack']
    && $owner['ownerIdBack'] === 'Valid Government-Issued ID (back side)', $owner);
$check('2. an unknown type (an older page that sends none) still needs the back', array_keys(DocumentRequirements::requirementsFor('docreq', true, ['ownerId' => null])) === ['ownerId', 'ownerIdBack']);
$everyTypeNeedsBack = array_filter(array_keys($options), static fn (string $code): bool =>
    array_keys(DocumentRequirements::requirementsFor('docreq', false, ['ownerId' => $code, 'requesterId' => $code])) !== ['ownerId', 'requesterId', 'authLetter', 'ownerIdBack', 'requesterIdBack']);
$check('2. every one of the 14 types, a passport included, needs the ID\'s front and back (no type waives a side)', $everyTypeNeedsBack === []
    && !method_exists(DocumentRequirements::class, 'optionalFor') && !defined(DocumentRequirements::class . '::BACK_OPTIONAL_ID_TYPES'), $everyTypeNeedsBack);
$check('2. the page has no type-based exception either: the same rule on both sides (client and server)', !str_contains($html, 'BACK_OPTIONAL') && !str_contains($html, 'backIsOptional')
    && str_contains($html, "idFrontKeys(fd).forEach(function(front){ keys.push(front, ID_BACK_KEYS[front]); });"));
$check('2. a stored type is shown to Staff only when it is one of the registry\'s types', DocumentRequirements::idTypeHintLabel('umid') === 'UMID'
    && DocumentRequirements::idTypeHintLabel('school_or_company_id') === null && DocumentRequirements::idTypeHintLabel('<b>x</b>') === null
    && DocumentRequirements::idTypeHintLabel(null) === null && DocumentRequirements::idTypeHintLabel(['umid']) === null);
$rep = DocumentRequirements::requirementsFor('landtransfer', false, ['ownerId' => 'umid', 'requesterId' => 'drivers_license']);
$check('2. a representative (Land Transfer): both IDs front and back, the letter and the five documents', array_keys($rep) === ['ctcTdOrTitle', 'notarialDeed', 'vicinityMap',
    'certNoImprovement', 'taxClearance', 'ownerId', 'requesterId', 'authLetter', 'ownerIdBack', 'requesterIdBack'], array_keys($rep));
$check('2. the automatic check\'s list (no types given) is unchanged: fronts only', array_keys(DocumentRequirements::requirementsFor('docreq', false)) === ['ownerId', 'requesterId', 'authLetter']
    && array_column((new DocumentRequirements())->evaluate('docreq', true, ['ownerId' => ['mime' => 'image/png', 'size' => 10]])['items'], 'key') === ['ownerId']);
$check('2. a back side is an ID image: JPG or PNG only, the same 5 MB rule', DocumentRequirements::allowedMimeTypes('ownerIdBack') === ['image/jpeg', 'image/png']
    && DocumentRequirements::allowedMimeTypes('requesterIdBack') === ['image/jpeg', 'image/png'] && in_array('ownerIdBack', DocumentRequirements::IDENTITY_KEYS, true)
    && in_array('requesterIdBack', DocumentRequirements::allKeys(), true));
$check('2. drafts may hold both back sides and remember both selected types', str_contains($drafts, "'ownerIdBack', 'requesterIdBack',")
    && str_contains($drafts, "'ownerIdType' => 40, 'requesterIdType' => 40"));

// ================= 3. Submission (source): hint validated, back stored separately, nothing to the AI =================
$check('3. the server accepts only a registry type (or none) and checks it before anything is saved', str_contains($api, 'function sa_id_type_hints(array $data, bool $isOwner): array')
    && str_contains($api, "throw new SaHttpException(422, 'Select the type of valid ID from the list.');")
    && strpos($api, '$idTypes = sa_id_type_hints($data') < strpos($api, '$pdo = sa_db();' . "\n    \$storedFiles"));
$check('3. a file that is not required is still refused', str_contains($api, "foreach (array_keys(\$files) as \$key) {\n        if (!isset(\$documents[\$key])) {"));
$check('3. a form that names a type must send that ID\'s front and back (the server checks, whatever the page did)', str_contains($api, '$missingSides[] = $documents[$side];')
    && str_contains($api, "'Please upload clear photos of both the front and the back of your valid ID: '"));
$check('3. the missing-document messages tell the applicant to reload the page if the upload place is not shown', substr_count($api, 'please reload this page and try again.') === 2);
$check('3. the submit script\'s address carries a version (a hash of its content), so an old cached copy is not used', str_contains((string) file_get_contents($app . '/web/prototype.php'),
    "<script src=\"/assets/php-bridge.js?v=' . \$bridgeVersion . '\"></script>'")
    && str_contains((string) file_get_contents($app . '/web/prototype.php'), "\$bridgeVersion = is_file(\$bridgeFile) ? substr((string) hash_file('sha256', \$bridgeFile), 0, 12) : '0';"));
$check('3. every file is stored under its own key (front and back never share a row or a file name)', str_contains($api, 'foreach ($documents as $key => $label) {')
    && str_contains($api, "\$storedName = \$itemId . '_' . bin2hex(random_bytes(16)) . '.' . \$extension;"));
$check('3. the selected type is stored only once the migration has run (no error before it)', str_contains($api, "\$withIdTypeHint = sa_has_column('request_documents', 'id_type_hint');"));
$migration = (string) file_get_contents($app . '/database/migrations/20261121_request_document_id_type_hint.sql');
$sql = trim((string) preg_replace('/^--.*$/m', '', $migration));
$check('3. the migration adds one nullable column, only when it is missing (safe to run again)', str_contains($sql, "'ALTER TABLE request_documents ADD COLUMN id_type_hint VARCHAR(40) NULL DEFAULT NULL AFTER file_status'")
    && str_contains($sql, "SET @sql := IF(@has_id_type_hint = 0,") && substr_count($sql, 'ALTER TABLE') === 1 && !preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE)\b/i', $sql), $sql);
$rollback = trim((string) preg_replace('/^--.*$/m', '', (string) file_get_contents($app . '/database/rollbacks/20261121_request_document_id_type_hint_rollback.sql')));
$check('3. the rollback drops only that column, only when present', str_contains($rollback, "'ALTER TABLE request_documents DROP COLUMN id_type_hint'")
    && str_contains($rollback, 'SET @sql := IF(@has_id_type_hint = 1,') && substr_count($rollback, 'ALTER TABLE') === 1 && substr_count($rollback, 'DROP') === 1, $rollback);
$check('3. the bridge sends the selected types (the representative\'s only for a representative)', str_contains($bridge, "payload.owner_id_type = String(form.ownerIdType || formValues.ownerIdType || '');")
    && str_contains($bridge, "if (!payload.is_owner) payload.requester_id_type = String(form.requesterIdType || formValues.requesterIdType || '');"));

// ================= 4. The automatic check never receives a back side or the selected type =================
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE requests (id INTEGER PRIMARY KEY, flow TEXT, is_owner INTEGER, first_name TEXT, last_name TEXT, arp_number TEXT, document_type TEXT, transfer_type TEXT)');
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
    $pdo->prepare('INSERT INTO requests VALUES (?,?,?,?,?,?,?,?)')->execute([$id, $flow, $isOwner, 'ROSA', 'FICTIONAL', '', 'Certification of Assessment', '']);
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
    && array_keys($owner['declared']) === ['flow', 'is_owner', 'first_name', 'last_name', 'arp_number', 'document_type', 'transfer_type']);

// ================= 5. Staff page: back side next to its front, the type marked "not verified" =================
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

// 6.1 Dropdown and layout (owner, desktop)
$g = $run('desktop', $base . 'S.render();' . $layout
    . ' var sel=document.getElementById("f-ownerIdType"); out.label=document.querySelector("label[for=f-ownerIdType]").textContent; out.placeholder=sel.options[0].textContent;'
    . ' out.placeholderValue=sel.options[0].value; out.options=sel.options.length; out.required=sel.required; out.note=document.querySelector(".id-sides-note").textContent;'
    . ' sel.value="philhealth_id"; sel.dispatchEvent(new Event("change")); out.chosen=S.state.formData.ownerIdType;'
    . ' out.selected=(document.getElementById("hint-ownerIdType")||{}).textContent; out.selValue=document.getElementById("f-ownerIdType").value;', 1366);
$check('6.1 owner: one ID block with the label "Select Type of Valid ID" and the placeholder "Select your ID type"', ($g['blocks'] ?? 0) === 1
    && ($g['label'] ?? '') === 'Select Type of Valid ID*' && ($g['placeholder'] ?? '') === 'Select your ID type' && ($g['placeholderValue'] ?? 'x') === '', $g);
$check('6.1 the dropdown lists the 14 registry types and is required', ($g['options'] ?? 0) === 15 && ($g['required'] ?? false) === true, $g);
$check('6.1 choosing a type keeps it in the form and shows it clearly', ($g['chosen'] ?? '') === 'philhealth_id' && ($g['selValue'] ?? '') === 'philhealth_id'
    && ($g['selected'] ?? '') === 'Selected: PhilHealth ID. The office still checks the ID you upload.', $g);
$check('6.1 desktop: FRONT and BACK side by side, equal widths, with the instruction', ($g['sides'][0]['sideBySide'] ?? false) === true && ($g['sides'][0]['equal'] ?? false) === true
    && ($g['sides'][0]['labels'] ?? null) === ['FRONT SIDE OF ID*', 'BACK SIDE OF ID*'] && ($g['note'] ?? '') === 'Upload clear photos of both sides of your ID.' && ($g['scrollX'] ?? true) === false, $g);

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

// 6.4 Required fields (a passport's back included); the review
$g = $run('required', $base . 'S.render(); S.nextStep(); out.errors=Object.keys(S.state.errors); out.step=S.state.step;'
    . ' out.typeError=(document.getElementById("err-ownerIdType")||{}).textContent; out.backError=(document.getElementById("err-ownerIdBack")||{}).textContent;'
    . ' fd.ownerIdType="passport"; fd.files={ownerId:{name:"front.png",size:10,type:"image/png",valid:true}}; S.state.errors={}; S.render();'
    . ' out.passportNote=document.querySelector(".id-sides-note").textContent; out.backLabel=document.querySelectorAll(".id-side-label")[1].textContent;'
    . ' out.passportBackError=(document.getElementById("err-ownerIdBack")||{}).textContent||null;'
    . ' S.nextStep(); out.passportStep=S.state.step; out.passportErrors=Object.keys(S.state.errors);'
    . ' fd.ownerIdType="umid"; S.state.step=3; S.render(); S.nextStep(); out.umidErrors=Object.keys(S.state.errors);'
    . ' fd.files.ownerIdBack={name:"back.png",size:10,type:"image/png",valid:true}; S.state.errors={}; S.nextStep(); out.okStep=S.state.step;'
    . ' out.review=[].slice.call(document.querySelectorAll(".review-table tr")).map(function(r){ return r.textContent; }).filter(function(t){ return /Valid ID/.test(t); });', 1366);
$check('6.4 without a type or photos the step does not continue: type, front and back are each required', ($g['step'] ?? 0) === 3
    && ($g['errors'] ?? null) === ['ownerIdType', 'ownerId', 'ownerIdBack'] && ($g['typeError'] ?? '') === 'Select the type of valid ID you are uploading.'
    && ($g['backError'] ?? '') === 'Property Owner Valid ID (back side) upload is required.', $g);
$check('6.4 a passport also needs its back: still required, and the step does not continue with the front only', ($g['passportNote'] ?? '') === 'Upload clear photos of both sides of your ID.'
    && ($g['backLabel'] ?? '') === 'BACK SIDE OF ID*' && ($g['passportStep'] ?? 0) === 3 && ($g['passportErrors'] ?? null) === ['ownerIdBack'], $g);
$check('6.4 any other ID still needs its back; with both sides it continues', ($g['umidErrors'] ?? null) === ['ownerIdBack'] && ($g['okStep'] ?? 0) === 4, $g);
$check('6.4 the review shows the selected type and both sides', ($g['review'] ?? null) === ['Property Owner Valid ID: ID TypeUMID', 'Property Owner Valid IDfront.png',
    'Property Owner Valid ID (back side)back.png'], $g);

// 6.5 Representative: both IDs need their own type and both sides
$g = $run('repRequired', $repBase . 'S.nextStep(); out.errors=Object.keys(S.state.errors).sort();', 1366);
$check('6.5 representative: each ID has its own type, front and back; the letter is still required', ($g['errors'] ?? null) === ['authLetter', 'ownerId', 'ownerIdBack', 'ownerIdType',
    'requesterId', 'requesterIdBack', 'requesterIdType'], $g);

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
    . ' var ok={name:"x.png",size:10,type:"image/png",valid:true}; fd.requesterIdType="drivers_license"; fd.ownerIdType="passport";'
    . ' ["requesterId","requesterIdBack","ownerId","ownerIdBack","authLetter"].concat(docs).forEach(function(k){ fd.files[k]=Object.assign({}, ok, {name:k+".png"}); });'
    . ' S.state.errors={}; S.nextStep(); out.step=S.state.step;'
    . ' out.review=[].slice.call(document.querySelectorAll(".review-table tr")).map(function(r){ return r.textContent; }).filter(function(t){ return /Valid ID/.test(t); });', 1366);
$check('6.6b representative Land Transfer: the representative\'s and the owner\'s ID blocks, the letter and the five documents', ($g['blocks'] ?? null) === ["Authorized Representative's Valid ID", 'Property Owner Valid ID']
    && ($g['letter'] ?? false) === true && ($g['docs'] ?? 0) === 5, $g);
$check('6.6b nothing uploaded: every document, both IDs\' types and both sides of each ID are required', ($g['errors'] ?? null) === ['authLetter', 'certNoImprovement', 'ctcTdOrTitle',
    'notarialDeed', 'ownerId', 'ownerIdBack', 'ownerIdType', 'requesterId', 'requesterIdBack', 'requesterIdType', 'taxClearance', 'vicinityMap'], $g);
$check('6.6b complete: it continues to the review, which lists both IDs\' types and both sides', ($g['step'] ?? 0) === 4 && ($g['review'] ?? null) === [
    "Authorized Representative's Valid ID: ID TypePhilippine Driver's License", 'Property Owner Valid ID: ID TypePassport', "Authorized Representative's Valid IDrequesterId.png",
    "Authorized Representative's Valid ID (back side)requesterIdBack.png", 'Property Owner Valid IDownerId.png', 'Property Owner Valid ID (back side)ownerIdBack.png'], $g);

// 6.6c A saved draft restored in the browser: both photos and the selected type come back
$saved = '[{doc_key:"ownerId", name:"front.png", size:120, type:"image/png"}, {doc_key:"ownerIdBack", name:"back.png", size:130, type:"image/png"}]';
$g = $run('draftRestore', 'S.applyDraft("docreq", 3, 3, {firstName:"Rosa", lastName:"Fictional", contact:"+639170000001", addressLine:"1 Demo St.", requesterBarangay:"POBLACION",'
    . ' barangay:"POBLACION", arp:"14-0001-00045", documentType:"Certification of Assessment", purpose:"Personal Copy", ownerIdType:"umid"}, ' . $saved . '); S.render();'
    . ' out.type=document.getElementById("f-ownerIdType").value; out.selected=document.getElementById("hint-ownerIdType").textContent;'
    . ' out.sides=[].slice.call(document.querySelectorAll(".id-side .upl-file")).map(function(e){ return e.textContent; });'
    . ' out.saved=[].slice.call(document.querySelectorAll(".id-side .upl-ok")).map(function(e){ return e.textContent; });'
    . ' S.nextStep(); out.step=S.state.step; out.errors=Object.keys(S.state.errors);', 1366);
$check('6.6c a restored draft shows its selected type and both saved photos, and continues without asking again', ($g['type'] ?? '') === 'umid'
    && ($g['selected'] ?? '') === 'Selected: UMID. The office still checks the ID you upload.' && ($g['sides'] ?? null) === ['front.png', 'back.png']
    && ($g['saved'] ?? null) === ['Saved', 'Saved'] && ($g['step'] ?? 0) === 4 && ($g['errors'] ?? null) === [], $g);
$g = $run('draftTampered', 'S.applyDraft("docreq", 3, 3, {firstName:"Rosa", lastName:"Fictional", ownerIdType:"<b>bogus</b>"}, ' . $saved . '); S.render();'
    . ' out.type=document.getElementById("f-ownerIdType").value; out.selected=document.getElementById("hint-ownerIdType").textContent;'
    . ' out.html=document.querySelector(".id-type-field").innerHTML.indexOf("bogus")>-1; S.nextStep(); out.step=S.state.step; out.typeError=(document.getElementById("err-ownerIdType")||{}).textContent;', 1366);
$check('6.6c an unknown or altered saved type: the placeholder and the prompt (never an empty "Selected:"), and the type is still required', ($g['type'] ?? 'x') === ''
    && ($g['selected'] ?? '') === 'Choose the ID you will upload.' && ($g['html'] ?? true) === false && ($g['step'] ?? 0) === 3
    && ($g['typeError'] ?? '') === 'Select the type of valid ID you are uploading.', $g);

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
    . '  return {panelNote: ratio(q(".id-guidance-panel > .reference-note")), selected: ratio(q(".id-type-selected")), sidesNote: ratio(q(".id-sides-note")),'
    . '   title: ratio(q(".id-card-title")), sideLabel: ratio(q(".id-side-label")), meta: ratio(q(".id-side .upl-meta span"))}; }';
$themeRun = static fn (string $theme): string => 'document.documentElement.setAttribute("data-theme","' . $theme . '");' . $base . 'fd.ownerIdType="philhealth_id"; S.render();'
    . $contrast . ' out.r=measure();';
$dark = $run('contrastDark', $themeRun('dark'), 1366);
$light = $run('contrastLight', $themeRun('light'), 1366);
$aa = static fn (array $r): bool => $r !== [] && min($r) >= 4.5;
echo 'INFO  measured contrast, desktop: dark ' . json_encode($dark['r'] ?? null) . ' | light ' . json_encode($light['r'] ?? null) . "\n";
$check('6.6d dark theme, desktop: every ID panel text keeps at least 4.5:1 (the guidance note, "Selected:", the instruction, titles, labels)', $aa((array) ($dark['r'] ?? [])), $dark);
$check('6.6d light theme, desktop: the same texts keep at least 4.5:1', $aa((array) ($light['r'] ?? [])), $light);
$check('6.6d the dark panel follows the system setting too (same rule as the chosen dark theme)', str_contains($html,
    '@media (prefers-color-scheme: dark) and (min-width:1025px){ :root:not([data-theme="light"]) .id-guidance-panel{ background:var(--surface-2);')
    && str_contains($html, ':root[data-theme="dark"] .id-guidance-panel{ background:var(--surface-2);'));

// 6.7 Text styles: the new parts reuse the page's existing typography
$css = $between($html, '/* ---- Valid ID: type selector and front/back photos.', '@media (prefers-reduced-motion:reduce){ .id-side');
$check('6.7 the new styles change no font (no font-family, font-size or font shorthand)', $css !== '' && !preg_match('/font(-family|-size)?\s*:/', $css), $css === '' ? 'css block not found' : '');
[$outsideSupports] = explode('@supports', (string) preg_replace('#/\*.*?\*/#s', '', $css), 2) + [1 => ''];
$check('6.7 without color-mix() or backdrop-filter the cards are the opaque theme surface (translucency and blur only inside @supports)',
    str_contains($outsideSupports, 'background:var(--surface);') && str_contains($outsideSupports, 'background:var(--surface-2);')
    && !str_contains($outsideSupports, 'backdrop-filter') && !str_contains($outsideSupports, 'color-mix')
    && str_contains($css, '@supports (background:color-mix(in srgb, red 50%, transparent)) and ((-webkit-backdrop-filter:blur(1px)) or (backdrop-filter:blur(1px))){'));
$check('6.7 the card colours come from the theme (light and dark), never a fixed white', !preg_match('/rgba\(255,\s*255,\s*255,\s*\.[5-9]/', $css) && !str_contains($css, '#fff'));

// 6.8 Screenshots (optional, outside the repository)
if ($shotDir !== null && is_dir($shotDir)) {
    $shotJs = $base . 'fd.ownerIdType="philhealth_id"; S.render(); return Promise.all([fakeId("FRONT SIDE"), fakeId("BACK SIDE")]).then(function(f){ '
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
