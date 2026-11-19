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
    . $fn('dcIssueCountText') . $fn('dcIssueTitle') . $fn('dcImportantIssues') . $fn('aiFindingGuide')
    . 'var cases = ' . json_encode($cases, JSON_UNESCAPED_UNICODE) . ';'
    . <<<'JS'
var details = {firstName:'Juan', middleName:'Santos', lastName:'Dela Cruz', isOwner:true, arpNumber:'018-00045'};
var files = ['ownerId','ctcTdOrTitle','certNoImprovement'].map(function(k, i){ return {key:k, name:'demo-'+k+'.png', docId:100+i, available:true}; });
var out = {};
Object.keys(cases).forEach(function(name){
  var r = {id:'LT-DEMO-1', applicant:'Juan Dela Cruz', ai:cases[name], aiEligibility:{eligible:true}, details:details, files:files};
  out[name] = {html: renderDocCheckDetail({r:r, kind:'land', type:'Land Transfer: Sale', state:aiCheckState(r)}), issues: dcImportantIssues(cases[name]).issues.length,
    summary: aiFindingSummary(cases[name])};
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

// Structure
$c = $page('clean');
$check('result sections: identification + extracted information, then important issues', str_contains($c, 'Document Identification and Extracted Information') && str_contains($c, '>Important Issues<')
    && strpos($c, 'Document Identification') < strpos($c, 'Important Issues'), $text('clean'));
// Document type and ID type
$check('an ID shows its specific ID type, labelled as an unverified AI suggestion', str_contains($c, '<dt>ID type</dt><dd>Philippine Driver&#39;s License <span class="cell-sub">AI suggestion (unverified)</span>'), $c);
$check('a tax declaration and a certification are shown as documents, not IDs', str_contains($c, '<dt>Document</dt><dd>Tax Declaration ') && str_contains($c, '<dt>Document</dt><dd>Certification of No Improvement ')
    && substr_count($c, '<dt>ID type</dt>') === 1, $text('clean'));
$check('a clearance uploaded in place of a certification is named as a Barangay Clearance (not an ID)', str_contains($page('flood'), '<dt>Document</dt><dd>Barangay Clearance '));
// Exact names
$check('the ID holder\'s name is shown exactly as read (order, comma, middle name), marked as OCR text', str_contains($c, '<dt>Name on ID</dt><dd><span class="dc-name">DELA CRUZ, JUAN SANTOS</span><span class="cell-sub">Exactly as read by OCR</span>'), $c);
$check('a title or tax declaration names the registered owner; the property number is shown as read', str_contains($c, '<dt>Registered owner</dt><dd><span class="dc-name">JUAN DELA CRUZ</span>')
    && str_contains($c, '<dt>Property No.</dt><dd><span class="mono">018-00045</span>'));
$check('a matching name says Matches and raises no "Differs" note', !str_contains($c, 'Differs') && str_contains($c, '<span class="dc-compare"><span class="cell-sub">On the application:</span> Juan Santos Dela Cruz &middot; Matches</span>'), $text('clean'));
$m = $page('mismatch');
$check('a different name on the applicant\'s own ID: the ID\'s name and the application\'s name are shown apart, marked Differs', str_contains($m, '<span class="dc-name">REYES, MARIA LUZ</span>')
    && str_contains($m, '<span class="dc-compare"><span class="cell-sub">On the application:</span> Juan Santos Dela Cruz &middot; <strong>Differs, verify</strong>') && substr_count($m, 'Differs, verify') === 1, $text('mismatch'));
$check('the name issue is listed once although both the rule and the AI reported it, with the automatic check\'s label', substr_count($m, 'Name may not match the application') === 1
    && str_contains($m, 'Automatic comparison') && (int) ($out['mismatch']['issues'] ?? 0) === 1, $text('mismatch'));
// Uncertain names
$u = $page('unsure');
$check('an uncertain part of a name is marked for Staff, never corrected', str_contains($u, 'DELA <mark class="dc-unsure" title="May be misread">CRU2,</mark> JUAN SANTOS')
    && str_contains($u, 'Unclear: check the marked letters on the original') && !str_contains($u, 'DELA CRUZ, JUAN'), $u);
$check('a misread letter alone is not called a name mismatch; another person\'s name with a misread letter still is', !str_contains($u, 'Differs') && str_contains($u, 'Possibly the same name (spelling variant or OCR misread), verify')
    && str_contains($page('unsureOther'), 'Differs, verify') && str_contains($page('unsureOther'), '<mark class="dc-unsure" title="May be misread">MAR1A</mark>'), $text('unsure'));
$check('and is listed once as "Name may be misread" with what to check', substr_count($u, 'Name may be misread') === 1 && str_contains($u, 'check its spelling letter by letter'), $text('unsure'));
// Concise issues
$f = $page('flood');
$check('a flood of AI remarks is reduced to the important ones (each once)', (int) ($out['flood']['issues'] ?? 0) === 2
    && substr_count($f, 'Property number may not match the application') === 1 && substr_count($f, 'Only partly readable') === 1, $text('flood'));
$check('repeated "only Staff can judge signatures" points become one line naming the documents', substr_count($f, 'Signatures, seals and notarization can only be judged on the original') === 1
    && str_contains($text('flood'), 'on the original: Valid ID of Property Owner, Certified True Copy of Tax Declaration or Title.'));
$check('vague AI remarks without specifics are folded into one line, still marked unverified', str_contains($text('flood'), 'The AI also noted 2 other details without specifics (unverified).'));
$check('evidence quotes are labelled as OCR text', str_contains($f, '<q><span class="cell-sub">Read by OCR (may contain reading errors):</span> ARP No. 018-00099 Owner</q>'));
$check('the list page counts the same issues as the detail page', ($out['flood']['summary'] ?? null) === ['1 possible inconsistency', '1 reading problem', '2 for Staff to verify', '2 unspecific AI notes'], $out['flood']['summary'] ?? null);
$check('a name read from labelled parts says so; a possibly incomplete name says so', str_contains($page('partsName'), 'Read by OCR from labelled parts (such as Last Name, Given Names), in the document&#39;s order')
    && str_contains($page('incompleteName'), 'Read by OCR, possibly incomplete: more of a name appears next to it on the document') && !str_contains($page('incompleteName'), 'Exactly as read by OCR</span><span class="dc-compare">'));
// Name comparison (page only; the shown name never changes)
$check('name comparison: Philippine variants match (Ma. = Maria, dela/de la/delacruz, delos, del rosario)', array_slice($out['compare'] ?? [], 0, 6) === ['match', 'match', 'match', 'match', 'match', 'match'], $out['compare'] ?? null);
$check('name comparison: a Jr only one side has needs verification; Jr vs Sr differs', array_slice($out['compare'] ?? [], 6, 2) === ['verify', 'differs'], $out['compare'] ?? null);
$check('name comparison: OCR near-misses, a dropped particle and an initial need verification, not "differs"', array_slice($out['compare'] ?? [], 8, 4) === ['verify', 'verify', 'verify', 'verify'], $out['compare'] ?? null);
$check('name comparison: a misread letter never hides a different surname; short or different surnames differ', array_slice($out['compare'] ?? [], 12, 4) === ['differs', 'differs', 'differs', 'differs'], $out['compare'] ?? null);
$check('name comparison: too little to compare is said so', array_slice($out['compare'] ?? [], 16, 2) === ['unknown', 'unknown'], $out['compare'] ?? null);
// Summary states (headline, empty-list text and list page agree)
$vg = $page('vagueOnly');
$check('only vague AI notes: "Needs review" with "no specific issues detected; see notes below", never "no significant issues"',
    str_contains($vg, '<strong>Needs review</strong> &middot; no specific issues detected; see notes below') && str_contains($vg, 'No specific issues detected; see notes below. Please verify')
    && !str_contains($vg, 'no significant issues') && !str_contains($vg, 'No significant issues') && str_contains($text('vagueOnly'), 'The AI also noted 1 other detail without specifics (unverified).')
    && ($out['vagueOnly']['summary'] ?? null) === ['1 unspecific AI note'] && !str_contains($vg, 'possible inconsistency(ies)'), $text('vagueOnly'));
$check('when nothing else explains "Needs review" (the model did not answer), the server\'s reason is the note below, without the repeated not-an-approval sentence',
    str_contains($page('modelSilent'), 'no specific issues detected; see notes below') && str_contains($text('modelSilent'), 'Needs review: the AI model did not review the documents, so only the automatic checks ran.')
    && !str_contains($page('modelSilent'), 'This is not an approval') && ($out['modelSilent']['summary'] ?? null) === [], $text('modelSilent'));
$check('a passed result: "No concerns found · no significant issues"', str_contains($c, '<strong>No concerns found</strong> &middot; no significant issues') && ($out['clean']['summary'] ?? null) === []);
$check('important issues: the headline counts exactly the listed issues', str_contains($page('mismatch'), '&middot; 1 important issue</p>') && str_contains($page('flood'), '&middot; 2 important issues</p>'));
// Escaping, classification contradictions, unknown type
$h = $page('htmlName');
$check('an extracted name is always HTML-escaped (and its symbol-bearing words marked as possibly misread)', !str_contains($h, '<img')
    && str_contains($h, '<mark class="dc-unsure" title="May be misread">&lt;img</mark>') && str_contains($h, '<mark class="dc-unsure" title="May be misread">onerror=alert(1)&gt;</mark>'), $h);
$check('a Barangay Clearance in the ID slot: shown as a document, and listed once as "May not be the required document"', str_contains($page('clearanceInIdSlot'), '<dt>Document</dt><dd>Barangay Clearance ')
    && substr_count($page('clearanceInIdSlot'), 'May not be the required document') === 1, $text('clearanceInIdSlot'));
$check('an unknown document type is shown as "Cannot tell from the text", without a forced category', str_contains($page('unknownKind'), '<dt>Document</dt><dd>Cannot tell from the text ')
    && !str_contains($page('unknownKind'), 'May not be the required document'), $text('unknownKind'));
// No significant issues
$check('no significant issues: the agreed sentence, which does not claim authenticity', str_contains($c, 'No significant issues detected. Please verify the extracted details against the original document.')
    && !preg_match('/\b(authentic|genuine|verified)\b/i', (string) preg_replace('/cannot tell whether a document, signature or seal is genuine|unverified|not verified/i', '', $text('clean'))), $text('clean'));
$check('the disclaimer is said once in the results and once at the bottom, not on every line', substr_count($c, 'not a decision') === 1 && substr_count($c, 'It never approves or rejects a request') === 1
    && !str_contains($c, 'This is not an approval'), $text('clean'));
// Advisory only
foreach ($cases as $name => $_) {
    $p = $page($name);
    $check("no automatic approval or rejection control ({$name})", !preg_match('/data-action="(approve|reject|update-status|start-processing|complete-processing)/', $p)
        && !str_contains($p, 'data-form="update-status"') && str_contains($p, 'data-action="ai-mark-reviewed"'));
}
// Older results
$l = $page('legacy');
$check('a result from before this change still shows (type match only, no invented name)', str_contains($l, '<dt>Looks like this document?</dt><dd>AI suggestion (unverified): yes</dd>')
    && !str_contains($l, 'Name on ID') && !str_contains($l, 'dc-name'), $text('legacy'));
// Cards still open the originals
$check('every card opens its original through request-file.php', substr_count($c, '<a class="dc-doc-card" role="listitem" href="/request-file.php?doc=') === 3);

// ---- Typography: the new rules set no font family; only the size the rows already had ----
$css = $between($html, '  .dc-docs{', '  .dc-issues{');
$check('new Document Check styles add no font family and reuse the existing 13.5px row size only', $css !== '' && !preg_match('/font-family|font\s*:/', $css)
    && preg_match_all('/font-size:([0-9.]+px)/', $css, $sizes) === 1 && $sizes[1] === ['13.5px'], $css);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
