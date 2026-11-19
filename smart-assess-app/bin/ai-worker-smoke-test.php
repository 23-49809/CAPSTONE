<?php
declare(strict_types=1);

/*
 * SMART ASSESS - smoke test of the AI worker with a SYNTHETIC document only.
 *
 *   php bin/ai-worker-smoke-test.php
 *
 * Builds one synthetic, image-only "identification card" in memory (no text layer, so
 * the worker must run Tesseract on it), sends it to the worker as a normal signed job,
 * and checks the answer the way the application would accept it:
 *   - the worker reports ready;
 *   - the document was read by Tesseract (method image_ocr, OCR ok, enough characters);
 *   - the model ran (status ok, with its name and digest);
 *   - the answer is the current result version (DocumentAnalysis::WORKER_RESULT_VERSION, the
 *     version this release's worker answers with) and passes the application's own validation.
 * It takes no file, path or document argument: it cannot send anything but its own
 * synthetic image. It uses no database, changes nothing and sends no SMS or email. It
 * prints statuses, counts and timings only, never the text read from the document.
 *
 * Settings (environment), the same as the dispatcher's:
 *   SMART_ASSESS_AI_WORKER_URL       e.g. http://127.0.0.1:8095
 *   SMART_ASSESS_AI_WORKER_SECRET    the shared secret (or SMART_ASSESS_WORKER_SECRET,
 *                                    the worker's own name for it, so it can run with
 *                                    the worker's environment file)
 *   SMART_ASSESS_AI_WORKER_SOCKET    optional: the dispatcher's link socket
 *   SMART_ASSESS_AI_WORKER_TIMEOUT   optional (default 900 s)
 * See docs/AI_DOCUMENT_VERIFICATION.md ("Production installation checklist") for the
 * exact commands. Needs GD, or pdftoppm (Poppler) as a fallback, to draw the image.
 *
 * Exit codes: 0 every check passed; 1 a check failed; 2 not configured or cannot build
 * the synthetic image; 75 the worker is not ready (nothing was sent).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require_once dirname(__DIR__) . '/app/services/WorkerSignature.php';
require_once dirname(__DIR__) . '/app/services/AiWorkerClient.php';
require_once dirname(__DIR__) . '/app/services/DocumentAnalysis.php';

const SA_SMOKE_JOB_ID = 999999001;   // recognisable in the worker's log; never a real job ID in use
const SA_SMOKE_MIN_CHARACTERS = 40;
const SA_SMOKE_LINES = [
    'SYNTHETIC TEST DOCUMENT - NOT A REAL ID',
    'SAMPLE IDENTIFICATION CARD',
    'Last Name: SAMPLE',
    'Given Name: TESTER',
    'Date of Birth: 1990-01-01',
    'Address: Test Street, Sample Town',
    'Card No. 0000-0000-0000-0000',
];

/** The synthetic image as PNG bytes: GD when loaded, else a text PDF rasterised by pdftoppm. */
function sa_smoke_image(): string
{
    if (function_exists('imagecreatetruecolor')) {
        $small = imagecreatetruecolor(760, 22 * count(SA_SMOKE_LINES) + 40);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        foreach (SA_SMOKE_LINES as $index => $line) {
            imagestring($small, 5, 20, 20 + 22 * $index, $line, imagecolorallocate($small, 0, 0, 0));
        }
        $big = imagecreatetruecolor(1520, 2 * imagesy($small));
        imagecopyresampled($big, $small, 0, 0, 0, 0, 1520, 2 * imagesy($small), 760, imagesy($small));
        ob_start();
        imagepng($big);
        return (string) ob_get_clean();
    }
    $pdftoppm = null;
    foreach (['/usr/bin/pdftoppm', '/opt/homebrew/bin/pdftoppm', '/usr/local/bin/pdftoppm'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $pdftoppm = $candidate;
            break;
        }
    }
    if ($pdftoppm === null) {
        throw new RuntimeException('neither GD nor pdftoppm is available to draw the synthetic image');
    }
    $content = 'BT /F1 14 Tf 40 360 Td 22 TL';
    foreach (SA_SMOKE_LINES as $line) {
        $content .= ' (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line) . ') Tj T*';
    }
    $content .= ' ET';
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 520 400] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>', '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream"];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

    $dir = sys_get_temp_dir() . '/sa-smoke-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('cannot create a temporary folder');
    }
    try {
        file_put_contents($dir . '/in.pdf', $pdf);
        $process = proc_open([$pdftoppm, '-png', '-r', '200', '-singlefile', $dir . '/in.pdf', $dir . '/out'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0 || !is_file($dir . '/out.png')) {
            throw new RuntimeException('pdftoppm could not draw the synthetic image');
        }
        return (string) file_get_contents($dir . '/out.png');
    } finally {
        @unlink($dir . '/in.pdf');
        @unlink($dir . '/out.png');
        @rmdir($dir);
    }
}

function sa_smoke_main(array $argv): int
{
    if (count($argv) > 1) {
        fwrite(STDERR, "This test takes no arguments: it only sends its own built-in synthetic document.\n");
        return 2;
    }
    $results = [];
    $check = static function (string $name, bool $passed, string $detail = '') use (&$results): void {
        $results[] = $passed;
        echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    };

    try {
        if ((getenv('SMART_ASSESS_AI_WORKER_SECRET') === false || getenv('SMART_ASSESS_AI_WORKER_SECRET') === '')
            && is_string(getenv('SMART_ASSESS_WORKER_SECRET')) && getenv('SMART_ASSESS_WORKER_SECRET') !== '') {
            putenv('SMART_ASSESS_AI_WORKER_SECRET=' . getenv('SMART_ASSESS_WORKER_SECRET'));   // the worker's own name for it
        }
        $client = AiWorkerClient::fromEnvironment();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Not configured: ' . $exception->getMessage() . "\n");
        return 2;
    }
    try {
        $png = sa_smoke_image();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Cannot build the synthetic document: ' . $exception->getMessage() . "\n");
        return 2;
    }

    $health = $client->health();
    $check('the worker reports ready', $health === AiWorkerClient::HEALTH_READY, $health);
    if ($health !== AiWorkerClient::HEALTH_READY) {
        fwrite(STDERR, "The worker is not ready; nothing was sent.\n");
        return 75;
    }

    $job = ['version' => 1, 'job_id' => SA_SMOKE_JOB_ID,
        'declared' => ['flow' => 'docreq', 'is_owner' => true, 'first_name' => 'TESTER', 'last_name' => 'SAMPLE',
            'arp_number' => '00-0000-00000', 'document_type' => 'Certified True Copy of Tax Declaration', 'transfer_type' => ''],
        'documents' => [['key' => 'ownerId', 'label' => 'Synthetic test ID', 'mime' => 'image/png', 'content_base64' => base64_encode($png)]],
        'time_budget_seconds' => $client->workerBudgetSeconds()];
    $started = microtime(true);
    try {
        $raw = $client->analyze($job);
    } catch (Throwable $exception) {
        $check('the worker answered the synthetic job', false, preg_match('/^[a-z_]{1,40}$/', $exception->getMessage()) ? $exception->getMessage() : 'error');
        return 1;
    }
    $seconds = microtime(true) - $started;
    $document = $raw['documents'][0] ?? [];
    $model = $raw['model'] ?? [];

    $check('the worker answered the synthetic job', true, sprintf('%.1f s', $seconds));
    // The worker must answer with this release's version, not merely one the application still
    // accepts: an older answer means the worker was not refreshed with this release.
    $check('result version ' . DocumentAnalysis::WORKER_RESULT_VERSION . ' (the worker matches this release)',
        ($raw['version'] ?? null) === DocumentAnalysis::WORKER_RESULT_VERSION, 'version ' . json_encode($raw['version'] ?? null));
    $check('Tesseract read the image (method image_ocr, OCR ok)', ($document['method'] ?? null) === 'image_ocr' && ($document['ocr_status'] ?? null) === 'ok',
        'method ' . json_encode($document['method'] ?? null) . ', ocr ' . json_encode($document['ocr_status'] ?? null) . ', engine ' . json_encode($raw['ocr_engine'] ?? null));
    $check('enough text was read', (int) ($document['text_characters'] ?? 0) >= SA_SMOKE_MIN_CHARACTERS && ($document['readability'] ?? 'unreadable') !== 'unreadable',
        (int) ($document['text_characters'] ?? 0) . ' characters, readability ' . json_encode($document['readability'] ?? null));
    $check('the model ran (status ok, name and digest reported)', ($model['status'] ?? null) === 'ok' && is_string($model['name'] ?? null) && $model['name'] !== ''
        && is_string($model['digest'] ?? null) && $model['digest'] !== '',
        'status ' . json_encode($model['status'] ?? null) . ', model ' . json_encode($model['name'] ?? null) . ', digest ' . json_encode(is_string($model['digest'] ?? null) ? substr($model['digest'], 0, 19) : null));
    try {
        $validated = DocumentAnalysis::validateWorkerResult($raw, ['ownerId']);
        $check('the application accepts the answer (its own validation)', true, count($validated['rule_findings'] ?? []) . ' rule finding(s)');
    } catch (Throwable $exception) {
        $check('the application accepts the answer (its own validation)', false, 'rejected');
    }

    $failed = count(array_filter($results, static fn (bool $passed): bool => !$passed));
    printf("%d / %d passed (synthetic document only; nothing stored, nothing sent to anyone)\n", count($results) - $failed, count($results));
    return $failed === 0 ? 0 : 1;
}

// Run only when started as a program (tests include this file to build the same synthetic image).
if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(sa_smoke_main($argv));
}
