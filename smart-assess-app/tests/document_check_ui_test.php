<?php
declare(strict_types=1);

/*
 * Staff Document Check presentation (no database, no network, demo data only): results are
 * built with the real DocumentAnalysis::classify() and shaped as AiVerification::staffViews()
 * sends them, then rendered by the page's own functions in Node.
 *
 *   php tests/document_check_ui_test.php
 */

require_once dirname(__DIR__) . '/app/services/DocumentAnalysis.php';

$project = dirname(__DIR__, 2);
$html = (string) file_get_contents($project . '/smart-assess-internal.html');
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 700)];
};
$between = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    if ($from === false) {
        return '';
    }
    $to = strpos($source, $end, $from + strlen($start));
    return $to === false ? '' : substr($source, $from, $to - $from);
};
$fn = static fn (string $name): string => $between($html, 'function ' . $name . '(', "\n}\n") . "\n}\n";

// ---- Demo results through the real server rules ----
$labels = ['ownerId' => 'Valid ID of Property Owner', 'ctcTdOrTitle' => 'Certified True Copy of Tax Declaration or Title', 'certNoImprovement' => 'Certification of No Improvement'];
$keys = array_keys($labels);
$doc = static fn (string $key, string $kind, string $holder, bool $clear = true, string $number = ''): array => ['key' => $key, 'document_kind' => $kind,
    'holder_name' => $holder, 'holder_name_clear' => $clear, 'property_number' => $number, 'expected_type_match' => 'yes', 'missing_fields' => []];
/** One staff view, as staffViews() returns it, from a worker answer (version 4 by default: printed titles found
 *  in each file's text default to the kind the model suggested; pass $evidence to set them per document). */
$view = static function (array $docs, array $findings = [], array $readability = [], array $ruleFindings = [], int $version = 4, array $evidence = []) use ($labels, $keys): array {
    $checks = [];
    foreach ($keys as $key) {
        $holder = '';
        $kind = null;
        foreach ($docs as $d) {
            if ($d['key'] === $key) {
                $holder = $d['holder_name'] ?? '';
                $kind = $d['document_kind'] ?? null;
            }
        }
        $checks[] = ['key' => $key, 'ocr_status' => 'ok', 'ocr_error' => null, 'readability' => $readability[$key] ?? 'good', 'pages' => 1, 'method' => 'image_ocr',
            'text_characters' => 400] + ($version >= 3 ? ['name_match' => $holder === '' ? 'none' : 'exact'] : [])
            + ($version >= 4 ? ['kind_evidence' => $evidence[$key] ?? ($kind !== null && isset(DocumentAnalysis::KIND_EVIDENCE[$kind]) ? [$kind] : [])] : []);
    }
    $worker = DocumentAnalysis::validateWorkerResult(['version' => $version, 'documents' => $checks, 'rule_findings' => $ruleFindings,
        'model' => ['status' => 'ok', 'name' => 'demo-model', 'digest' => null, 'output' => ['documents' => $docs, 'findings' => $findings, 'manual_review_recommended' => false]],
        'ocr_engine' => 'tesseract (eng)'], $keys);
    $requirements = ['items' => array_map(static fn (string $k): array => ['key' => $k, 'label' => $labels[$k], 'status' => 'uploaded_valid_format'], $keys)];
    $result = DocumentAnalysis::classify($requirements, $worker, $labels);
    return [
        'status' => 'completed', 'outcome' => $result['outcome'], 'reason' => $result['reason'],
        'findings' => array_map(static fn (array $f): array => ['category' => $f['category'], 'field' => $f['field'],
            'documents' => array_map(static fn (string $k): string => $labels[$k] ?? $k, $f['document_keys']), 'explanation' => $f['explanation'], 'evidence' => $f['evidence'], 'source' => $f['source']], $result['findings']),
        'documents' => $result['documents'], 'model' => 'demo-model', 'ocrEngine' => 'tesseract (eng)', 'attempts' => 1, 'maxAttempts' => 3,
        'error' => null, 'startedAt' => '2026-11-18 10:00:00', 'completedAt' => '2026-11-18 10:01:10', 'reviewed' => false,
    ];
};
$finding = static fn (string $category, string $field, array $docKeys, string $evidence = ''): array => ['category' => $category, 'field' => $field, 'document_keys' => $docKeys, 'explanation' => 'x', 'evidence' => $evidence];
$clean = [$doc('ownerId', 'drivers_license', 'DELA CRUZ, JUAN SANTOS'), $doc('ctcTdOrTitle', 'tax_declaration', 'JUAN DELA CRUZ', true, '018-00045'), $doc('certNoImprovement', 'certification_no_improvement', 'JUAN DELA CRUZ')];
$surnameRule = ['category' => 'possible_inconsistency', 'field' => 'name', 'document_keys' => ['ownerId'], 'explanation' => 'The applicant\'s surname as entered in the form was not found in the text read from Valid ID of Property Owner.', 'evidence' => '', 'source' => 'rule'];
$cases = [
    'clean' => $view($clean),
    'mismatch' => $view([$doc('ownerId', 'philhealth_id', 'REYES, MARIA LUZ'), $clean[1], $clean[2]], [$finding('possible_inconsistency', 'name', ['ownerId'])], [], [$surnameRule]),
    'unsure' => $view([$doc('ownerId', 'passport', 'DELA CRU2, JUAN SANTOS'), $clean[1], $clean[2]]),
    'unsureOther' => $view([$doc('ownerId', 'passport', 'REYES, MAR1A LUZ'), $clean[1], $clean[2]]),
    'vagueOnly' => $view($clean, [$finding('possible_inconsistency', 'other', ['ctcTdOrTitle'])]),
    'htmlName' => $view([$doc('ownerId', 'drivers_license', 'DELA CRUZ <img src=x onerror=alert(1)>'), $clean[1], $clean[2]]),
    'clearanceInIdSlot' => $view([$doc('ownerId', 'barangay_clearance', 'JUAN DELA CRUZ'), $clean[1], $clean[2]]),
    'unknownKind' => $view([$doc('ownerId', 'unclear', ''), $clean[1], $clean[2]]),
    'flood' => $view([$doc('ownerId', 'drivers_license', 'DELA CRUZ, JUAN SANTOS'), $clean[1], $doc('certNoImprovement', 'barangay_clearance', 'JUAN DELA CRUZ')], [
        $finding('cannot_verify', 'other', ['ownerId']), $finding('cannot_verify', 'other', ['ownerId']), $finding('cannot_verify', 'other', ['ctcTdOrTitle']),
        $finding('ocr_uncertainty', 'other', ['certNoImprovement']), $finding('ocr_uncertainty', 'other', ['certNoImprovement']),
        $finding('possible_inconsistency', 'other', ['ctcTdOrTitle']), $finding('possible_inconsistency', 'other', ['ownerId']),
        $finding('possible_inconsistency', 'property_identifier', ['ctcTdOrTitle'], 'ARP No. 018-00099 Owner'), $finding('possible_inconsistency', 'property_identifier', ['ctcTdOrTitle'], 'ARP No. 018-00099 Owner'),
    ], ['certNoImprovement' => 'partial']),
    'legacy' => $view(array_map(static fn (string $k): array => ['key' => $k, 'expected_type_match' => 'yes', 'missing_fields' => [], 'note' => ''], $keys), [], [], [], 2),
];

$cases['modelSilent'] = array_replace($cases['clean'], ['outcome' => 'needs_review', 'findings' => [],
    'reason' => 'Needs review: the AI model did not review the documents, so only the automatic checks ran. This is not an approval: Staff must inspect the original documents and make the decision.']);
// The number read differs from the application, and no server finding says so (the page's own comparison).
$cases['numberOnly'] = $cases['clean'];
$cases['numberOnly']['documents'][1]['property_number'] = '018-00099';
$cases['unreadableId'] = $view([$doc('ownerId', 'unclear', '', false), $clean[1], $clean[2]], [], ['ownerId' => 'unreadable']);
// A required document that was not uploaded: the requirement check's own finding.
$cases['notUploaded'] = $cases['clean'];
$cases['notUploaded']['documents'][2] = ['key' => 'certNoImprovement', 'label' => $labels['certNoImprovement'], 'requirement' => 'missing', 'ocr_status' => 'skipped', 'readability' => null, 'pages' => 0];
$cases['notUploaded']['outcome'] = 'missing_information';
$cases['notUploaded']['findings'][] = ['category' => 'missing_information', 'field' => 'required_field', 'documents' => [$labels['certNoImprovement']],
    'explanation' => 'Certification of No Improvement was not uploaded.', 'evidence' => '', 'source' => 'requirements'];
foreach (['parts' => 'partsName', 'incomplete' => 'incompleteName'] as $match => $case) {
    $cases[$case] = $cases['clean'];
    $cases[$case]['documents'][0]['holder_name_match'] = $match;
}

// ---- Render with the page's own functions (stubs only for page plumbing) ----
$script = 'function esc(s){ return String(s==null?"":s).replace(/[&<>"\']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];}); }'
    . 'function ic(){ return ""; } function fmtDateTime(s){ return String(s); }'
    . $between($html, 'var AI_CHECK_STATES = {', 'function aiCheckState(r, kind){')
    . $fn('aiCheckState') . $fn('aiDurationText') . $fn('renderDocCheckDetail') . $fn('docCheckDisclaimer')
    . $fn('dcNameHtml') . $between($html, 'var DC_DIGIT_LETTERS', 'function dcNameParts(') . $fn('dcNameParts') . $fn('dcEditDistance') . $fn('dcNameCompare')
    . $fn('dcImportantIssues')
    . $between($html, 'var DC_VERDICT = {', 'function dcContext(r){') . $fn('dcContext') . $fn('dcUnread') . $fn('dcCheck') . $fn('dcKindCheck') . $fn('dcKindCheckTitles') . $fn('dcNameCheck')
    . $between($html, 'var DC_LAND_DOCUMENTS', 'function dcDocChecks(') . $fn('dcDocChecks') . $fn('dcAllPassed') . $fn('dcRequestChecks') . $fn('dcChecksHtml') . $fn('drDocCard')
    . 'var cases = ' . json_encode($cases, JSON_UNESCAPED_UNICODE) . ';'
    . <<<'JS'
var details = {firstName:'Juan', middleName:'Santos', lastName:'Dela Cruz', isOwner:true, arpNumber:'018-00045'};
var labels = {ownerId:'Valid ID of Property Owner', ctcTdOrTitle:'Certified True Copy of Tax Declaration or Title', certNoImprovement:'Certification of No Improvement'};
var mimes = {ownerId:'image/png', ctcTdOrTitle:'application/pdf', certNoImprovement:'image/jpeg'};
var mkFiles = function(){ return ['ownerId','ctcTdOrTitle','certNoImprovement'].map(function(k, i){ return {key:k, label:labels[k], name:'demo-'+k+(k==='ctcTdOrTitle'?'.pdf':'.png'), mime:mimes[k], docId:100+i, available:true, status:'uploaded'}; }); };
var out = {};
Object.keys(cases).forEach(function(name){
  var files = mkFiles();
  if(name==='notUploaded'){ files[2].name = ''; files[2].status = 'missing'; }
  if(name==='unsure') files[1].available = false;
  var r = {id:'LT-DEMO-1', applicant:'Juan Dela Cruz', ai:cases[name], aiEligibility:{eligible:true}, details:details, files:files};
  out[name] = {html: renderDocCheckDetail({r:r, kind:'land', type:'Land Transfer: Sale', state:aiCheckState(r, 'land')}), issues: dcImportantIssues(cases[name]).issues.length,
    checks: dcRequestChecks(r, 'land').map(function(c){ return [c.name, c.verdict]; }), state: aiCheckState(r, 'land'),
    // The same result shown as a Document Request (the ID checks): its own checks and page.
    drChecks: dcRequestChecks(r, 'doc').map(function(c){ return [c.name, c.verdict]; }), drState: aiCheckState(r, 'doc'),
    drHtml: renderDocCheckDetail({r:Object.assign({}, r, {id:'DR-DEMO-1'}), kind:'doc', type:'Certified True Copy', state:aiCheckState(r, 'doc')})};
});
// No automatic result yet: the originals are still shown for review.
['none', 'waiting', 'off'].forEach(function(st){
  var files = mkFiles(); files[2].name = ''; files[2].status = 'missing';
  var r = {id:'LT-DEMO-1', applicant:'Juan Dela Cruz', ai: st==='waiting' ? {status:'pending', attempts:0, maxAttempts:3} : null,
    aiEligibility: st==='off' ? {eligible:false, reason:'not_enabled'} : {eligible:true}, details:details, files:files};
  out['state_'+st] = {html: renderDocCheckDetail({r:r, kind:'land', type:'Land Transfer: Sale', state:aiCheckState(r, 'land')})};
});
out.compare = [
  ['DELA CRUZ, JUAN SANTOS','Juan','Dela Cruz'], ['MARIA LUISA REYES','Ma. Luisa','Reyes'], ['DELACRUZ, JUAN','Juan','Dela Cruz'], ['DE LA CRUZ, JUAN','Juan','Dela Cruz'],
  ['DELOS SANTOS, ANA','Ana','Delos Santos'], ['DEL ROSARIO, ANA','Ana','Delrosario'], ['DELA CRUZ, JUAN','Juan','Dela Cruz Jr.'], ['DELA CRUZ JR, JUAN','Juan','Dela Cruz Sr.'],
  ['DELA CRUS, JUAN','Juan','Dela Cruz'], ['DELA CRU2, JUAN','Juan','Dela Cruz'], ['DELA CRUZ, JUAN','Juan','Cruz'], ['CRUZ, J.','Juan','Cruz'],
  ['REYE5, JUAN','Juan','Cruz'], ['REYES, MARIA','Juan','Dela Cruz'], ['LIM, ANA','Ana','Lin'], ['SANTOS, JUAN','Juan','Santiago'], ['JUAN','Juan','Dela Cruz'], ['DELA CRUZ, JUAN','','']
].map(function(c){ return dcNameCompare(c[0], {firstName:c[1], lastName:c[2]}); });
process.stdout.write(JSON.stringify(out));
JS;
$jsFile = tempnam(sys_get_temp_dir(), 'sa-dc-') . '.js';
file_put_contents($jsFile, $script);
$node = trim((string) shell_exec('command -v node'));
$check('node is available', $node !== '');
$raw = $node !== '' ? (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($jsFile) . ' 2>&1') : '';
@unlink($jsFile);
$out = json_decode($raw, true);
$check('the page functions run on the demo results', is_array($out), $raw);
$out = is_array($out) ? $out : [];
$page = static fn (string $name): string => (string) ($out[$name]['html'] ?? '');
$text = static fn (string $name): string => (string) preg_replace('/\s+/', ' ', strip_tags($page($name)));

// ---- Structure: the original first, then its checks, then the details read ----
$c = $page('clean');
$card = static function (string $html, int $n): string {
    $parts = explode('<article class="dr-doc', $html);
    return (string) ($parts[$n + 1] ?? '');
};
$checksOf = static fn (string $name): array => $out[$name]['checks'] ?? [];
$drOf = static fn (string $name): array => $out[$name]['drChecks'] ?? [];
$drPage = static fn (string $name): string => (string) ($out[$name]['drHtml'] ?? '');
$check('the page is titled Document Review and shows one card per uploaded document', str_contains($c, '<h1>Document Review</h1>') && substr_count($c, '<article class="dr-doc') === 3, $text('clean'));
$check('each card shows the document category (what was required) and the file name and type', str_contains($card($c, 0), '<h3>Valid ID of Property Owner</h3>')
    && str_contains($card($c, 0), 'demo-ownerId.png · PNG image · 1 page · Text: Readable') && str_contains($card($c, 1), 'demo-ctcTdOrTitle.pdf · PDF document'), $card($c, 0));
$order = $card((string) ($out['clean']['drHtml'] ?? ''), 0);
$check('in each card the preview comes first, then the checks, then the details read', strpos($order, 'dr-preview') < strpos($order, 'class="dc-checks"')
    && strpos($order, 'class="dc-checks"') < strpos($order, '<dt>Name read</dt>'), $order);
// ---- Secure previews ----
$check('an image is previewed inline from request-file.php (doc ID + reference), lazily, and opens full size in a new tab',
    str_contains($card($c, 0), '<a class="dr-preview" href="/request-file.php?doc=100&amp;ref=LT-DEMO-1" target="_blank" rel="noopener" title="Open full size in a new tab"><img class="dr-img" src="/request-file.php?doc=100&amp;ref=LT-DEMO-1&amp;preview=1" alt="Valid ID of Property Owner (uploaded file)" loading="lazy"></a>'), $card($c, 0));
$check('a PDF is previewed in an iframe from request-file.php', str_contains($card($c, 1), '<iframe class="dr-pdf" src="/request-file.php?doc=101&amp;ref=LT-DEMO-1&amp;preview=1" title="Certified True Copy of Tax Declaration or Title (uploaded PDF)" loading="lazy"></iframe>'), $card($c, 1));
$check('only the inline preview carries preview=1; Open and the full-size link open the original as before (recorded)',
    substr_count($card($c, 0), 'preview=1') === 1 && str_contains($card($c, 0), '<a class="btn btn-outline btn-sm" href="/request-file.php?doc=100&amp;ref=LT-DEMO-1" target="_blank" rel="noopener">Open')
    && str_contains($card($c, 0), '<a class="dr-preview" href="/request-file.php?doc=100&amp;ref=LT-DEMO-1" '), $card($c, 0));
preg_match_all('/(?:href|src)="([^"]*)"/', implode('', array_map($page, array_keys($cases))) . $page('state_none'), $urls);
$check('every link and preview on the page goes through request-file.php (no public upload URL, no storage path)', $urls[1] !== []
    && array_filter($urls[1], static fn (string $u): bool => !str_starts_with($u, '/request-file.php?doc=')) === [] && !str_contains(implode('', $urls[1]), 'uploads'), array_unique($urls[1]));
$check('a file that cannot be served shows why and no preview or link', str_contains($card($page('unsure'), 1), 'Cannot be opened: missing from storage or an unsupported file type.')
    && !str_contains($card($page('unsure'), 1), 'request-file.php'), $card($page('unsure'), 1));
$nu = $card($page('notUploaded'), 2);
$check('a document that was not uploaded: "Not uploaded" instead of a preview, and its check says Missing with the exact document', str_contains($nu, '<div class="dr-preview is-off">Not uploaded</div>')
    && str_contains($nu, '<strong>Document identification</strong><span class="dc-verdict">Missing</span>') && str_contains($nu, 'Not uploaded: Certification of No Improvement.') && !str_contains($nu, 'request-file.php'), $nu);
foreach (['none' => 'Not checked yet', 'waiting' => 'Waiting to be checked', 'off' => 'Automatic check not enabled'] as $st => $label) {
    $sp = (string) ($out['state_' . $st]['html'] ?? '');
    $check("without an automatic result ({$st}) the originals are still shown for review, with no invented summary", str_contains($sp, $label)
        && substr_count($sp, 'class="dr-img"') === 1 && substr_count($sp, 'class="dr-pdf"') === 1 && str_contains($sp, 'No automatic summary. Review the original.')
        && str_contains($sp, 'The client did not upload this document.') && !str_contains($sp, '<dt>') && !str_contains($sp, 'dc-check'), $sp);
}
// ---- All checks correct: stated explicitly, with what was detected ----
$check('Land Transfer: only the document checks appear (no Name Match or ID Type), and each identified document is Correct', $checksOf('clean') === [
    ['Document identification', 'correct'], ['Document identification', 'correct']], $checksOf('clean'));
$check('Land Transfer: with only two of the five documents in the result, the headline does not say all checks passed', !str_contains($c, 'All checks passed')
    && str_contains($c, '<strong>3 of 5 checks need attention</strong>') && ($out['clean']['state'] ?? '') === 'attention', $text('clean'));
$check('Land Transfer: the ID card says it is not part of the Document Check', str_contains($card($c, 0), 'Required with the application, but not part of the Land Transfer Document Check.')
    && !str_contains($card($c, 0), 'dc-check'), $card($c, 0));
$check('Document Request: a matching name on a recognised ID: both checks Correct, said explicitly, still asking for verification', $drOf('clean') === [['Name Match', 'correct'], ['ID Type', 'correct']]
    && str_contains($drPage('clean'), '<strong>All checks passed: Name Match and ID Type are correct</strong>.') && str_contains($drPage('clean'), 'Please verify the extracted details against the original document.')
    && ($out['clean']['drState'] ?? '') === 'clear', $drOf('clean'));
$check('each correct check shows what was detected and why', str_contains($card($drPage('clean'), 0), 'Read from the ID: DELA CRUZ, JUAN SANTOS · Submitted: Juan Santos Dela Cruz')
    && str_contains($card($drPage('clean'), 0), 'The name read from the ID matches the submitted name.') && str_contains($card($drPage('clean'), 0), '<span class="dc-detected">Philippine Driver&#39;s License</span>')
    && str_contains($card($c, 1), '<span class="dc-detected">Tax Declaration</span>') && str_contains($card($c, 1), 'Its printed title or issuer (Tax Declaration) appears in the file&#39;s text.'), $card($c, 0));
// ---- Names: never corrected, uncertain parts marked, compared only on the requester's own ID ----
$check('the ID holder\'s name is shown exactly as read (order, comma, middle name)', str_contains($card($c, 0), '<dt>Name read</dt><dd><span class="dc-name">DELA CRUZ, JUAN SANTOS</span><span class="cell-sub">Exactly as read</span>'), $card($c, 0));
$m = $drPage('mismatch');
$check('a different name on the requester\'s own ID: Name Match says Mismatch, with both names shown; the ID type is still confirmed', ($drOf('mismatch')[0] ?? null) === ['Name Match', 'mismatch']
    && ($drOf('mismatch')[1] ?? null) === ['ID Type', 'correct'] && str_contains($m, 'Read from the ID: REYES, MARIA LUZ · Submitted: Juan Santos Dela Cruz')
    && str_contains($m, 'The name read from the ID does not match the submitted name.') && str_contains($m, '<strong>1 of 2 checks need attention</strong>'), $drOf('mismatch'));
$u = $page('unsure');
$check('an uncertain part of a name is marked for Staff, never corrected', str_contains($u, 'DELA <mark class="dc-unsure" title="May be misread">CRU2,</mark> JUAN SANTOS')
    && str_contains($u, 'Unclear: check the marked letters on the original') && !str_contains($u, 'DELA CRUZ, JUAN'), $u);
$check('a misread letter alone is a Possible mismatch, not a match or a mismatch; another person\'s name with a misread letter is a Mismatch',
    ($drOf('unsure')[0] ?? null) === ['Name Match', 'possible'] && ($drOf('unsureOther')[0] ?? null) === ['Name Match', 'mismatch']
    && str_contains($page('unsureOther'), '<mark class="dc-unsure" title="May be misread">MAR1A</mark>'), [$drOf('unsure'), $drOf('unsureOther')]);
$check('the submitted name is never put in place of the name read', !str_contains($m, '<span class="dc-name">Juan') && !str_contains($u, '<span class="dc-name">Juan'));
$check('names on other documents are shown but not compared (no Name Match there)', !str_contains($card($c, 1), 'Name Match') && !str_contains($card($c, 2), 'Name Match'));
$check('a name read from labelled parts says so; a possibly incomplete name says so', str_contains($page('partsName'), 'Read from labelled parts (such as Last Name, Given Names), in the document&#39;s order')
    && str_contains($page('incompleteName'), 'Possibly incomplete: more of a name appears next to it') && !str_contains($card($page('incompleteName'), 0), '<span class="cell-sub">Exactly as read</span>'));
$ur = $card($drPage('unreadableId'), 0);
$check('an unreadable ID: no name or type invented; both checks are Unable to verify, never a pass', ($drOf('unreadableId')[0] ?? null) === ['Name Match', 'unable'] && ($drOf('unreadableId')[1] ?? null) === ['ID Type', 'unable']
    && !str_contains($ur, 'dc-name') && str_contains($ur, 'It is not assumed to match.') && str_contains($ur, 'Text: Could not be read'), $ur);
$check('an unknown ID type is Unable to verify, without a forced category', ($drOf('unknownKind')[1] ?? null) === ['ID Type', 'unable']
    && str_contains($card($drPage('unknownKind'), 0), 'The type of ID could not be identified from the text.'), $drOf('unknownKind'));
$cl = $drPage('clearanceInIdSlot');
$check('a Barangay Clearance in the ID slot: ID Type is a Possible mismatch, naming what it appears to be', ($drOf('clearanceInIdSlot')[1] ?? null) === ['ID Type', 'possible']
    && str_contains($cl, 'Appears to be Barangay Clearance (its title appears in the text), not a government-issued ID.'), $drOf('clearanceInIdSlot'));
$check('Document Request: only Name Match and ID Type, never a document check', array_unique(array_merge(...array_values(array_map(static fn ($o) => array_column($o['drChecks'] ?? [], 0),
    array_filter($out, static fn ($o) => is_array($o) && isset($o['drChecks'])))))) === ['Name Match', 'ID Type']);
// ---- Property numbers ----
$no = $page('numberOnly');
$check('a property number that differs from the application stays visible on its card', str_contains($card($no, 1), '<span class="mono">018-00099</span>')
    && str_contains($card($no, 1), 'Application:</span> <span class="mono">018-00045</span> &middot; <strong>Differs, verify</strong>'), $card($no, 1));
// ---- Other notes, technical details, older results ----
$f = $page('flood');
$check('model remarks outside the checks are kept in Technical details, not shown as checks', str_contains($f, '<details class="dr-tech"><summary>Technical details</summary>')
    && str_contains($text('flood'), 'Other automatic note (not part of these checks):') && substr_count($f, 'Signatures, seals and notarization can only be judged on the original') === 1
    && !str_contains($f, 'class="dr-issue"'), $text('flood'));
$check('a different document in a Land Transfer slot is a Possible mismatch naming what it appears to be', ($checksOf('flood')[1] ?? null) === ['Document identification', 'possible']
    && str_contains($card($f, 2), 'Appears to be Barangay Clearance (its title appears in the text), not Certification of No Improvement.'), $checksOf('flood'));
$check('technical details (OCR engine, model, attempts) are collapsed, not shown by default', (bool) preg_match('#<details class="dr-tech"><summary>Technical details</summary><p class="cell-sub">[^<]*OCR: tesseract \(eng\) · Model: demo-model · Attempts: 1 of 3#', $c)
    && !str_contains($c, '<details class="dr-tech" open'), $c);
$check('the full OCR text is never shown', !preg_match('/text_characters|ocr_text|full text/i', implode('', array_map($page, array_keys($cases)))));
$l = $page('legacy');
$check('a result from before version 3: nothing invented; each type check is Unable to verify (older check)', !str_contains($l, 'dc-name')
    && array_unique(array_column($checksOf('legacy'), 1)) === ['unable'] && str_contains($l, 'This check ran before printed titles were read from the text') && ($out['legacy']['state'] ?? '') === 'attention', $checksOf('legacy'));
$check('when the model did not answer, the server\'s reason is in Technical details, without the repeated not-an-approval sentence',
    str_contains($text('modelSilent'), 'Needs review: the AI model did not review the documents, so only the automatic checks ran.') && !str_contains($page('modelSilent'), 'This is not an approval'), $text('modelSilent'));
$check('the advisory notice is said once at the bottom; nothing claims authenticity', substr_count($c, 'It never approves or rejects a request') === 1 && str_contains($c, 'The original document is the source of truth.')
    && !preg_match('/\b(authentic|genuine)\b/i', (string) preg_replace('/cannot tell whether a document, signature or seal is genuine/i', '', $text('clean'))));
// ---- Escaping ----
$h = $page('htmlName');
$check('an extracted name is always HTML-escaped (and its symbol-bearing words marked as possibly misread)', !str_contains($h, '<img src=x')
    && str_contains($h, '<mark class="dc-unsure" title="May be misread">&lt;img</mark>') && str_contains($h, '<mark class="dc-unsure" title="May be misread">onerror=alert(1)&gt;</mark>'), $h);
// ---- Advisory only ----
foreach (array_merge(array_keys($cases), ['state_none', 'state_waiting', 'state_off']) as $name) {
    $p = (string) ($out[$name]['html'] ?? '');
    $check("no automatic approval or rejection control ({$name})", $p !== '' && !preg_match('/data-action="(approve|reject|update-status|start-processing|complete-processing)/', $p)
        && !str_contains($p, 'data-form="update-status"'));
}

// ---- Typography: no new font family; only sizes the page already uses ----
$css = $between($html, '  /* Document Review: per document', "\n  }\n  ");
preg_match_all('/font-family:([^;}]+)/', $css, $families);
preg_match_all('/font-size:([0-9.]+px)/', $css, $sizes);
$check('Document Review styles use the existing body font only and existing sizes (14/13.5/13px)', $css !== '' && array_unique($families[1]) === ['var(--font-body)']
    && array_diff(array_unique($sizes[1]), ['14px', '13.5px', '13px']) === [] && !preg_match('/font\s*:/', $css), [$families[1], $sizes[1]]);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
