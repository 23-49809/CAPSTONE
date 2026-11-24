<?php
declare(strict_types=1);

/*
 * Simplified National ID front review (no database, OCR binary, or network):
 * Full Name and Address extraction only, cautious comparisons, constrained AI codes, and Staff output.
 * Run: php tests/id_front_review_test.php
 */
require_once __DIR__ . '/../app/services/IdFrontReview.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 400)];
};

$frontText = "REPUBLIKA NG PILIPINAS\nPAMBANSANG PAGKAKAKILANLAN\nPhilippine Identification Card\n"
    . "Apelyido/Last Name\nDELA CRUZ\nMga Pangalan/Given Names\nJUAN SANTOS\nGitnang Apelyido/Middle Name\nREYES\n"
    . "Tirahan/Address\n123 RIZAL ST, BRGY SAN JUAN, MABINI, BATANGAS\n";
$read = IdFieldExtractor::extractNationalIdFullName($frontText);
$check('front name reader returns one complete Full Name value only', $read === ['status' => 'read', 'value' => 'JUAN SANTOS REYES DELA CRUZ'], $read);
$check('the simplified name reader does not return DOB, sex, ID number, or separate name fields', array_keys($read) === ['status', 'value']
    && !array_key_exists('birth_date', $read) && !array_key_exists('card_number', $read));

$extracted = IdFrontReview::extract($frontText . "Petsa ng Kapanganakan/Date of Birth\n01 JANUARY 1990\nPCN\n1234-5678-9012-3456\n", true);
$check('front review returns only Full Name and Address as extracted fields', array_keys($extracted) === ['side', 'full_name', 'address']
    && array_keys($extracted['full_name']) === ['status', 'value'] && array_keys($extracted['address']) === ['status', 'value'], $extracted);
$check('an unreadable or failed OCR result has no invented values', IdFrontReview::extract('', false) === [
    'side' => 'front', 'full_name' => ['status' => 'unreadable', 'value' => null], 'address' => ['status' => 'unreadable', 'value' => null],
]);
$check('different OCR name readings return Needs Review without selecting either name', IdFrontReview::extract($frontText, true, true)['full_name'] === ['status' => 'uncertain', 'value' => null]);
$partial = IdFieldExtractor::extractNationalIdFullName("Apelyido/Last Name\nDELA CRUZ\nMga Pangalan/Given Names\n");
$check('a partial name stays incomplete and is never completed from the form', $partial['status'] === 'incomplete' && $partial['value'] === 'DELA CRUZ', $partial);
$check('a non-National ID title is not treated as the National ID front', IdFrontReview::extract("DRIVER LICENSE\nName\nJUAN SANTOS\n", true)['full_name']['status'] === 'not_found');

$form = IdFrontReview::form(['first_name' => 'Juan Santos', 'middle_name' => 'Reyes', 'last_name' => 'Dela Cruz',
    'address_line' => '123 Rizal Street, Barangay San Juan', 'city' => 'Mabini', 'province' => 'Batangas', 'zip_code' => '4202']);
$fullName = $extracted['full_name'];
$check('full-name comparison tolerates case and punctuation only', IdFrontReview::compareFullName(
    ['status' => 'read', 'value' => 'JUAN SANTOS REYES DELA CRUZ'], $form)['result'] === 'match');
$check('a potentially incomplete full name needs review rather than matching', IdFrontReview::compareFullName(
    ['status' => 'incomplete', 'value' => 'JUAN DELA CRUZ'], $form)['result'] === 'needs_review');
$check('a meaningful full-name difference is reported as mismatch', IdFrontReview::compareFullName(
    ['status' => 'read', 'value' => 'MARIA SANTOS REYES DELA CRUZ'], $form)['result'] === 'mismatch');
$check('the same full-name words in a different order need Staff review', IdFrontReview::compareFullName(
    ['status' => 'read', 'value' => 'JUAN SANTOS DELA CRUZ REYES'], $form)['result'] === 'needs_review');
$check('an incomplete application full name needs review', IdFrontReview::compareFullName(
    ['status' => 'read', 'value' => 'JUAN SANTOS REYES DELA CRUZ'], array_replace($form, ['last_name' => '']))['result'] === 'needs_review');
$check('an incomplete address needs review', IdFrontReview::compareAddress(['status' => 'incomplete', 'value' => 'MABINI'], $form)['result'] === 'needs_review');
$check('the front OCR address reader finds the printed address label', $extracted['address']['status'] === 'read'
    && str_contains((string) $extracted['address']['value'], 'MABINI'), $extracted['address']);

$comparison = IdFrontReview::compare($extracted, $form);
$input = IdFrontReview::modelInput('ownerId', $extracted, $form, $comparison, 'readable');
$check('AI review input contains only Full Name and Address from both sides', array_keys($input['application_form']) === ['full_name', 'address']
    && array_keys($input['extracted_from_id']) === ['full_name', 'address']
    && !str_contains(json_encode($input), 'birth_date') && !str_contains(json_encode($input), 'card_number'), $input);
$ai = IdFrontReview::validateModelReview(['key' => 'ownerId', 'overall' => 'needs_review',
    'full_name' => ['assessment' => 'agrees', 'differences' => []],
    'address' => ['assessment' => 'cannot_tell', 'differences' => ['abbreviation_or_format']],
    'attention' => ['check_address']], ['ownerId']);
$check('AI analysis accepts only fixed advisory codes for the full name and address', $ai['overall'] === 'needs_review'
    && $ai['address']['assessment'] === 'cannot_tell', $ai);
$check('AI cannot return a decision or claim ID authenticity', !in_array('approve', IdFrontReview::AI_OVERALL, true)
    && !in_array('reject', IdFrontReview::AI_OVERALL, true) && !str_contains(strtolower(IdFrontReview::modelRules()), 'authenticate'));
$unavailable = IdFrontReview::aiFindings(['key' => 'ownerId', 'ai_id_review_state' => 'model_timeout'], $comparison, $extracted);
$check('AI timeout is shown as unavailable and does not fabricate findings', $unavailable['available'] === false
    && str_contains($unavailable['message'], 'unavailable'));

$staff = IdFrontReview::staffView([
    ['key' => 'ownerId', 'label' => 'Property Owner National ID', 'requirement' => 'uploaded_valid_format',
        'id_review' => $extracted, 'id_front_ocr_text' => $frontText, 'ai_id_review_state' => 'model_timeout'],
], $form, true);
$check('Staff view shows only applicant front extraction, comparison, original OCR text and unavailable AI state', is_array($staff)
    && $staff['side'] === 'front' && $staff['ocrText'] === $frontText && count($staff['rows']) === 2
    && $staff['ai']['available'] === false && $staff['otherIds'] === [], $staff);
$otherSlotOnly = IdFrontReview::staffView([
    ['key' => 'requesterId', 'label' => 'Representative ID', 'requirement' => 'uploaded_valid_format', 'id_review' => $extracted],
], $form, true);
$check('a review from the non-applicant ID slot cannot create or populate the applicant review', $otherSlotOnly === null, $otherSlotOnly);
$bothSlots = IdFrontReview::staffView([
    ['key' => 'ownerId', 'label' => 'Applicant National ID', 'requirement' => 'uploaded_valid_format',
        'id_review' => $extracted, 'id_front_ocr_text' => $frontText],
    ['key' => 'requesterId', 'label' => 'Representative ID', 'requirement' => 'uploaded_valid_format',
        'id_review' => ['side' => 'front', 'full_name' => ['status' => 'read', 'value' => 'OTHER PERSON'], 'address' => ['status' => 'read', 'value' => 'OTHER ADDRESS']],
        'id_front_ocr_text' => 'representative private OCR'],
], $form, true);
$check('only the selected applicant ID slot is exposed even if another slot contains review data', is_array($bothSlots)
    && $bothSlots['ocrText'] === $frontText && $bothSlots['otherIds'] === [], $bothSlots);
$internalHtml = (string) file_get_contents(dirname(__DIR__, 2) . '/smart-assess-internal.html');
$internalPhp = (string) file_get_contents(dirname(__DIR__) . '/web/internal.php');
$check('the existing Staff Application Details page renders the original front, OCR, extracted values, comparison and AI findings',
    str_contains($internalHtml, 'function reqIdFrontReviewHtml(r)') && str_contains($internalHtml, 'Uploaded ID (Front Side Only)')
    && str_contains($internalHtml, 'Extracted Information') && str_contains($internalHtml, 'Application Comparison')
    && str_contains($internalHtml, 'AI Review Findings') && str_contains($internalHtml, 'Original OCR Text (National ID Front)')
    && str_contains($internalHtml, "esc(review&&review.ocrText||'OCR text is not available for this result.')"));
$renderFunction = substr($internalHtml, strpos($internalHtml, 'function reqIdFrontReviewHtml(r){'));
$renderFunction = substr($renderFunction, 0, strpos($renderFunction, "\n}\n") + 3);
$check('the ID review panel uses the existing protected original-file route and has no approval/rejection action',
    str_contains($renderFunction, '/request-file.php?doc=') && !str_contains($renderFunction, 'approve') && !str_contains($renderFunction, 'reject'));
$check('the Staff page payload removes raw transcripts from the general AI document list after creating the selected National-ID review',
    str_contains($internalPhp, "unset(\$reviewDocument['id_front_ocr_text']);")
    && strpos($internalPhp, "IdFrontReview::staffView(") < strpos($internalPhp, "unset(\$reviewDocument['id_front_ocr_text']);"));


$aiMatch = [
    'key' => 'ownerId',
    'ai_id_review_state' => 'ok',
    'ai_id_review' => [
        'key' => 'ownerId',
        'overall' => 'match',
        'full_name' => ['assessment' => 'agrees', 'differences' => []],
        'address' => ['assessment' => 'agrees', 'differences' => []],
        'attention' => [],
    ],
];
$systemMismatch = [
    'full_name' => ['result' => 'mismatch', 'reasons' => ['different_full_name']],
    'address' => ['result' => 'match', 'reasons' => ['same']],
    'overall' => 'mismatch',
];
$mismatchDespiteAiMatch = IdFrontReview::aiFindings($aiMatch, $systemMismatch, $extracted);
$check('AI match cannot downgrade a deterministic mismatch in the displayed result',
    $mismatchDespiteAiMatch['overall'] === 'mismatch'
    && $mismatchDespiteAiMatch['overallLabel'] === 'MISMATCH'
    && str_contains($mismatchDespiteAiMatch['recommendation'], 'carefully'));

$systemNeedsReview = [
    'full_name' => ['result' => 'needs_review', 'reasons' => ['incomplete']],
    'address' => ['result' => 'match', 'reasons' => ['same']],
    'overall' => 'needs_review',
];
$needsReviewDespiteAiMatch = IdFrontReview::aiFindings($aiMatch, $systemNeedsReview, $extracted);
$check('AI match cannot downgrade a deterministic needs-review result',
    $needsReviewDespiteAiMatch['overall'] === 'needs_review'
    && $needsReviewDespiteAiMatch['overallLabel'] === 'NEEDS REVIEW'
    && str_contains($needsReviewDespiteAiMatch['recommendation'], 'Check the items'));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
