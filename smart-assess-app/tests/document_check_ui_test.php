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
/** One staff view, as staffViews() returns it, from a version 3 worker answer. */
$view = static function (array $docs, array $findings = [], array $readability = [], array $ruleFindings = [], int $version = 3) use ($labels, $keys): array {
    $checks = [];
    foreach ($keys as $key) {
        $holder = '';
        foreach ($docs as $d) {
            if ($d['key'] === $key) {
                $holder = $d['holder_name'] ?? '';
            }
        }
        $checks[] = ['key' => $key, 'ocr_status' => 'ok', 'ocr_error' => null, 'readability' => $readability[$key] ?? 'good', 'pages' => 1, 'method' => 'image_ocr',
            'text_characters' => 400] + ($version >= 3 ? ['name_match' => $holder === '' ? 'none' : 'exact'] : []);
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
    . $between($html, 'var AI_CHECK_STATES = {', 'function aiCheckState(r){')
    . $fn('aiCheckState') . $fn('aiFindingCounts') . $fn('aiFindingSummary') . $fn('aiDurationText') . $fn('renderDocCheckDetail') . $fn('docCheckDisclaimer')
    . $fn('dcNameHtml') . $between($html, 'var DC_DIGIT_LETTERS', 'function dcNameParts(') . $fn('dcNameParts') . $fn('dcEditDistance') . $fn('dcNameCompare')
    . $fn('dcImportantIssues')
    . $between($html, 'var DR_ISSUES = {', "\n};\n") . "\n};\n" . $fn('drIssueFor') . $fn('drIssueItem') . $fn('drSummaryText') . $fn('drDocCard')
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
  out[name] = {html: renderDocCheckDetail({r:r, kind:'land', type:'Land Transfer: Sale', state:aiCheckState(r)}), issues: dcImportantIssues(cases[name]).issues.length,
    summary: aiFindingSummary(cases[name])};
});
// No automatic result yet: the originals are still shown for review.
['none', 'waiting', 'off'].forEach(function(st){
  var files = mkFiles(); files[2].name = ''; files[2].status = 'missing';
  var r = {id:'LT-DEMO-1', applicant:'Juan Dela Cruz', ai: st==='waiting' ? {status:'pending', attempts:0, maxAttempts:3} : null,
    aiEligibility: st==='off' ? {eligible:false, reason:'not_enabled'} : {eligible:true}, details:details, files:files};
  out['state_'+st] = {html: renderDocCheckDetail({r:r, kind:'land', type:'Land Transfer: Sale', state:aiCheckState(r)})};
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

// ---- Structure: the original first, then its summary, then its problems ----
$c = $page('clean');
$card = static function (string $html, int $n): string {
    $parts = explode('<article class="dr-doc', $html);
    return (string) ($parts[$n + 1] ?? '');
};
$check('the page is titled Document Review and shows one card per uploaded document', str_contains($c, '<h1>Document Review</h1>') && substr_count($c, '<article class="dr-doc') === 3, $text('clean'));
$check('each card shows the document category (what was required) and the file name and type', str_contains($card($c, 0), '<h3>Valid ID of Property Owner</h3>')
    && str_contains($card($c, 0), 'demo-ownerId.png · PNG image · 1 page · Text: Readable') && str_contains($card($c, 1), 'demo-ctcTdOrTitle.pdf · PDF document'), $card($c, 0));
$order = $card($c, 0);
$check('in each card the preview comes first, then the summary, then problems', strpos($order, 'dr-preview') < strpos($order, '<dt>Document type</dt>')
    && strpos($order, '<dt>Document type</dt>') < strpos($order, 'class="dr-problems"'), $order);
// ---- Secure previews ----
$check('an image is previewed inline from request-file.php (doc ID + reference), lazily, and opens full size in a new tab',
    str_contains($card($c, 0), '<a class="dr-preview" href="/request-file.php?doc=100&amp;ref=LT-DEMO-1" target="_blank" rel="noopener" title="Open full size in a new tab"><img class="dr-img" src="/request-file.php?doc=100&amp;ref=LT-DEMO-1&amp;preview=1" alt="Valid ID of Property Owner (uploaded file)" loading="lazy"></a>'), $card($c, 0));
$check('a PDF is previewed in an iframe from request-file.php', str_contains($card($c, 1), '<iframe class="dr-pdf" src="/request-file.php?doc=101&amp;ref=LT-DEMO-1&amp;preview=1" title="Certified True Copy of Tax Declaration or Title (uploaded PDF)" loading="lazy"></iframe>'), $card($c, 1));
preg_match_all('/(?:href|src)="([^"]*)"/', implode('', array_map($page, array_keys($cases))) . $page('state_none'), $urls);
$check('every link and preview on the page goes through request-file.php (no public upload URL, no storage path)', $urls[1] !== []
    && array_filter($urls[1], static fn (string $u): bool => !str_starts_with($u, '/request-file.php?doc=')) === [] && !str_contains(implode('', $urls[1]), 'uploads'), array_unique($urls[1]));
$check('a file that cannot be served shows why and no preview or link', str_contains($card($page('unsure'), 1), 'Cannot be opened: missing from storage or an unsupported file type.')
    && !str_contains($card($page('unsure'), 1), 'request-file.php'), $card($page('unsure'), 1));
// Mark as reviewed: inline previews are not recorded (preview=1); Open and the full-size link are.
$check('only the inline preview carries preview=1; Open and the full-size link open the original as before (recorded)',
    substr_count($card($c, 0), 'preview=1') === 1 && str_contains($card($c, 0), '<a class="btn btn-outline btn-sm" href="/request-file.php?doc=100&amp;ref=LT-DEMO-1" target="_blank" rel="noopener">Open')
    && str_contains($card($c, 0), '<a class="dr-preview" href="/request-file.php?doc=100&amp;ref=LT-DEMO-1" ') && substr_count($card($c, 1), 'preview=1') === 1, $card($c, 0));
$check('the review note asks Staff to open every original, as before', str_contains($c, 'Open every original that can be opened before marking as reviewed; the server checks that you have. Opening is recorded, not proof of inspection.')
    && !str_contains($c, 'previewing a file here is recorded'), $text('clean'));
$nu = $card($page('notUploaded'), 2);
$check('a document that was not uploaded: "Not uploaded" instead of a preview, listed once with a follow-up', str_contains($nu, '<div class="dr-preview is-off">Not uploaded</div>')
    && substr_count($nu, '<strong>Not uploaded</strong>') === 1 && str_contains($nu, 'Follow-up: Ask the client to upload it.') && !str_contains($nu, 'request-file.php'), $nu);
foreach (['none' => 'Not checked yet', 'waiting' => 'Waiting to be checked', 'off' => 'Automatic check not enabled'] as $st => $label) {
    $sp = (string) ($out['state_' . $st]['html'] ?? '');
    $check("without an automatic result ({$st}) the originals are still shown for review, with no invented summary", str_contains($sp, $label)
        && substr_count($sp, 'class="dr-img"') === 1 && substr_count($sp, 'class="dr-pdf"') === 1 && str_contains($sp, 'No automatic summary. Review the original.')
        && str_contains($sp, 'The client did not upload this document.') && !str_contains($sp, '<dt>'), $sp);
}
// ---- Summary: only what was read ----
$check('an ID: type (suggested, not verified), the name exactly as read, and a one-line summary', str_contains($card($c, 0), '<dt>Document type</dt><dd>Philippine Driver&#39;s License <span class="cell-sub">Suggested from the text, not verified</span>')
    && str_contains($card($c, 0), '<dt>Name read</dt><dd><span class="dc-name">DELA CRUZ, JUAN SANTOS</span><span class="cell-sub">Exactly as read</span>')
    && str_contains($card($c, 0), '<dt>Summary</dt><dd>Contains the holder&#39;s name.</dd>'), $card($c, 0));
$check('a tax declaration: owner name and property number as read, and a summary naming only what was read', str_contains($card($c, 1), '<dt>Document type</dt><dd>Tax Declaration ')
    && str_contains($card($c, 1), '<dt>Owner name read</dt><dd><span class="dc-name">JUAN DELA CRUZ</span>') && str_contains($card($c, 1), '<dt>Property number</dt><dd><span class="mono">018-00045</span>')
    && str_contains($card($c, 1), '<dt>Summary</dt><dd>Contains the registered owner&#39;s name and a property number.</dd>'), $card($c, 1));
$check('a certification and a clearance are documents, not IDs', str_contains($card($c, 2), '<dt>Document type</dt><dd>Certification of No Improvement ')
    && str_contains($card($page('flood'), 2), '<dt>Document type</dt><dd>Barangay Clearance ') && !str_contains($card($c, 2), 'holder'), $card($c, 2));
$ur = $card($page('unreadableId'), 0);
$check('an unreadable ID: no name or type invented, "Not read" said, and flagged once as possibly unreadable', !str_contains($ur, 'dc-name')
    && str_contains($ur, '<dt>Name read</dt><dd><span class="cell-sub">Not read. Check the name on the original.</span>') && str_contains($ur, 'What this document is could not be told from its text.')
    && substr_count($ur, '<strong>Possibly unreadable</strong>') === 1 && str_contains($ur, 'Text: Could not be read'), $ur);
$check('an unknown document type is shown as "Cannot tell from the text", without a forced category', str_contains($card($page('unknownKind'), 0), '<dt>Document type</dt><dd>Cannot tell from the text ')
    && !str_contains($page('unknownKind'), 'Document may be incorrect'), $text('unknownKind'));
// ---- Names: never corrected, uncertain parts marked, compared only on the applicant's own ID ----
$check('a matching name says Matches and raises no problem', str_contains($card($c, 0), '<span class="dc-compare"><span class="cell-sub">Application:</span> Juan Santos Dela Cruz &middot; Matches</span>')
    && !str_contains($c, 'Differs') && !str_contains($c, 'dr-issue'), $text('clean'));
$m = $page('mismatch');
$check('a different name on the applicant\'s own ID: both names shown apart, marked Differs, one "Name differs" problem with what to verify', str_contains($m, '<span class="dc-name">REYES, MARIA LUZ</span>')
    && str_contains($m, 'Application:</span> Juan Santos Dela Cruz &middot; <strong>Differs, verify</strong>') && substr_count($m, '<strong>Name differs</strong>') === 1
    && str_contains($m, 'Compare it with the original.') && str_contains($m, 'Follow-up: If the names really differ'), $text('mismatch'));
$check('the name problem is shown once although the rule, the AI and the comparison all found it', substr_count($m, 'class="dr-issue"') === 1 && (int) ($out['mismatch']['issues'] ?? 0) === 1, $text('mismatch'));
$u = $page('unsure');
$check('an uncertain part of a name is marked for Staff, never corrected', str_contains($u, 'DELA <mark class="dc-unsure" title="May be misread">CRU2,</mark> JUAN SANTOS')
    && str_contains($u, 'Unclear: check the marked letters on the original') && !str_contains($u, 'DELA CRUZ, JUAN'), $u);
$check('a misread letter alone is not a mismatch ("Name possibly misread"); another person\'s name with a misread letter still differs', !str_contains($u, 'Differs')
    && str_contains($u, '<strong>Possibly the same name, verify</strong>') && substr_count($u, '<strong>Name possibly misread</strong>') === 1
    && str_contains($page('unsureOther'), 'Differs, verify') && str_contains($page('unsureOther'), '<mark class="dc-unsure" title="May be misread">MAR1A</mark>'), $text('unsure'));
$check('the application\'s name is never put in place of the name read', !str_contains($m, '<span class="dc-name">Juan') && !str_contains($u, '<span class="dc-name">Juan'));
$check('names on other documents are shown but not compared with the application', !str_contains($card($c, 1), 'Application:') && !str_contains($card($c, 2), 'Application:'));
$check('a name read from labelled parts says so; a possibly incomplete name says so and is flagged', str_contains($page('partsName'), 'Read from labelled parts (such as Last Name, Given Names), in the document&#39;s order')
    && str_contains($page('incompleteName'), 'Possibly incomplete: more of a name appears next to it') && !str_contains($card($page('incompleteName'), 0), '<span class="cell-sub">Exactly as read</span>'));
// ---- Property numbers ----
$f = $page('flood');
$check('a different property number stays visible: "Property number differs", once, with the short OCR quote', substr_count($f, '<strong>Property number differs</strong>') === 1
    && str_contains($f, 'Text read (may contain reading errors): &ldquo;ARP No. 018-00099 Owner&rdquo;'), $text('flood'));
$no = $page('numberOnly');
$check('a property number that differs from the application is flagged by the page even without a server finding', substr_count($no, '<strong>Property number differs</strong>') === 1
    && str_contains($card($no, 1), '<span class="mono">018-00099</span>') && str_contains($card($no, 1), 'Application:</span> <span class="mono">018-00045</span> &middot; <strong>Differs, verify</strong>')
    && str_contains($no, '<strong>1 problem to check</strong>.'), $card($no, 1));
// ---- Concise findings ----
$check('a flood of model remarks becomes two short problems; vague remarks and signature points move to Technical details', substr_count($f, 'class="dr-issue"') === 2
    && substr_count($f, '<strong>Possibly unreadable</strong>') === 1 && str_contains($f, '<details class="dr-tech"><summary>Technical details</summary>')
    && str_contains($text('flood'), 'The model also noted 2 other details without saying what (not shown as problems).')
    && substr_count($f, 'Signatures, seals and notarization can only be judged on the original') === 1, $text('flood'));
$check('technical details (OCR engine, model, attempts) are collapsed, not shown by default', (bool) preg_match('#<details class="dr-tech"><summary>Technical details</summary><p class="cell-sub">[^<]*OCR: tesseract \(eng\) · Model: demo-model · Attempts: 1 of 3#', $c)
    && !str_contains($c, '<details class="dr-tech" open'), $c);
$check('the full OCR text is never shown', !preg_match('/text_characters|ocr_text|full text/i', implode('', array_map($page, array_keys($cases)))));
$check('every problem has a title and what to verify; follow-ups only where useful', (bool) preg_match_all('#<li class="dr-issue"><strong>[^<]+</strong><span>[^<]+</span>#', $f . $m . $u, $items) && count($items[0]) === substr_count($f . $m . $u, 'class="dr-issue"'));
$check('the list page counts the same server issues as before', ($out['flood']['summary'] ?? null) === ['1 possible inconsistency', '1 reading problem', '2 for Staff to verify', '2 unspecific AI notes'], $out['flood']['summary'] ?? null);
$cl = $page('clearanceInIdSlot');
$check('a Barangay Clearance in the ID slot: shown as a document and listed once as "Document may be incorrect"', str_contains($cl, '<dt>Document type</dt><dd>Barangay Clearance ')
    && substr_count($cl, '<strong>Document may be incorrect</strong>') === 1, $text('clearanceInIdSlot'));
// ---- Headline states ----
$check('no issues: "No significant issue detected" with the verify-against-the-original sentence, and no authenticity claim',
    str_contains($c, '<strong>No significant issue detected</strong>. No specific issue was detected in the available text. Please verify the extracted details against the original document.')
    && substr_count($c, 'No significant issue detected in the available text.') === 3
    && !preg_match('/\b(authentic|genuine|verified)\b/i', (string) preg_replace('/cannot tell whether a document, signature or seal is genuine|not verified/i', '', $text('clean'))), $text('clean'));
$check('the headline counts exactly the problems listed', str_contains($m, '<strong>1 problem to check</strong>.') && str_contains($f, '<strong>2 problems to check</strong>.'), $text('flood'));
$vg = $page('vagueOnly');
$check('only vague model notes: never "No significant issue"; the note is in Technical details', str_contains($vg, '<strong>No specific issue detected</strong>')
    && !str_contains($vg, 'No significant issue detected</strong>') && str_contains($text('vagueOnly'), 'The model also noted 1 other detail without saying what'), $text('vagueOnly'));
$check('when nothing else explains "Needs review" (the model did not answer), the server\'s reason is shown, without the repeated not-an-approval sentence',
    str_contains($text('modelSilent'), 'Needs review: the AI model did not review the documents, so only the automatic checks ran.') && !str_contains($page('modelSilent'), 'This is not an approval'), $text('modelSilent'));
$check('the advisory notice is said once at the bottom', substr_count($c, 'It never approves or rejects a request') === 1 && str_contains($c, 'The original document is the source of truth.'));
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
// ---- Older results ----
$l = $page('legacy');
$check('a result from before version 3 still shows (type match only, no invented name or summary)', str_contains($l, '<dt>Looks like the required document?</dt><dd>Yes <span class="cell-sub">Suggested from the text, not verified</span>')
    && !str_contains($l, 'Name read') && !str_contains($l, 'dc-name') && !str_contains($l, '<dt>Summary</dt>'), $text('legacy'));

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
