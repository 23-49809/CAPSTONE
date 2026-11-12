<?php
declare(strict_types=1);

/*
 * Tests for OcrService. Everything is synthetic: fake OCR tools (small PHP
 * scripts) record each call, and generated images/PDFs contain no real data.
 * Integration checks use the real Tesseract and Poppler when installed, by
 * explicit path; they are skipped, and reported, when they are not.
 *
 *   php tests/ocr_service_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */

require_once __DIR__ . '/../app/services/OcrService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$passed = 0;
$failed = 0;
$skipped = 0;
$check = static function (string $name, bool $result) use (&$passed, &$failed): void {
    printf("%s %s\n", $result ? 'PASS' : 'FAIL', $name);
    $result ? $passed++ : $failed++;
};
$checkIntegration = static function (string $name, bool $result) use (&$passed, &$failed): void {
    printf("%s INTEGRATION %s\n", $result ? 'PASS' : 'FAIL', $name);
    $result ? $passed++ : $failed++;
};
$skipIntegration = static function (string $reason) use (&$skipped): void {
    printf("SKIP INTEGRATION %s\n", $reason);
    $skipped++;
};

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smart-assess-ocr-test-' . bin2hex(random_bytes(8));
$uploadDirectory = $testRoot . DIRECTORY_SEPARATOR . 'uploads';
$fakeBinDirectory = $testRoot . DIRECTORY_SEPARATOR . 'fake-bin';
if (!mkdir($testRoot, 0700) || !mkdir($uploadDirectory, 0700) || !mkdir($fakeBinDirectory, 0700)) {
    fwrite(STDERR, "Unable to create isolated synthetic test directories.\n");
    exit(2);
}
$testRoot = (string) realpath($testRoot);
$uploadDirectory = (string) realpath($uploadDirectory);
$fakeBinDirectory = (string) realpath($fakeBinDirectory);
$originalPath = getenv('PATH');
$errorLog = $testRoot . DIRECTORY_SEPARATOR . 'php-errors.log';
ini_set('log_errors', '1');
ini_set('error_log', $errorLog);

$configPath = $testRoot . DIRECTORY_SEPARATOR . 'fake-config.json';
$callsPath = $testRoot . DIRECTORY_SEPARATOR . 'calls.log';
$tinyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/y5sAAAAASUVORK5CYII=';

/** A PNG header that claims the given size; enough for the size check, nothing to decode. */
$pngHeader = static fn (int $width, int $height): string =>
    "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00" . pack('N', 0);
$jpegHeader = static fn (int $width, int $height): string =>
    "\xFF\xD8\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
    . "\xFF\xC0" . pack('n', 17) . "\x08" . pack('nn', $height, $width) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9";
$webpHeader = static function (int $width, int $height): string {
    $chunk = 'VP8X' . pack('V', 10) . "\x00\x00\x00\x00" . substr(pack('V', $width - 1), 0, 3) . substr(pack('V', $height - 1), 0, 3);
    return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
};

/**
 * Fake tools. Each reads fake-config.json and appends one JSON line per call to
 * calls.log: tool, arguments, start time, its working folder's permissions and
 * the environment it received.
 */
$fakePrelude = '$config = json_decode((string) @file_get_contents(' . var_export($configPath, true) . '), true) ?: [];'
    . '$tool = basename($argv[0]);'
    . 'file_put_contents(' . var_export($callsPath, true) . ', json_encode(["tool" => $tool, "args" => array_slice($argv, 1), "at" => microtime(true),'
    . ' "cwd_mode" => substr(sprintf("%o", fileperms(getcwd())), -4), "env" => getenv()]) . "\n", FILE_APPEND);'
    . 'if (!empty($config["sleep"][$tool])) { usleep((int) ($config["sleep"][$tool] * 1000000)); }'
    . '$opt = static function (string $name) use ($argv): ?string { $i = array_search($name, $argv, true); return $i === false ? null : ($argv[$i + 1] ?? null); };';
$fakeSources = [
    'pdfinfo' => 'fwrite(STDOUT, "Pages:          " . (int) ($config["pages_total"] ?? count($config["pages"] ?? [])) . "\n");',
    'pdftotext' => '$pages = $config["pages"] ?? []; $first = (int) $opt("-f"); $last = (int) $opt("-l");'
        . 'for ($p = $first; $p <= $last; $p++) { fwrite(STDOUT, (string) ($pages[$p - 1] ?? "")); if (($config["formfeed"] ?? true) || $first === $last) { fwrite(STDOUT, "\f"); } }',
    'pdftoppm' => '$page = (int) $opt("-f"); $prefix = $argv[count($argv) - 1];'
        . 'if (in_array($page, $config["render_fail"] ?? [], true)) { exit(3); }'
        . '$png = in_array($page, $config["render_huge"] ?? [], true)'
        . ' ? "\x89PNG\r\n\x1a\n" . pack("N", 13) . "IHDR" . pack("NN", 9000, 9000) . "\x08\x02\x00\x00\x00" . pack("N", 0)'
        . ' : base64_decode(' . var_export($tinyPngBase64, true) . ');'
        . 'file_put_contents($prefix . ".png", $png);',
    'tesseract' => '$image = $argv[1] ?? ""; $page = preg_match("/page-(\d+)\.png$/", $image, $m) ? (int) $m[1] : 0;'
        . 'if (!empty($config["marker"])) { file_put_contents($config["marker"], "ran"); }'
        . 'if (in_array($page, $config["ocr_fail"] ?? [], true)) { exit(1); }'
        . 'if ($page > 0 && array_key_exists((string) $page, $config["ocr_text"] ?? [])) { fwrite(STDOUT, $config["ocr_text"][(string) $page] . "\n"); exit(0); }'
        . 'fwrite(STDOUT, $page > 0 ? "OCR page " . $page . "\n" : ($config["image_text"] ?? "Synthetic image OCR text") . "\n");',
];
$writeTool = static function (string $directory, string $name, string $source): string {
    $path = $directory . DIRECTORY_SEPARATOR . $name;
    if (file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n" . $source) === false || !chmod($path, 0700)) {
        throw new RuntimeException('Unable to prepare a synthetic OCR tool.');
    }
    return $path;
};
$fakeTools = [];
foreach ($fakeSources as $name => $source) {
    $fakeTools[$name] = $writeTool($fakeBinDirectory, $name, $fakePrelude . $source);
}
$configure = static function (array $config) use ($configPath, $callsPath): void {
    file_put_contents($configPath, json_encode($config));
    @unlink($callsPath);
};
$calls = static function (?string $tool = null) use ($callsPath): array {
    $lines = is_file($callsPath) ? file($callsPath, FILE_IGNORE_NEW_LINES) : [];
    $all = array_map(static fn (string $line): array => json_decode($line, true), $lines ?: []);
    return $tool === null ? $all : array_values(array_filter($all, static fn (array $call): bool => $call['tool'] === $tool));
};
$service = static fn (int $processTimeout = 30, int $totalTimeout = 60, ?array $tools = null): OcrService =>
    new OcrService($uploadDirectory, $processTimeout, $totalTimeout, $tools ?? $fakeTools);
$pageMethods = static fn (array $result): array => array_map(static fn (array $page): string => $page['page'] . ':' . $page['method'] . ($page['error'] !== null ? ':' . $page['error'] : ''), $result['pages']);
$temporaryDirectories = static function (): array {
    return glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smart-assess-ocr-*') ?: [];
};
$throws = static function (callable $callback): bool {
    try {
        $callback();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};

/** A PDF whose pages are each either text or a JPEG image. */
$makePdf = static function (array $pages): string {
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', ''];
    $kids = [];
    foreach ($pages as $page) {
        $pageObject = count($objects) + 1;
        $kids[] = $pageObject . ' 0 R';
        if ($page['type'] === 'text') {
            $content = 'BT /F1 22 Tf 72 720 Td (' . $page['text'] . ') Tj ET';
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . ($pageObject + 1) . ' 0 R >> >> /Contents ' . ($pageObject + 2) . ' 0 R >>';
            $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        } else {
            $content = 'q 612 0 0 128 0 600 cm /Im0 Do Q';
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 ' . ($pageObject + 1) . ' 0 R >> >> /Contents ' . ($pageObject + 2) . ' 0 R >>';
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $page['width'] . ' /Height ' . $page['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
                . strlen($page['jpeg']) . " >>\nstream\n" . $page['jpeg'] . "\nendstream";
        }
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[$index + 1] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($index = 1; $index <= count($objects); $index++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
    }
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
};

try {
    $temporaryBefore = $temporaryDirectories();

    // ---- Fixtures ----
    $imagePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'synthetic.png';
    file_put_contents($imagePath, base64_decode($tinyPngBase64, true));
    $jpegBytes = null;
    if (extension_loaded('gd')) {
        $gdImage = imagecreatetruecolor(600, 125);
        imagefill($gdImage, 0, 0, imagecolorallocate($gdImage, 255, 255, 255));
        imagestring($gdImage, 5, 20, 50, 'SMART ASSESS SCANNED PAGE', imagecolorallocate($gdImage, 0, 0, 0));
        imagepng($gdImage, $imagePath);
        ob_start();
        imagejpeg($gdImage, null, 95);
        $jpegBytes = (string) ob_get_clean();
    }
    $pdfPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'synthetic.pdf';
    file_put_contents($pdfPath, "%PDF-1.4\n% synthetic test PDF\n");
    $unsupportedPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'spoofed.png';
    file_put_contents($unsupportedPath, 'synthetic plain text');
    mkdir($testRoot . DIRECTORY_SEPARATOR . 'outside', 0700);
    $outsidePath = $testRoot . DIRECTORY_SEPARATOR . 'outside' . DIRECTORY_SEPARATOR . 'synthetic.png';
    file_put_contents($outsidePath, base64_decode($tinyPngBase64, true));
    $largePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'oversized.png';
    $handle = fopen($largePath, 'wb');
    ftruncate($handle, 5 * 1024 * 1024);
    fclose($handle);
    $justUnderPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'just-under.png';
    file_put_contents($justUnderPath, $pngHeader(100, 100) . str_repeat("\0", 5 * 1024 * 1024 - 1 - strlen($pngHeader(100, 100))));
    $marker = $testRoot . DIRECTORY_SEPARATOR . 'tesseract-ran';
    $pdfHashBefore = hash_file('sha256', $pdfPath);

    // ================= Existing behaviour =================
    $configure([]);
    $imageHashBefore = hash_file('sha256', $imagePath);
    $result = $service()->extract($imagePath);
    $check('image OCR returns text through Tesseract', $result['success'] && $result['text'] === 'Synthetic image OCR text'
        && $result['engine'] === 'tesseract' && $result['extraction_method'] === 'image_ocr' && $result['pages_processed'] === 1
        && $pageMethods($result) === ['1:ocr']);
    $check('image extraction cleans its temporary folder and leaves the original unchanged',
        $temporaryDirectories() === $temporaryBefore && hash_file('sha256', $imagePath) === $imageHashBefore);
    $check('programs run inside a private 0700 temporary folder', ($calls('tesseract')[0]['cwd_mode'] ?? '') === '0700');

    $configure(['pages' => ['Synthetic embedded PDF text, page one', 'Synthetic embedded PDF text, page two']]);
    $result = $service()->extract($pdfPath);
    $check('PDF embedded text is used and no page is OCR\'d', $result['success'] && $result['extraction_method'] === 'embedded_text'
        && $result['engine'] === 'poppler' && $result['pages_processed'] === 2
        && $result['text'] === "Synthetic embedded PDF text, page one\nSynthetic embedded PDF text, page two"
        && $calls('pdftoppm') === [] && $calls('tesseract') === []);

    $configure(['pages' => [' ', "\n"]]);
    $result = $service()->extract($pdfPath);
    $check('a scanned PDF is rendered and OCR\'d page by page', $result['success'] && $result['extraction_method'] === 'rasterized_ocr'
        && $result['engine'] === 'poppler+tesseract' && $result['text'] === "OCR page 1\nOCR page 2" && $result['pages_processed'] === 2
        && $pageMethods($result) === ['1:ocr', '2:ocr']);

    $configure(['pages_total' => 12, 'pages' => array_fill(0, 12, '')]);
    $result = $service()->extract($pdfPath);
    $renderedPages = array_map(static fn (array $call): string => $call['args'][1], $calls('pdftoppm'));
    $check('PDFs stay limited to the first 10 pages, with a warning', $result['success'] && $result['pages_processed'] === 10
        && in_array('Processing was limited to the first 10 pages of this document.', $result['warnings'], true)
        && $renderedPages === array_map('strval', range(1, 10)) && count($calls('tesseract')) === 10
        && ($calls('pdftotext')[0]['args'][3] ?? null) === '10');

    $check('the actual MIME type is verified', $service()->extract($unsupportedPath)['error_code'] === 'unsupported_mime_type');
    $check('a file of exactly 5 MB is rejected', $service()->extract($largePath)['error_code'] === 'file_too_large');
    $configure([]);
    $check('a file one byte under 5 MB is accepted for processing', $service()->extract($justUnderPath)['error_code'] !== 'file_too_large'
        && count($calls('tesseract')) === 1);
    $check('a file outside the private upload folder is rejected', $service()->extract($outsidePath)['error_code'] === 'outside_upload_directory');
    $symlinkPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'link-out.png';
    symlink($outsidePath, $symlinkPath);
    $check('a link pointing outside the private upload folder is rejected', $service()->extract($symlinkPath)['error_code'] === 'outside_upload_directory');
    unlink($symlinkPath);
    $check('a missing file returns a safe error', $service()->extract($uploadDirectory . '/missing.png')['error_code'] === 'file_missing');
    $check('a directory is rejected as a file', $service()->extract($uploadDirectory)['error_code'] === 'file_unreadable');

    $configure(['sleep' => ['tesseract' => 5]]);
    $started = microtime(true);
    $result = $service(1, 60)->extract($imagePath);
    $check('the per-process timeout still applies inside a long total deadline', $result['error_code'] === 'process_timeout'
        && $result['text'] === '' && microtime(true) - $started < 2.5);
    $check('temporary files are cleaned after a timeout', $temporaryDirectories() === $temporaryBefore);

    // ================= 1. Image dimensions =================
    $oversized = [
        'PNG 6000 x 5000 (30 MP)' => ['huge.png', $pngHeader(6000, 5000)],
        'JPEG 8000 x 8000 (64 MP)' => ['huge.jpg', $jpegHeader(8000, 8000)],
        'WebP 10000 x 5000 (50 MP)' => ['huge.webp', $webpHeader(10000, 5000)],
        'PNG 25000 x 1001 (just over 25 MP)' => ['wide.png', $pngHeader(25000, 1001)],
    ];
    foreach ($oversized as $label => [$name, $bytes]) {
        $path = $uploadDirectory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $bytes);
        $configure(['marker' => $marker]);
        @unlink($marker);
        $result = $service()->extract($path);
        $check('oversized image rejected before Tesseract runs: ' . $label, $result['success'] === false
            && $result['error_code'] === 'image_too_large' && $result['text'] === '' && !is_file($marker) && $calls() === []);
    }
    $atLimitPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'at-limit.png';
    file_put_contents($atLimitPath, $pngHeader(5000, 5000));
    $configure([]);
    $result = $service()->extract($atLimitPath);
    $check('an image of exactly 25 MP is allowed through to Tesseract', $result['success'] && count($calls('tesseract')) === 1);
    $check('the limit is 25 megapixels', OcrService::MAX_IMAGE_PIXELS === 25_000_000);
    $noSizePath = $uploadDirectory . DIRECTORY_SEPARATOR . 'no-size.png';
    file_put_contents($noSizePath, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 4));
    $configure([]);
    $result = $service()->extract($noSizePath);
    $check('an image whose size cannot be read is refused without running Tesseract', $result['success'] === false
        && in_array($result['error_code'], ['unreadable_document', 'unsupported_mime_type'], true) && $calls() === []);
    $configure(['pages' => ['', 'Embedded text long enough to count as a real page'], 'render_huge' => [1]]);
    $result = $service()->extract($pdfPath);
    $check('an oversized rendered page is reported and not OCR\'d', $result['success'] && $pageMethods($result) === ['1:not_processed:image_too_large', '2:embedded_text']
        && $calls('tesseract') === [] && in_array('Page 1 could not be processed (image_too_large).', $result['warnings'], true));

    // ================= 2. Overall deadline =================
    $configure(['pages' => [''], 'sleep' => ['pdfinfo' => 10]]);
    $started = microtime(true);
    $result = $service(30, 2)->extract($pdfPath);
    $elapsed = microtime(true) - $started;
    $check('a single program is stopped at the overall deadline, not its own 30 s timeout', $result['error_code'] === 'deadline_exceeded'
        && $elapsed >= 1.9 && $elapsed < 2.6);
    $check('nothing else starts after that', count($calls()) === 1);

    $configure(['pages' => array_fill(0, 10, ''), 'sleep' => ['tesseract' => 0.5, 'pdftoppm' => 0.1]]);
    $started = microtime(true);
    $result = $service(5, 2)->extract($pdfPath);
    $elapsed = microtime(true) - $started;
    $tesseractCalls = $calls('tesseract');
    $lateStarts = array_filter($calls(), static fn (array $call): bool => $call['at'] >= $started + 2.0);
    $check('ten pages of individually short OCR cannot run past the overall deadline', $result['success'] === false
        && $result['error_code'] === 'deadline_exceeded' && $elapsed < 2.6 && count($tesseractCalls) < 10);
    $check('no program starts after the deadline', $lateStarts === []);
    $notProcessed = array_filter($result['pages'], static fn (array $page): bool => $page['method'] === 'not_processed' && $page['error'] === 'deadline_exceeded');
    $check('pages left unprocessed by the deadline are listed', count($result['pages']) === 10 && count($notProcessed) >= 1 && $result['text'] === '');

    $configure(['pages' => [''], 'sleep' => ['pdftotext' => 1.2, 'pdftoppm' => 1.2]]);
    $started = microtime(true);
    $result = $service(30, 2)->extract($pdfPath);
    $elapsed = microtime(true) - $started;
    $check('a later program gets only the time that remains', $result['error_code'] === 'deadline_exceeded' && $elapsed < 2.6
        && count($calls('pdftoppm')) === 1 && $calls('tesseract') === []);
    $check('temporary files are cleaned after the deadline', $temporaryDirectories() === $temporaryBefore);
    $check('total timeout must be 1 to 300 seconds', $throws(static fn () => new OcrService($uploadDirectory, 30, 0))
        && $throws(static fn () => new OcrService($uploadDirectory, 30, 301)));

    // ================= 3. Executables =================
    $pathTool = $writeTool($testRoot . DIRECTORY_SEPARATOR . 'outside', 'tesseract', 'file_put_contents(' . var_export($marker, true) . ', "path"); fwrite(STDOUT, "FROM PATH\n");');
    putenv('PATH=' . dirname($pathTool));
    @unlink($marker);
    $result = (new OcrService($uploadDirectory))->extract($imagePath);
    $check('PATH is never searched for a program', !is_file($marker) && !str_contains($result['text'], 'FROM PATH'));
    putenv('PATH=' . (is_string($originalPath) ? $originalPath : ''));

    $configure(['marker' => $marker]);
    $missing = ['missing file' => $testRoot . '/no-such-tool', 'directory' => $fakeBinDirectory];
    $plainFile = $testRoot . DIRECTORY_SEPARATOR . 'not-executable';
    file_put_contents($plainFile, '#!/bin/sh');
    chmod($plainFile, 0600);
    $missing['file without execute permission'] = $plainFile;
    foreach ($missing as $label => $path) {
        $result = $service(30, 60, ['tesseract' => $path] + $fakeTools)->extract($imagePath);
        $check('configured tesseract that is a ' . $label . ' reports missing_tool', $result['error_code'] === 'missing_tool');
    }
    $worldWritable = $writeTool($testRoot, 'tesseract-world', $fakePrelude . $fakeSources['tesseract']);
    chmod($worldWritable, 0777);
    @unlink($marker);
    $result = $service(30, 60, ['tesseract' => $worldWritable] + $fakeTools)->extract($imagePath);
    $check('a world-writable program is refused and never run', $result['error_code'] === 'untrusted_tool' && !is_file($marker));
    $openDirectory = $testRoot . DIRECTORY_SEPARATOR . 'open-bin';
    mkdir($openDirectory, 0700);
    chmod($openDirectory, 0777);
    $inOpenDirectory = $writeTool($openDirectory, 'tesseract', $fakePrelude . $fakeSources['tesseract']);
    $result = $service(30, 60, ['tesseract' => $inOpenDirectory] + $fakeTools)->extract($imagePath);
    $check('a program in a world-writable folder is refused and never run', $result['error_code'] === 'untrusted_tool' && !is_file($marker));
    chmod($openDirectory, 0700);
    $check('tool errors expose no paths', !str_contains(json_encode($result), $testRoot));
    $check('a relative program path is refused', $throws(static fn () => new OcrService($uploadDirectory, 30, 60, ['tesseract' => 'tesseract'])));
    $check('a program path with a NUL byte is refused', $throws(static fn () => new OcrService($uploadDirectory, 30, 60, ['tesseract' => "/usr/bin/tesseract\0x"])));
    $check('only the four OCR tools can be configured', $throws(static fn () => new OcrService($uploadDirectory, 30, 60, ['sh' => '/bin/sh']))
        && $throws(static fn () => new OcrService($uploadDirectory, 30, 60, [0 => '/bin/sh'])));
    $check('a configured path must be text', $throws(static fn () => new OcrService($uploadDirectory, 30, 60, ['tesseract' => ['/bin/sh']])));

    putenv('SMART_ASSESS_TEST_SECRET=must-not-reach-tools');
    $configure([]);
    $service()->extract($imagePath);
    $environment = $calls('tesseract')[0]['env'] ?? [];
    putenv('SMART_ASSESS_TEST_SECRET');
    $check('programs get a fixed PATH and none of the web server\'s other variables', ($environment['PATH'] ?? null) === '/usr/bin:/bin'
        && !array_key_exists('SMART_ASSESS_TEST_SECRET', $environment) && !array_key_exists('HOME', $environment));
    $trickyName = $uploadDirectory . DIRECTORY_SEPARATOR . 'a b;touch PWNED $(touch PWNED2) `touch PWNED3`.png';
    copy($imagePath, $trickyName);
    $configure([]);
    $result = $service()->extract($trickyName);
    $check('a file name with shell characters is passed as one argument, never run', $result['success']
        && ($calls('tesseract')[0]['args'][0] ?? null) === $trickyName
        && glob($testRoot . '/{,*/}PWNED*', GLOB_BRACE) === [] && glob(sys_get_temp_dir() . '/smart-assess-ocr-*/PWNED*') === []);

    // ================= 4. Mixed-content PDFs =================
    $configure(['pages' => ['Page one has a full embedded text layer.', '', 'Page 3']]);
    $result = $service()->extract($pdfPath);
    $check('a mixed PDF keeps embedded text and OCRs only the scanned pages', $result['success'] && $result['extraction_method'] === 'mixed'
        && $result['engine'] === 'poppler+tesseract' && $pageMethods($result) === ['1:embedded_text', '2:ocr', '3:ocr']
        && $result['text'] === "Page one has a full embedded text layer.\nOCR page 2\nOCR page 3"
        && array_map(static fn (array $call): string => $call['args'][1], $calls('pdftoppm')) === ['2', '3']);
    $check('a page with only a short text layer (a page number) is treated as scanned', in_array('3:ocr', $pageMethods($result), true));

    $configure(['pages' => ['Page one has a full embedded text layer.', '', ''], 'ocr_fail' => [3]]);
    $result = $service()->extract($pdfPath);
    $check('a page that cannot be OCR\'d is reported, and the rest are kept', $result['success'] && $result['pages_processed'] === 2
        && $pageMethods($result) === ['1:embedded_text', '2:ocr', '3:not_processed:unreadable_document']
        && in_array('Page 3 could not be processed (unreadable_document).', $result['warnings'], true)
        && $result['text'] === "Page one has a full embedded text layer.\nOCR page 2");
    $configure(['pages' => ['', 'Page two has a full embedded text layer.'], 'render_fail' => [1]]);
    $result = $service()->extract($pdfPath);
    $check('a page that cannot be rendered is reported', $result['success'] && $pageMethods($result) === ['1:not_processed:render_failed', '2:embedded_text']);
    $configure(['pages' => ['Page one has a full embedded text layer.', '']]);
    $result = $service(30, 60, ['tesseract' => $testRoot . '/no-such-tool'] + $fakeTools)->extract($pdfPath);
    $check('without Tesseract, embedded pages are kept and scanned pages are reported', $result['success']
        && $pageMethods($result) === ['1:embedded_text', '2:not_processed:missing_tool']);
    $configure(['pages' => ['', '']]);
    $result = $service(30, 60, ['tesseract' => $testRoot . '/no-such-tool'] + $fakeTools)->extract($pdfPath);
    $check('without Tesseract, a fully scanned PDF fails as before', $result['success'] === false && $result['error_code'] === 'missing_tool'
        && $pageMethods($result) === ['1:not_processed:missing_tool', '2:not_processed:missing_tool']);
    $configure(['pages' => ['', ''], 'ocr_fail' => [1, 2]]);
    $result = $service()->extract($pdfPath);
    $check('if no page can be processed, the extraction fails and lists every page', $result['success'] === false
        && $result['error_code'] === 'unreadable_document' && count($result['pages']) === 2);
    $configure(['pages' => ['First page has a full embedded text layer.', 'Second page has a full embedded text layer.'], 'formfeed' => false]);
    $result = $service()->extract($pdfPath);
    $check('without page breaks in the output, each page is read on its own so text stays on its page', $result['success']
        && $result['text'] === "First page has a full embedded text layer.\nSecond page has a full embedded text layer."
        && count($calls('pdftotext')) === 3);
    $configure(['pages' => ['Page 1'], 'ocr_text' => ['1' => '']]);
    $result = $service()->extract($pdfPath);
    $check('if OCR finds nothing, a short text layer is kept', $result['success'] && $result['text'] === 'Page 1');
    $check('the PDF itself is never changed', hash_file('sha256', $pdfPath) === $pdfHashBefore);

    // ================= Logs and clean-up =================
    $source = (string) file_get_contents(__DIR__ . '/../app/services/OcrService.php');
    $logged = is_file($errorLog) ? (string) file_get_contents($errorLog) : '';
    $check('no OCR or embedded text reaches the error log', !preg_match('/OCR page|Synthetic|embedded text layer/', $logged));
    $check('the service has no logging or printing calls', !preg_match('/\b(error_log|syslog|var_dump|print_r|printf|echo|print)\b\s*\(/', $source));
    $check('PATH is not read by the service', !str_contains($source, "getenv('PATH')"));
    $check('every temporary folder is removed', $temporaryDirectories() === $temporaryBefore);

    // ================= Integration (real tools, by explicit path) =================
    $realTools = [];
    foreach (array_keys($fakeSources) as $tool) {
        foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'] as $directory) {
            if (is_executable($directory . '/' . $tool)) {
                $realTools[$tool] = $directory . '/' . $tool;
                break;
            }
        }
    }
    $missingRealTools = array_diff(array_keys($fakeSources), array_keys($realTools));
    if ($missingRealTools !== []) {
        $skipIntegration('local OCR tools unavailable: ' . implode(', ', $missingRealTools));
    } elseif (!is_string($jpegBytes) || $jpegBytes === '') {
        $skipIntegration('GD extension unavailable for synthetic image fixtures');
    } else {
        $real = new OcrService($uploadDirectory, 30, 60, $realTools);
        $embeddedPdf = $uploadDirectory . DIRECTORY_SEPARATOR . 'real-embedded.pdf';
        file_put_contents($embeddedPdf, $makePdf([['type' => 'text', 'text' => 'SMART ASSESS EMBEDDED TEST PAGE']]));
        $result = $real->extract($embeddedPdf);
        $checkIntegration('real Poppler extracts embedded PDF text', $result['success'] && $result['extraction_method'] === 'embedded_text'
            && str_contains($result['text'], 'SMART ASSESS EMBEDDED TEST PAGE'));

        $result = $real->extract($imagePath);
        $checkIntegration('real Tesseract reads a synthetic image', $result['success'] && stripos($result['text'], 'SMART') !== false);

        $scannedPdf = $uploadDirectory . DIRECTORY_SEPARATOR . 'real-scanned.pdf';
        file_put_contents($scannedPdf, $makePdf([['type' => 'image', 'jpeg' => $jpegBytes, 'width' => 600, 'height' => 125]]));
        $result = $real->extract($scannedPdf);
        $checkIntegration('real Poppler and Tesseract OCR a scanned PDF', $result['success'] && $result['extraction_method'] === 'rasterized_ocr'
            && stripos($result['text'], 'SCANNED') !== false);

        $mixedPdf = $uploadDirectory . DIRECTORY_SEPARATOR . 'real-mixed.pdf';
        file_put_contents($mixedPdf, $makePdf([
            ['type' => 'text', 'text' => 'SMART ASSESS EMBEDDED TEXT ON PAGE ONE'],
            ['type' => 'image', 'jpeg' => $jpegBytes, 'width' => 600, 'height' => 125],
            ['type' => 'text', 'text' => 'SMART ASSESS EMBEDDED TEXT ON PAGE THREE'],
        ]));
        $result = $real->extract($mixedPdf);
        $checkIntegration('real mixed PDF: embedded pages kept, scanned page OCR\'d', $result['success'] && $result['extraction_method'] === 'mixed'
            && $pageMethods($result) === ['1:embedded_text', '2:ocr', '3:embedded_text']
            && str_contains($result['text'], 'PAGE ONE') && str_contains($result['text'], 'PAGE THREE') && stripos($result['text'], 'SCANNED') !== false);
        $checkIntegration('real tools leave no temporary folders', $temporaryDirectories() === $temporaryBefore);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Unexpected test setup or execution failure: ' . $exception::class . ' at line ' . $exception->getLine() . "\n");
    $failed++;
} finally {
    if (is_string($originalPath)) {
        putenv('PATH=' . $originalPath);
    } else {
        putenv('PATH');
    }
    $removeTestDirectory = static function (string $directory) use (&$removeTestDirectory): void {
        @chmod($directory, 0700);
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $removeTestDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    };
    if (is_dir($testRoot)) {
        $removeTestDirectory($testRoot);
    }
}

printf("%d passed, %d failed, %d integration checks skipped\n", $passed, $failed, $skipped);
exit($failed === 0 ? 0 : 1);
