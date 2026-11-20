<?php
declare(strict_types=1);

/*
 * OCR evaluation on fictional ID cards (see tests/support/IdOcrEvaluation.php).
 *
 *   php tests/tools/ocr-evaluation.php [--quick] [--report=docs/OCR_EVALUATION.md] [--json=results.json]
 *
 * --quick runs the conditions the test suite uses (IdOcrEvaluation::QUICK_CONDITIONS); without it,
 * every card under every condition. Prints the per-type table; --report writes the Markdown report.
 * Needs Tesseract, Poppler, GD with FreeType and a TrueType font (exits 2 without them).
 */

require_once __DIR__ . '/../support/IdOcrEvaluation.php';

$options = getopt('', ['quick', 'report:', 'json:']);
$tools = IdOcrEvaluation::tools();
if ($tools === null || !IdCardFixtures::available() || !OcrImagePreparerAvailable()) {
    fwrite(STDERR, "Tesseract/Poppler, GD with FreeType or a TrueType font is missing. Nothing was evaluated.\n");
    exit(2);
}
function OcrImagePreparerAvailable(): bool
{
    require_once __DIR__ . '/../../app/services/OcrImagePreparer.php';
    return OcrImagePreparer::available();
}
$quick = isset($options['quick']);
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sa-ocr-evaluation-' . bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(static function () use ($work): void {
    array_map('unlink', glob($work . DIRECTORY_SEPARATOR . '*') ?: []);
    @rmdir($work);
});
$evaluation = new IdOcrEvaluation($work, $tools, dirname(__DIR__) . '/fixtures/ocr-ground-truth');
$started = microtime(true);
$results = $evaluation->run($quick, static function (int $index, int $total, array $item): void {
    fwrite(STDERR, sprintf("\r[%3d/%3d] %-20s %-20s", $index + 1, $total, $item['card'], $item['condition']));
});
fwrite(STDERR, "\n");
$summary = $evaluation->summary($results);
$version = trim((string) strtok((string) shell_exec(escapeshellarg($tools['tesseract']) . ' --version 2>&1'), "\n"));
$meta = [
    'quick' => $quick,
    'date' => date('Y-m-d H:i'),
    'environment' => $version . ', PHP ' . PHP_VERSION . ', ' . PHP_OS_FAMILY . ', English language data (eng) only; '
        . count($results) . ' fixtures in ' . round(microtime(true) - $started) . ' s',
    'limitations' => [
        'Synthetic cards only: generic layouts drawn with one font family, no holograms, laminate, wear, real photos or real security printing. Real IDs will read worse.',
        'Each document type has one or two layouts; real cards vary by issue year and region.',
        'The passport machine-readable zone uses Courier, not the OCR-B font of real passports; Tesseract has no OCR-B model installed (eng only).',
        'Filipino labels are matched by spelling rules, not a Filipino language model (no tgl data installed).',
        'Field values are compared exactly after normalisation (dates as YYYY-MM-DD); a near-miss counts as wrong, and CER shows how near.',
        'The name is the one the ID\'s own labelled fields print, reported only for a confirmed type (as the worker does). In production the model\'s name is also checked against the text.',
        'Peak memory counts the PHP process only; Tesseract runs as its own process (about 170 MB measured for a 12 MP photo).',
    ],
];
foreach ($summary['types'] as $type => $t) {
    printf("%-18s fixtures %3d  classification %-16s name %-16s false names %d  fields %-18s\n", IdOcrEvaluation::TYPE_NAMES[$type], $t['fixtures'],
        $t['classification'] . '/' . $t['fixtures'], $t['name'] . '/' . $t['fixtures'], $t['false_name'], $t['field_matches'] . '/' . $t['fields_total']);
}
printf("negatives          fixtures %3d  confirmed %d  names %d\n", $summary['negatives']['fixtures'], $summary['negatives']['confirmed'], $summary['negatives']['names']);
if (isset($options['report'])) {
    file_put_contents((string) $options['report'], IdOcrEvaluation::markdown($summary, $meta));
}
if (isset($options['json'])) {
    file_put_contents((string) $options['json'], (string) json_encode(['meta' => $meta, 'summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT));
}
