<?php
declare(strict_types=1);

/*
 * Evaluates ONE synthetic fixture the way the AI worker reads an ID upload, in its own process so
 * its time and peak memory are its own: OcrService (with the ID completeness check), readability,
 * IdFieldExtractor (type, fields, expiry) and the worker's rule for the name (reported only when
 * the type is confirmed, no complete OCR pass read a different name and it is not flagged possibly
 * incomplete).
 *
 *   php tests/tools/ocr-evaluate-one.php <fixture> <tools JSON> <YYYY-MM-DD for expiry>
 *
 * Prints one JSON object. For fictional test cards only: it prints field values.
 */

require_once __DIR__ . '/../../app/services/OcrService.php';
require_once __DIR__ . '/../../app/services/DocumentAnalysis.php';

[, $path, $toolsJson, $today] = $argv + [null, '', '{}', date('Y-m-d')];
$tools = json_decode((string) $toolsJson, true) ?: [];
$started = microtime(true);
$service = new OcrService(dirname((string) $path), 30, 90, $tools);
$ocr = $service->extract((string) $path, static fn (string $text): ?float => DocumentAnalysis::ocrCompleteness('requesterId', $text));
$text = $ocr['success'] ? DocumentAnalysis::normalizeText($ocr['text']) : '';
$readability = DocumentAnalysis::readability('requesterId', $text, $ocr['success']);
$read = IdFieldExtractor::extract($text, (string) $today);
$conflict = false;
foreach ($ocr['alternative_texts'] ?? [] as $alternative) {
    $other = $read['type'] === null ? null : IdFieldExtractor::extractAs($read['type'], DocumentAnalysis::normalizeText((string) $alternative), (string) $today)['name'];
    $conflict = $conflict || ($other !== null && $read['name'] !== null && !DocumentAnalysis::namesAgree($other, $read['name']));
}
$check = DocumentAnalysis::idCheck($read, $readability, $ocr['success'], '', $conflict);
$scale = PHP_OS_FAMILY === 'Darwin' ? 1 : 1024;
echo json_encode([
    'ocr_success' => $ocr['success'],
    'ocr_error' => $ocr['error_code'],
    'ocr_state' => $check['ocr_state'],
    'readability' => $readability,
    'type' => $read['type'],
    'type_state' => $read['type_state'],
    'fields' => $read['fields'],
    // As the worker: a name counts only for a confirmed type, with no conflicting pass, and not flagged
    // possibly incomplete (a "LAST, FIRST" name whose middle name may be missing is left for Staff).
    'name' => $read['type_state'] === 'confirmed' && !$conflict && $read['name_complete'] ? $read['name'] : null,
    'name_conflict' => $conflict,
    'name_incomplete' => $read['name'] !== null && !$read['name_complete'],
    'expiry' => $read['expiry'],
    'seconds' => round(microtime(true) - $started, 3),
    'peak_rss' => (int) getrusage()['ru_maxrss'] * $scale,
]), "\n";
