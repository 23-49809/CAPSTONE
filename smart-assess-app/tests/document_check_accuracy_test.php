<?php
declare(strict_types=1);

/*
 * Document Check accuracy by request type (no database, no network, fictional demo data). Worker
 * results (version 4) go through the real DocumentAnalysis::validateWorkerResult() and classify(),
 * then the page's own check functions decide each verdict, rendered in Node.
 *
 *  - Document Request: only Name Match and ID Type, on the ID(s). The name read from the ID is
 *    compared with the submitted name (never substituted); a representative's own ID is the one
 *    compared; the owner's ID name is not compared when the owner's name was not submitted.
 *  - Land Transfer: each of the five required documents is identified from its printed title in the
 *    file's own text (Tax Declaration OR Title; Deed of Sale OR Donation): Correct, Missing, Possible
 *    mismatch or Unable to verify. A file name, an unreadable file or a suggestion alone never passes.
 *
 *   php tests/document_check_accuracy_test.php
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

$allLabels = ['ownerId' => 'Valid Government-Issued ID', 'requesterId' => 'Valid ID of Requester', 'authLetter' => 'Authorization Letter',
    'ctcTdOrTitle' => 'Certified True Copy of Tax Declaration or Title', 'notarialDeed' => 'Notarial Deed of Sale or Donation', 'vicinityMap' => 'Vicinity Map',
    'certNoImprovement' => 'Certification of No Improvement', 'taxClearance' => 'Tax Clearance'];
/**
 * A finished check as staffViews() returns it. $docs: key => [kind, holder name] from the model (null kind = the
 * model gave nothing for it); $evidence: key => printed titles found in that file's text; $missing: keys not uploaded.
 */
$view = static function (array $keys, array $docs, array $evidence = [], array $readability = [], array $missing = [], int $version = 4, bool $model = true) use ($allLabels): array {
    $labels = array_intersect_key($allLabels, array_flip($keys));
    $sent = array_values(array_diff($keys, $missing));
    $checks = [];
    foreach ($sent as $key) {
        $holder = $docs[$key][1] ?? '';
        $checks[] = ['key' => $key, 'ocr_status' => ($readability[$key] ?? 'good') === 'failed' ? 'failed' : 'ok', 'ocr_error' => ($readability[$key] ?? '') === 'failed' ? 'ocr_failed' : null,
            'readability' => ($readability[$key] ?? 'good') === 'failed' ? 'unreadable' : ($readability[$key] ?? 'good'), 'pages' => 1, 'method' => 'image_ocr', 'text_characters' => 300]
            + ($version >= 3 ? ['name_match' => $holder === '' ? 'none' : 'exact'] : [])
            + ($version >= 4 ? ['kind_evidence' => $evidence[$key] ?? []] : []);
    }
    $modelDocs = array_map(static fn (string $key): array => ['key' => $key, 'document_kind' => $docs[$key][0] ?? 'unclear', 'holder_name' => $docs[$key][1] ?? '',
        'holder_name_clear' => true, 'property_number' => '', 'expected_type_match' => 'unclear', 'missing_fields' => []], $sent);
    $worker = DocumentAnalysis::validateWorkerResult(['version' => $version, 'documents' => $checks, 'rule_findings' => [],
        'model' => $model ? ['status' => 'ok', 'name' => 'demo-model', 'digest' => null, 'output' => ['documents' => $modelDocs, 'findings' => [], 'manual_review_recommended' => false]]
            : ['status' => 'skipped', 'name' => 'demo-model', 'digest' => null, 'output' => null], 'ocr_engine' => 'tesseract (eng)'], $sent);
    $requirements = ['items' => array_map(static fn (string $k): array => ['key' => $k, 'label' => $labels[$k], 'status' => in_array($k, $missing, true) ? 'missing' : 'uploaded_valid_format'], $keys)];
    $result = DocumentAnalysis::classify($requirements, $worker, $labels);
    return ['status' => 'completed', 'outcome' => $result['outcome'], 'reason' => $result['reason'], 'findings' => [], 'documents' => $result['documents'],
        'model' => 'demo-model', 'ocrEngine' => 'tesseract (eng)', 'attempts' => 1, 'maxAttempts' => 3, 'error' => null, 'reviewed' => false];
};
$owner = ['firstName' => 'Juan', 'middleName' => 'Santos', 'lastName' => 'Dela Cruz', 'isOwner' => true, 'arpNumber' => '14-0001-00045'];
$rep = ['firstName' => 'Maria', 'middleName' => '', 'lastName' => 'Reyes', 'isOwner' => false, 'arpNumber' => '14-0001-00045'];
$deedText = "DEED OF ABSOLUTE SALE\nKNOW ALL MEN BY THESE PRESENTS:\nThat the parcel covered by Transfer Certificate of Title No. T-12345 of the Registry of Deeds of Batangas\nand declared under Tax Declaration No. 018-00045 is sold";
$lostHeading = "KNOW ALL MEN BY THESE PRESENTS:\nThat I, JUAN DELA CRUZ, the VENDOR, hereby sell, transfer and convey to MARIA REYES, the VENDEE,\nthe parcel described as follows:\nTransfer Certificate of Title No. T-12345\nRegistry of Deeds of Batangas\nIN WITNESS WHEREOF";
$strayTitleLine = "That the parcel is described as follows:\nTransfer Certificate of Title No. T-12345\nRegistry of Deeds for the Province of Batangas\nLot 12, Poblacion";
$genuineTitle = "Republic of the Philippines\nRegistry of Deeds for the Province of Batangas\nTRANSFER CERTIFICATE OF TITLE\nNo. T-12345\nIT IS HEREBY CERTIFIED that certain land situated in Mabini\nMEMORANDUM OF ENCUMBRANCES\nEntry 99: Deed of Absolute Sale in favor of the vendee";
$ltKeys = ['ctcTdOrTitle', 'notarialDeed', 'vicinityMap', 'certNoImprovement', 'taxClearance', 'ownerId'];
$ltAll = ['ctcTdOrTitle' => ['tax_declaration', ''], 'notarialDeed' => ['deed_of_sale', ''], 'vicinityMap' => ['vicinity_map', ''],
    'certNoImprovement' => ['certification_no_improvement', ''], 'taxClearance' => ['tax_clearance', ''], 'ownerId' => ['drivers_license', 'DELA CRUZ, JUAN SANTOS']];
$ltEvidence = ['ctcTdOrTitle' => ['tax_declaration'], 'notarialDeed' => ['deed_of_sale'], 'vicinityMap' => ['vicinity_map'],
    'certNoImprovement' => ['certification_no_improvement'], 'taxClearance' => ['tax_clearance'], 'ownerId' => ['drivers_license']];
$cases = [
    // Document Request
    'drCorrect' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['drivers_license', 'DELA CRUZ, JUAN SANTOS']], ['ownerId' => ['drivers_license']])],
    'drMismatch' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['passport', 'REYES, MARIA LUZ']], ['ownerId' => ['passport']])],
    'drNameUnread' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['umid', '']], ['ownerId' => ['umid']])],
    'drTextUnreadable' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['unclear', '']], [], ['ownerId' => 'unreadable'])],
    'drOcrFailed' => ['doc', $owner, $view(['ownerId'], [], [], ['ownerId' => 'failed'])],
    'drTypeUnknown' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['other_id', 'DELA CRUZ, JUAN SANTOS']])],
    'drTypeUnconfirmed' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['drivers_license', 'DELA CRUZ, JUAN SANTOS']], ['ownerId' => []])],
    'drSchoolId' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['school_or_company_id', 'DELA CRUZ, JUAN SANTOS']])],
    'drNotAnId' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['barangay_clearance', 'JUAN DELA CRUZ']], ['ownerId' => ['barangay_clearance']])],
    'drIdMissing' => ['doc', $owner, $view(['ownerId'], [], [], [], ['ownerId'])],
    'drRepresentative' => ['doc', $rep, $view(['requesterId', 'ownerId', 'authLetter'], ['requesterId' => ['philsys_national_id', 'REYES, MARIA'],
        'ownerId' => ['drivers_license', 'DELA CRUZ, JUAN SANTOS'], 'authLetter' => ['authorization_letter', '']],
        ['requesterId' => ['philsys_national_id'], 'ownerId' => ['drivers_license']])],
    'drOlderResult' => ['doc', $owner, $view(['ownerId'], ['ownerId' => ['drivers_license', 'DELA CRUZ, JUAN SANTOS']], [], [], [], 3)],
    'drModelDown' => ['doc', $owner, $view(['ownerId'], [], ['ownerId' => ['drivers_license']], [], [], 4, false)],
    // Land Transfer
    'ltAllCorrect' => ['land', $owner, $view($ltKeys, $ltAll, $ltEvidence)],
    'ltTitleInsteadOfTd' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['transfer_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => ['transfer_certificate_of_title']] + $ltEvidence)],
    'ltOctInsteadOfTd' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['original_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => ['original_certificate_of_title']] + $ltEvidence)],
    'ltDonation' => ['land', $owner, $view($ltKeys, ['notarialDeed' => ['deed_of_donation', '']] + $ltAll, ['notarialDeed' => ['deed_of_donation']] + $ltEvidence)],
    'ltMissingMap' => ['land', $owner, $view($ltKeys, $ltAll, $ltEvidence, [], ['vicinityMap'])],
    'ltWrongDoc' => ['land', $owner, $view($ltKeys, ['vicinityMap' => ['tax_clearance', '']] + $ltAll, ['vicinityMap' => ['tax_clearance']] + $ltEvidence)],
    'ltUnreadable' => ['land', $owner, $view($ltKeys, ['taxClearance' => ['unclear', '']] + $ltAll, ['taxClearance' => []] + $ltEvidence, ['taxClearance' => 'poor'])],
    'ltAmbiguous' => ['land', $owner, $view($ltKeys, ['certNoImprovement' => ['unclear', '']] + $ltAll, ['certNoImprovement' => []] + $ltEvidence)],
    'ltTwoTitles' => ['land', $owner, $view($ltKeys, ['certNoImprovement' => ['unclear', '']] + $ltAll, ['certNoImprovement' => ['certification_no_improvement', 'tax_clearance']] + $ltEvidence)],
    // The file is named as the required document, and the model even suggests it, but its text shows no such title.
    'ltNameOnly' => ['land', $owner, $view($ltKeys, $ltAll, ['ctcTdOrTitle' => []] + $ltEvidence)],
    // A deed uploaded where the tax declaration goes. Its evidence is computed from a realistic deed text that
    // mentions a title number and a Tax Declaration number (only the deed's own heading counts).
    'ltDeedInTdSlot' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['deed_of_sale', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($deedText)] + $ltEvidence)],
    'ltDeedSuggestedTitle' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['transfer_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($deedText)] + $ltEvidence)],
    'ltDeedSuggestedTd' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['tax_declaration', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($deedText)] + $ltEvidence)],
    // Conflicting headings in one file: never Correct, even with a matching suggestion.
    'ltConflict' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['transfer_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => ['transfer_certificate_of_title', 'deed_of_sale']] + $ltEvidence)],
    // The deed whose heading OCR lost (standalone title-number line + Registry of Deeds), every reading.
    'ltLostHeadingAsTitle' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['transfer_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($lostHeading)] + $ltEvidence)],
    'ltLostHeadingUnsure' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['unclear', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($lostHeading)] + $ltEvidence)],
    'ltLostHeadingAsTd' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['tax_declaration', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($lostHeading)] + $ltEvidence)],
    'ltStrayTitleLine' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['transfer_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($strayTitleLine)] + $ltEvidence)],
    'ltLostHeadingInDeedSlot' => ['land', $owner, $view($ltKeys, ['notarialDeed' => ['deed_of_sale', '']] + $ltAll, ['notarialDeed' => DocumentAnalysis::kindEvidence($lostHeading)] + $ltEvidence)],
    'ltGenuineTitle' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['transfer_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($genuineTitle)] + $ltEvidence)],
    'ltTitleWithDeedWording' => ['land', $owner, $view($ltKeys, ['ctcTdOrTitle' => ['transfer_certificate_of_title', '']] + $ltAll, ['ctcTdOrTitle' => DocumentAnalysis::kindEvidence($genuineTitle . "\nKNOW ALL MEN BY THESE PRESENTS")] + $ltEvidence)],
    'ltDeedWithHeadingAndWording' => ['land', $owner, $view($ltKeys, $ltAll, ['notarialDeed' => DocumentAnalysis::kindEvidence($deedText)] + $ltEvidence)],
    // Every file suggested as the right document, but no heading found in any text.
    'ltSuggestionsOnly' => ['land', $owner, $view($ltKeys, $ltAll, array_fill_keys($ltKeys, []))],
    'ltTitleFoundModelUnsure' => ['land', $owner, $view($ltKeys, ['vicinityMap' => ['other_document', '']] + $ltAll, ['vicinityMap' => ['vicinity_map']] + $ltEvidence)],
    'ltOlderResult' => ['land', $owner, $view($ltKeys, $ltAll, [], [], [], 3)],
];

$script = 'function esc(s){ return String(s==null?"":s).replace(/[&<>"\']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];}); }'
    . 'function ic(){ return ""; } function fmtDateTime(s){ return String(s); }'
    . $between($html, 'var AI_CHECK_STATES = {', 'function aiCheckState(r, kind){')
    . $fn('aiCheckState') . $fn('aiDurationText') . $fn('renderDocCheckDetail') . $fn('docCheckDisclaimer')
    . $fn('dcNameHtml') . $between($html, 'var DC_DIGIT_LETTERS', 'function dcNameParts(') . $fn('dcNameParts') . $fn('dcEditDistance') . $fn('dcNameCompare') . $fn('dcImportantIssues')
    . $between($html, 'var DC_VERDICT = {', 'function dcContext(r){') . $fn('dcContext') . $fn('dcUnread') . $fn('dcCheck') . $fn('dcKindCheck') . $fn('dcNameCheck')
    . $between($html, 'var DC_LAND_DOCUMENTS', 'function dcDocChecks(') . $fn('dcDocChecks') . $fn('dcAllPassed') . $fn('dcRequestChecks') . $fn('dcChecksHtml') . $fn('drDocCard')
    . 'var cases = ' . json_encode($cases, JSON_UNESCAPED_UNICODE) . ';'
    . <<<'JS'
var out = {};
Object.keys(cases).forEach(function(name){
  var kind = cases[name][0], details = cases[name][1], ai = cases[name][2];
  var files = (ai.documents||[]).map(function(d, i){
    // File names deliberately named after the required document: they must never count as evidence.
    return {key:d.key, label:d.label, name: d.requirement==='missing' ? '' : d.label+'.png', mime:'image/png', docId:200+i, available:true, status: d.requirement==='missing' ? 'missing' : 'uploaded'};
  });
  var r = {id:(kind==='doc'?'DR':'LT')+'-2026-DEMO', applicant:'Fictional', ai:ai, aiEligibility:{eligible:true}, details:details, files:files};
  var checks = [];
  (ai.documents||[]).forEach(function(d){ dcDocChecks(d, dcContext(r), kind).forEach(function(c){ checks.push([d.key, c.name, c.verdict, c.detected, c.reason]); }); });
  out[name] = {checks:checks, state:aiCheckState(r, kind), html:renderDocCheckDetail({r:r, kind:kind, type:'Demo', state:aiCheckState(r, kind)})};
});
// A Document Request never gets a Land Transfer document check, even if such a document appeared in its result.
var drWithDeed = JSON.parse(JSON.stringify(cases.ltAllCorrect[2]));
out.drNeverLand = dcRequestChecks({ai:drWithDeed, details:cases.drCorrect[1]}, 'doc').map(function(c){ return c.name; });
process.stdout.write(JSON.stringify(out));
JS;
$jsFile = tempnam(sys_get_temp_dir(), 'sa-dca-') . '.js';
file_put_contents($jsFile, $script);
$node = trim((string) shell_exec('command -v node'));
$check('node is available', $node !== '');
$raw = $node !== '' ? (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($jsFile) . ' 2>&1') : '';
@unlink($jsFile);
$out = json_decode($raw, true);
$check('the page functions run on the demo results', is_array($out), $raw);
$out = is_array($out) ? $out : [];
/** [check name => verdict] for one document of one case. */
$v = static function (string $case, string $key) use ($out): array {
    $r = [];
    foreach ($out[$case]['checks'] ?? [] as [$k, $name, $verdict]) {
        if ($k === $key) {
            $r[$name] = $verdict;
        }
    }
    return $r;
};
$reason = static function (string $case, string $key, string $name) use ($out): string {
    foreach ($out[$case]['checks'] ?? [] as [$k, $n, , $detected, $why]) {
        if ($k === $key && $n === $name) {
            return $detected . ' | ' . $why;
        }
    }
    return '';
};
$page = static fn (string $case): string => (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($out[$case]['html'] ?? '')), ENT_QUOTES));

// ================= Document Request =================
$check('DR: matching name on a recognised government ID: Name Match Correct and ID Type Correct', $v('drCorrect', 'ownerId') === ['Name Match' => 'correct', 'ID Type' => 'correct'], $out['drCorrect']['checks'] ?? null);
$check('DR: the page says explicitly that both checks passed', str_contains($page('drCorrect'), 'All checks passed: Name Match and ID Type are correct.') && ($out['drCorrect']['state'] ?? '') === 'clear', $page('drCorrect'));
$check('DR: the correct verdicts show the detected name and ID type', str_contains($reason('drCorrect', 'ownerId', 'Name Match'), 'Read from the ID: DELA CRUZ, JUAN SANTOS · Submitted: Juan Santos Dela Cruz')
    && str_contains($reason('drCorrect', 'ownerId', 'ID Type'), "Philippine Driver's License |"), $out['drCorrect']['checks'] ?? null);
$check('DR: only Name Match and ID Type are checked (no other check appears)', array_unique(array_column($out['drCorrect']['checks'] ?? [], 1)) === ['Name Match', 'ID Type']
    && !str_contains($page('drCorrect'), 'Document identification'));
$check('DR: a different name is a Mismatch, showing both names', $v('drMismatch', 'ownerId')['Name Match'] === 'mismatch'
    && str_contains($reason('drMismatch', 'ownerId', 'Name Match'), 'Read from the ID: REYES, MARIA LUZ · Submitted: Juan Santos Dela Cruz | The name read from the ID does not match')
    && ($out['drMismatch']['state'] ?? '') === 'attention', $out['drMismatch']['checks'] ?? null);
$check('DR: no name read from a readable ID: Unable to verify, not assumed to match', $v('drNameUnread', 'ownerId')['Name Match'] === 'unable'
    && str_contains($reason('drNameUnread', 'ownerId', 'Name Match'), 'It is not assumed to match.'), $out['drNameUnread']['checks'] ?? null);
$check('DR: unreadable text or failed OCR: both checks Unable to verify, never a pass', $v('drTextUnreadable', 'ownerId') === ['Name Match' => 'unable', 'ID Type' => 'unable']
    && $v('drOcrFailed', 'ownerId') === ['Name Match' => 'unable', 'ID Type' => 'unable'], [$out['drTextUnreadable']['checks'] ?? null, $out['drOcrFailed']['checks'] ?? null]);
$check('DR: an unidentified ID type ("other ID") is Unable to verify', $v('drTypeUnknown', 'ownerId')['ID Type'] === 'unable'
    && str_contains($reason('drTypeUnknown', 'ownerId', 'ID Type'), 'could not be identified'), $out['drTypeUnknown']['checks'] ?? null);
$check('DR: an ID type suggested without its title or issuer in the text is Unable to verify (a suggestion is not a verification)', $v('drTypeUnconfirmed', 'ownerId')['ID Type'] === 'unable'
    && str_contains($reason('drTypeUnconfirmed', 'ownerId', 'ID Type'), 'was not found in the text'), $out['drTypeUnconfirmed']['checks'] ?? null);
$check('DR: a school or company ID is a Possible mismatch (not government-issued)', $v('drSchoolId', 'ownerId')['ID Type'] === 'possible', $out['drSchoolId']['checks'] ?? null);
$check('DR: a non-ID document in the ID slot is a Possible mismatch naming what it appears to be', $v('drNotAnId', 'ownerId')['ID Type'] === 'possible'
    && str_contains($reason('drNotAnId', 'ownerId', 'ID Type'), 'Barangay Clearance'), $out['drNotAnId']['checks'] ?? null);
$check('DR: an ID that was not uploaded is Missing for both checks', $v('drIdMissing', 'ownerId') === ['Name Match' => 'missing', 'ID Type' => 'missing'], $out['drIdMissing']['checks'] ?? null);
$check('DR representative: the representative\'s own ID is compared with the submitted (representative\'s) name: Correct',
    $v('drRepresentative', 'requesterId') === ['Name Match' => 'correct', 'ID Type' => 'correct'], $out['drRepresentative']['checks'] ?? null);
$check('DR representative: the owner\'s ID is NOT compared with the representative\'s name (Unable to verify, never a Mismatch); its type is still checked',
    $v('drRepresentative', 'ownerId') === ['Name Match' => 'unable', 'ID Type' => 'correct']
    && str_contains($reason('drRepresentative', 'ownerId', 'Name Match'), "The property owner's name is not recorded on this request"), $out['drRepresentative']['checks'] ?? null);
$check('DR representative: the authorization letter gets no automatic check', $v('drRepresentative', 'authLetter') === []
    && str_contains($page('drRepresentative'), 'Not checked automatically. Inspect the original.'));
$check('DR: no Land Transfer document check ever appears, even for a land document in the result', ($out['drNeverLand'] ?? null) === ['Name Match', 'ID Type'], $out['drNeverLand'] ?? null);
$check('DR: a result from before version 4 cannot confirm the ID type (Unable to verify, run again)', $v('drOlderResult', 'ownerId') === ['Name Match' => 'correct', 'ID Type' => 'unable']
    && str_contains($reason('drOlderResult', 'ownerId', 'ID Type'), 'Run the check again'), $out['drOlderResult']['checks'] ?? null);
$check('DR: with the model unavailable the name is not read (Unable to verify) even though the ID issuer was found', $v('drModelDown', 'ownerId')['Name Match'] === 'unable', $out['drModelDown']['checks'] ?? null);

// ================= Land Transfer =================
$docNames = ['ctcTdOrTitle', 'notarialDeed', 'vicinityMap', 'certNoImprovement', 'taxClearance'];
$check('LT: each of the five required documents is identified as Correct from its printed title', array_map(static fn ($k) => $v('ltAllCorrect', $k)['Document identification'] ?? '', $docNames)
    === ['correct', 'correct', 'correct', 'correct', 'correct'], $out['ltAllCorrect']['checks'] ?? null);
$check('LT: exactly five document checks and no Name Match or ID Type', count($out['ltAllCorrect']['checks'] ?? []) === 5 && $v('ltAllCorrect', 'ownerId') === []
    && array_unique(array_column($out['ltAllCorrect']['checks'] ?? [], 1)) === ['Document identification'], $out['ltAllCorrect']['checks'] ?? null);
$check('LT: the ID is shown as required but not part of the Document Check', str_contains($page('ltAllCorrect'), 'Required with the application, but not part of the Land Transfer Document Check.'));
$check('LT: the page says all five required documents were identified, and still asks for verification against the originals', str_contains($page('ltAllCorrect'), 'All checks passed: all five required documents were identified.')
    && str_contains($page('ltAllCorrect'), 'Please verify the extracted details against the original document.'), $page('ltAllCorrect'));
$check('LT: each correct verdict names the document detected', str_contains($reason('ltAllCorrect', 'notarialDeed', 'Document identification'), 'Deed of Absolute Sale |')
    && str_contains($reason('ltAllCorrect', 'vicinityMap', 'Document identification'), 'Vicinity Map |'), $out['ltAllCorrect']['checks'] ?? null);
$check('LT: a Transfer or Original Certificate of Title is accepted in place of the Tax Declaration', ($v('ltTitleInsteadOfTd', 'ctcTdOrTitle')['Document identification'] ?? '') === 'correct'
    && ($v('ltOctInsteadOfTd', 'ctcTdOrTitle')['Document identification'] ?? '') === 'correct'
    && str_contains($reason('ltTitleInsteadOfTd', 'ctcTdOrTitle', 'Document identification'), 'Transfer Certificate of Title (TCT)'), [$out['ltTitleInsteadOfTd']['checks'] ?? null]);
$check('LT: a Deed of Donation is accepted in place of a Deed of Sale', ($v('ltDonation', 'notarialDeed')['Document identification'] ?? '') === 'correct'
    && str_contains($reason('ltDonation', 'notarialDeed', 'Document identification'), 'Deed of Donation |'), $out['ltDonation']['checks'] ?? null);
$check('LT: a required document that was not uploaded is Missing, naming the exact document', ($v('ltMissingMap', 'vicinityMap')['Document identification'] ?? '') === 'missing'
    && str_contains($reason('ltMissingMap', 'vicinityMap', 'Document identification'), 'Not uploaded: Vicinity Map.'), $out['ltMissingMap']['checks'] ?? null);
$check('LT: a different document uploaded is a Possible mismatch naming the type detected', ($v('ltWrongDoc', 'vicinityMap')['Document identification'] ?? '') === 'possible'
    && str_contains($reason('ltWrongDoc', 'vicinityMap', 'Document identification'), 'Real Property Tax Clearance') && str_contains($reason('ltWrongDoc', 'vicinityMap', 'Document identification'), 'not Vicinity Map'), $out['ltWrongDoc']['checks'] ?? null);
$check('LT: an unreadable file is Unable to verify, not Correct', ($v('ltUnreadable', 'taxClearance')['Document identification'] ?? '') === 'unable', $out['ltUnreadable']['checks'] ?? null);
$check('LT: an ambiguous file (no title found, type unclear) is Unable to verify', ($v('ltAmbiguous', 'certNoImprovement')['Document identification'] ?? '') === 'unable', $out['ltAmbiguous']['checks'] ?? null);
$check('LT: a file showing the titles of two documents is Unable to verify', ($v('ltTwoTitles', 'certNoImprovement')['Document identification'] ?? '') === 'unable'
    && str_contains($reason('ltTwoTitles', 'certNoImprovement', 'Document identification'), 'more than one document'), $out['ltTwoTitles']['checks'] ?? null);
$check('LT: a file NAMED after the required document, even with a matching suggestion, is not verified without its title in the text', ($v('ltNameOnly', 'ctcTdOrTitle')['Document identification'] ?? '') === 'unable'
    && str_contains($reason('ltNameOnly', 'ctcTdOrTitle', 'Document identification'), 'was not found in the text') && ($out['ltNameOnly']['state'] ?? '') === 'attention', $out['ltNameOnly']['checks'] ?? null);
$check('LT: a deed in the tax declaration slot that mentions a title and a TD number is a Possible mismatch (a Deed), never Correct', ($v('ltDeedInTdSlot', 'ctcTdOrTitle')['Document identification'] ?? '') === 'possible'
    && str_contains($reason('ltDeedInTdSlot', 'ctcTdOrTitle', 'Document identification'), 'Deed of Absolute Sale'), $out['ltDeedInTdSlot']['checks'] ?? null);
$check('LT: a deed the AI suggests is a Title is NOT identified as a Title (Possible mismatch: the text shows a deed)', ($v('ltDeedSuggestedTitle', 'ctcTdOrTitle')['Document identification'] ?? '') === 'possible'
    && str_contains($reason('ltDeedSuggestedTitle', 'ctcTdOrTitle', 'Document identification'), 'the text shows the title of Deed of Absolute Sale'), $out['ltDeedSuggestedTitle']['checks'] ?? null);
$check('LT: a deed the AI suggests is a Tax Declaration is NOT identified as a Tax Declaration', ($v('ltDeedSuggestedTd', 'ctcTdOrTitle')['Document identification'] ?? '') === 'possible', $out['ltDeedSuggestedTd']['checks'] ?? null);
$tdVerdict = static fn (string $case): string => $v($case, 'ctcTdOrTitle')['Document identification'] ?? '';
$check('LT: the deed with its heading lost, read as a Title, is NOT Correct: Possible mismatch, because its body reads like a deed', $tdVerdict('ltLostHeadingAsTitle') === 'possible'
    && str_contains($reason('ltLostHeadingAsTitle', 'ctcTdOrTitle', 'Document identification'), 'its body reads like a deed'), $out['ltLostHeadingAsTitle']['checks'] ?? null);
$check('LT: the same deed with an unsure reading, or read as a Tax Declaration, is never Correct', $tdVerdict('ltLostHeadingUnsure') === 'possible' && $tdVerdict('ltLostHeadingAsTd') === 'possible',
    [$out['ltLostHeadingUnsure']['checks'] ?? null, $out['ltLostHeadingAsTd']['checks'] ?? null]);
$check('LT: a stray title-number line with the Registry of Deeds (no deed wording, no title certification) is Unable to verify, not Correct', $tdVerdict('ltStrayTitleLine') === 'unable', $out['ltStrayTitleLine']['checks'] ?? null);
$check('LT: in the deed slot, deed wording without its heading is Unable to verify (neither Correct nor a mismatch)',
    ($v('ltLostHeadingInDeedSlot', 'notarialDeed')['Document identification'] ?? '') === 'unable', $out['ltLostHeadingInDeedSlot']['checks'] ?? null);
$check('LT: a genuine title (heading, Registry of Deeds, certification) is still Correct, even with a sale in its encumbrances', $tdVerdict('ltGenuineTitle') === 'correct', $out['ltGenuineTitle']['checks'] ?? null);
$check('LT: a title heading in a file whose body also reads like a deed is Unable to verify (conflict), never Correct', $tdVerdict('ltTitleWithDeedWording') === 'unable'
    && str_contains($reason('ltTitleWithDeedWording', 'ctcTdOrTitle', 'Document identification'), 'reads like a deed'), $out['ltTitleWithDeedWording']['checks'] ?? null);
$check('LT: a deed with its heading and its usual wording is still Correct in the deed slot', ($v('ltDeedWithHeadingAndWording', 'notarialDeed')['Document identification'] ?? '') === 'correct', $out['ltDeedWithHeadingAndWording']['checks'] ?? null);
$check('LT: conflicting headings (a Title and a Deed in one file) are never Correct, even with a matching suggestion', ($v('ltConflict', 'ctcTdOrTitle')['Document identification'] ?? '') === 'unable'
    && str_contains($reason('ltConflict', 'ctcTdOrTitle', 'Document identification'), 'more than one document'), $out['ltConflict']['checks'] ?? null);
$check('LT: AI suggestions (and file names) alone produce no Correct for any of the five documents', array_unique(array_map(static fn ($k) => $v('ltSuggestionsOnly', $k)['Document identification'] ?? '', $docNames)) === ['unable']
    && !str_contains($page('ltSuggestionsOnly'), 'All checks passed'), $out['ltSuggestionsOnly']['checks'] ?? null);
$check('LT: a printed title found in the text identifies the document even when the reading was unsure', ($v('ltTitleFoundModelUnsure', 'vicinityMap')['Document identification'] ?? '') === 'correct', $out['ltTitleFoundModelUnsure']['checks'] ?? null);
$check('LT: a result from before version 4 cannot identify documents (all Unable to verify)', array_unique(array_map(static fn ($k) => $v('ltOlderResult', $k)['Document identification'] ?? '', $docNames)) === ['unable'], $out['ltOlderResult']['checks'] ?? null);
$check('LT: a missing document stops the all-passed headline', !str_contains($page('ltMissingMap'), 'All checks passed') && ($out['ltMissingMap']['state'] ?? '') === 'attention');
$check('LT: when any check needs attention the headline counts it, and the request is not "No concerns found"', str_contains($page('ltWrongDoc'), '1 of 5 checks need attention')
    && ($out['ltWrongDoc']['state'] ?? '') === 'attention', $page('ltWrongDoc'));

// ================= Safeguards =================
$all = implode(' ', array_map($page, array_keys($cases)));
$check('no verdict claims authenticity of an ID, signature, seal, notarization or document', !preg_match('/\b(authentic|genuine|legitimate|notariz(ed|ation) (is|was) valid)\b/i',
    (string) preg_replace('/cannot tell whether a document, signature or seal is genuine|Signatures, seals and notarization can only be judged on the original/i', '', $all)));
$check('no automatic approval or rejection control on any result', !preg_match('/data-action="(approve|reject|update-status|start-processing|complete-processing)/', implode('', array_column($out, 'html'))));
$check('every verdict is one of the five allowed', array_diff(array_unique(array_merge(...array_values(array_map(static fn ($c) => array_column($c['checks'] ?? [], 2), array_filter($out, static fn ($x) => is_array($x) && isset($x['checks'])))))),
    ['correct', 'mismatch', 'possible', 'missing', 'unable']) === []);
$check('the file name is never used: the same file names with no title in the text never give Correct', ($v('ltNameOnly', 'ctcTdOrTitle')['Document identification'] ?? '') !== 'correct'
    && !str_contains((string) json_encode(array_column($out['ltAllCorrect']['checks'] ?? [], 4)), '.png'));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
