<?php
declare(strict_types=1);

/*
 * OCR evaluation (quick set) with the real local tools on FICTIONAL ID cards of every supported
 * type, against hand-written ground truth (tests/fixtures/ocr-ground-truth). The full matrix and
 * the report: php tests/tools/ocr-evaluation.php --report=docs/OCR_EVALUATION.md
 *
 * Required here (safety, then accuracy on clear cards):
 *  - no false name match and no false classification, for any type or condition;
 *  - no non-ID document confirmed as an ID or giving a name;
 *  - a card missing a printed field never gets that field or a name it cannot support;
 *  - every clear card: right type, right name, every field exactly right, read as readable;
 *  - every fixture processed, each within 30 s.
 * Per-type results are printed (never one combined figure).
 *
 *   php tests/ocr_evaluation_test.php      (needs Tesseract, Poppler, GD with FreeType, a TrueType font; exits 2 without them)
 */

require_once __DIR__ . '/support/IdOcrEvaluation.php';
require_once __DIR__ . '/../app/services/OcrImagePreparer.php';
require_once __DIR__ . '/../app/services/IdFieldExtractor.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 500)];
};
$tools = IdOcrEvaluation::tools();
if ($tools === null || !IdCardFixtures::available() || !OcrImagePreparer::available()) {
    fwrite(STDERR, "Tesseract/Poppler, GD with FreeType or a TrueType font is missing. Nothing was checked.\n");
    exit(2);
}
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sa-ocr-evaluation-test-' . bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(static function () use ($work): void {
    array_map('unlink', glob($work . DIRECTORY_SEPARATOR . '*') ?: []);
    @rmdir($work);
});
$evaluation = new IdOcrEvaluation($work, $tools, __DIR__ . '/fixtures/ocr-ground-truth');
$truth = $evaluation->truth();

// ---- Ground truth is complete and independent of the reader ----
$cardsByType = [];
foreach ($truth['cards'] as $id => $card) {
    $cardsByType[$card['type']][] = $id;
    $fields = array_keys(IdDocumentTypes::type($card['type'])['fields']);
    $check("ground truth {$id}: fictional, one expected value (or null) for every field of its type, required fields as the registry", $card['fictional'] === true
        && array_keys($card['fields']) === $fields && $card['required'] === IdDocumentTypes::type($card['type'])['required'], [array_keys($card['fields']), $fields]);
}
$check('ground truth covers all seven document types, two layouts for PhilSys and the driver\'s license, and four negative controls',
    count(array_intersect(IdOcrEvaluation::TYPE_ORDER, array_keys($cardsByType))) === 7 && count($cardsByType) === 7
    && count($cardsByType['philsys_national_id']) === 2 && count($cardsByType['drivers_license']) === 2 && count($truth['negatives']) === 4);
$code = static function (string $file): string {   // the file's PHP code without its comments
    return implode('', array_map(static fn (mixed $t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
        token_get_all((string) file_get_contents($file))));
};
$check('the fixtures and the per-fixture reader never open the ground truth to draw or read a card (independent definitions)',
    !preg_match('/ground.?truth/i', $code(__DIR__ . '/support/IdCardFixtures.php')) && !preg_match('/ground.?truth/i', $code(__DIR__ . '/tools/ocr-evaluate-one.php')));

// ---- Run ----
$run = $evaluation->run(true);
$summary = $evaluation->summary($run);
$errors = array_filter($run, static fn (array $r): bool => isset($r['actual']['error']) || !isset($r['actual']['seconds']));
$check('every planned fixture was processed (' . count($run) . ' fixtures)', $errors === [] && count($run) === count($evaluation->plan(true)), array_column($errors, 'card'));
$check('every fixture finished within 30 s', max(array_map(static fn (array $r): float => (float) ($r['actual']['seconds'] ?? 99), $run)) < 30);

$falseNames = [];
$falseTypes = [];
foreach ($run as $r) {
    if ($r['kind'] !== 'card') {
        continue;
    }
    $s = IdOcrEvaluation::score($truth['cards'][$r['card']], $r['condition'], $r['actual']);
    if ($s['false_name']) {
        $falseNames[] = $r['card'] . '/' . $r['condition'];
    }
    if ($s['false_classification']) {
        $falseTypes[] = $r['card'] . '/' . $r['condition'];
    }
    if ($r['condition'] === 'clear') {
        $check("clear {$r['card']}: right type and state, right name, every field exactly right, readable",
            $s['classification'] && $s['name'] && $s['field_matches'] === $s['fields_total'] && $s['readable_expected'] === true, $r['actual']);
    }
    if ($r['condition'] === 'missing_field') {
        $expected = IdOcrEvaluation::expected($truth['cards'][$r['card']], 'missing_field');
        $omitted = array_keys(array_filter($expected['fields'], static fn (?string $v, string $f): bool => $v === null && $truth['cards'][$r['card']]['fields'][$f] !== null, ARRAY_FILTER_USE_BOTH));
        $invented = array_filter($omitted, static fn (string $f): bool => ($r['actual']['fields'][$f]['state'] ?? '') === 'read');
        $check("missing field {$r['card']}: the field left off the card is not read, and no name is reported that the card cannot support",
            $invented === [] && ($expected['name'] !== null || $r['actual']['name'] === null), [$omitted, $r['actual']['name']]);
    }
}
$check('no false name match on any type or condition', $falseNames === [], $falseNames);
$check('no false classification on any type or condition', $falseTypes === [], $falseTypes);
$check('negative controls: no non-ID document is confirmed as an ID, none gives a name', $summary['negatives']['confirmed'] === 0 && $summary['negatives']['names'] === 0, $summary['negatives']);

foreach ($summary['types'] as $type => $t) {
    printf("%-18s fixtures %2d  classification %2d  names %2d  false names %d  fields %3d/%-3d  CER %4.1f%%  max %.1f s\n", IdOcrEvaluation::TYPE_NAMES[$type], $t['fixtures'],
        $t['classification'], $t['name'], $t['false_name'], $t['field_matches'], $t['fields_total'], $t['cer_characters'] ? 100 * $t['cer_distance'] / $t['cer_characters'] : 0, max($t['seconds']));
}

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
