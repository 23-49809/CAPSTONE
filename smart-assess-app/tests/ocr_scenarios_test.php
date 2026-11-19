<?php
declare(strict_types=1);

/*
 * OCR scenarios with the real local tools (Tesseract, Poppler) on generated, FICTIONAL
 * "Philippine Identification Card"-style images and PDFs: a generic layout, the holder
 * "DELA CRUZ / JUAN / SANTOS", the number 1234-5678-9012-3456 and the printed words
 * "SYNTHETIC TEST CARD - NOT A REAL ID". No real identity document is used or stored; every
 * file is made in a private temporary folder and removed at the end. Nothing printed here
 * contains OCR text.
 *
 * For each scenario the full worker-side path is checked: OcrService::extract() with the ID
 * completeness check the worker passes for an ID slot (DocumentAnalysis::ocrCompleteness), then the
 * printed-title evidence (DocumentAnalysis::kindEvidence) and the name that would reach Staff
 * when the model gives none (DocumentAnalysis::keepQuotedDetails with the PhilSys field reader).
 * A name is either the card's own name or nothing: never another name.
 *
 *   php tests/ocr_scenarios_test.php     (needs tesseract, pdfinfo, pdftotext, pdftoppm, GD with
 *                                         FreeType and a TrueType font; exits 2 without them)
 */

require_once __DIR__ . '/../app/services/OcrService.php';
require_once __DIR__ . '/../app/services/DocumentAnalysis.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};

$tools = [];
foreach (['tesseract', 'pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
    foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'] as $directory) {
        if (is_executable("{$directory}/{$tool}")) {
            $tools[$tool] = "{$directory}/{$tool}";
            break;
        }
    }
}
$fonts = [
    ['/System/Library/Fonts/Supplemental/Arial.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf'],
    ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
    ['/usr/share/fonts/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'],
];
$font = null;
foreach ($fonts as $pair) {
    if (is_file($pair[0]) && is_file($pair[1])) {
        $font = $pair;
        break;
    }
}
if (count($tools) < 4 || $font === null || !function_exists('imagettftext') || !OcrImagePreparer::available()) {
    fwrite(STDERR, "Local OCR tools, GD with FreeType or a TrueType font are missing. Nothing was checked.\n");
    exit(2);
}
[$regular, $bold] = $font;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sa-ocr-scenarios-' . bin2hex(random_bytes(4));
mkdir($root, 0700);
register_shutdown_function(static function () use ($root): void {
    foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root);
});

// ---- Fictional card ----
$card = static function (bool $withLastName = true, bool $withLabels = true) use ($regular, $bold): GdImage {
    $w = 1300;
    $h = 820;
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 244, 247, 251));
    $blue = imagecolorallocate($im, 196, 218, 238);
    $pink = imagecolorallocate($im, 240, 205, 214);
    for ($k = 0; $k < 26; $k++) {   // light wavy security-pattern lines
        $previous = null;
        for ($x = 0; $x <= $w; $x += 6) {
            $y = (int) (80 + $k * 28 + 18 * sin($x / (40 + $k * 3) + $k));
            if ($previous !== null) {
                imageline($im, $previous[0], $previous[1], $x, $y, $k % 2 ? $blue : $pink);
            }
            $previous = [$x, $y];
        }
    }
    $navy = imagecolorallocate($im, 20, 45, 100);
    $ink = imagecolorallocate($im, 25, 25, 30);
    $grey = imagecolorallocate($im, 85, 85, 95);
    imagettftext($im, 22, 0, 360, 60, $navy, $bold, 'REPUBLIKA NG PILIPINAS');
    imagettftext($im, 15, 0, 360, 88, $grey, $regular, 'Republic of the Philippines');
    imagettftext($im, 26, 0, 360, 135, $navy, $bold, 'PAMBANSANG PAGKAKAKILANLAN');
    imagettftext($im, 16, 0, 360, 165, $grey, $regular, 'Philippine Identification Card');
    imagefilledrectangle($im, 50, 220, 320, 560, imagecolorallocate($im, 184, 194, 204));
    imagettftext($im, 18, 0, 60, 610, $ink, $bold, '1234-5678-9012-3456');
    $rows = [['Apelyido/Last Name', $withLastName ? 'DELA CRUZ' : '', 34], ['Mga Pangalan/Given Names', 'JUAN', 34], ['Gitnang Apelyido/Middle Name', 'SANTOS', 34],
        ['Petsa ng Kapanganakan/Date of Birth', 'JANUARY 01, 1990', 26], ['Tirahan/Address', '123 RIZAL ST., POBLACION, MABINI, BATANGAS', 20]];
    $y = 245;
    foreach ($rows as [$label, $value, $size]) {
        if ($withLabels) {
            imagettftext($im, 14, 0, 370, $y, $grey, $regular, $label);
        }
        if ($value !== '') {
            imagettftext($im, $size, 0, 370, $y + $size + 10, $ink, $bold, $value);
        }
        $y += $size + 62;
    }
    imagettftext($im, 12, 0, 50, 795, $grey, $regular, 'SYNTHETIC TEST CARD - NOT A REAL ID');
    return $im;
};
$save = static function (GdImage $im, string $name) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . $name;
    str_ends_with($name, '.png') ? imagepng($im, $path) : imagejpeg($im, $path, str_contains($name, 'phone') ? 75 : 88);
    return $path;
};
$scaled = static fn (GdImage $im, int $width): GdImage => imagescale($im, $width, -1, IMG_BILINEAR_FIXED) ?: $im;
$onBackground = static function (GdImage $cardImage, int $bw, int $bh, float $angle, int $cardWidth, array $bg) use ($scaled): GdImage {
    $c = $scaled($cardImage, $cardWidth);
    $rotated = imagerotate($c, $angle, imagecolorallocate($c, ...$bg));
    $canvas = imagecreatetruecolor($bw, $bh);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$bg));
    imagecopy($canvas, $rotated, (int) (($bw - imagesx($rotated)) / 2), (int) (($bh - imagesy($rotated)) / 2), 0, 0, imagesx($rotated), imagesy($rotated));
    return $canvas;
};
/** A photo of the card held at an angle: its top edge narrower (keystone $top) and turned $angle degrees. */
$tilted = static function (GdImage $cardImage, float $top, float $angle): GdImage {
    [$w, $h] = [imagesx($cardImage), imagesy($cardImage)];
    $out = imagecreatetruecolor($w, $h);
    imagefill($out, 0, 0, imagecolorallocate($out, 225, 225, 225));
    for ($y = 0; $y < $h; $y++) {
        $rowWidth = (int) round($w * ($top + (1 - $top) * $y / ($h - 1)));
        imagecopyresampled($out, $cardImage, (int) (($w - $rowWidth) / 2), $y, 0, $y, $rowWidth, 1, $w, 1);
    }
    return imagerotate($out, $angle, imagecolorallocate($out, 225, 225, 225)) ?: $out;
};
/** A minimal PDF: per page an optional JPEG image (top of the page) and optional Helvetica text lines. */
$pdf = static function (string $name, array $pages) use ($root): string {
    $objects = [];
    $kids = [];
    $add = static function (string $body) use (&$objects): int {
        $objects[] = $body;
        return count($objects);
    };
    $fontObject = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
    $pagesObject = $add('PAGES');
    foreach ($pages as $page) {
        $content = '';
        $resources = "/Font << /F1 {$fontObject} 0 R >>";
        if (isset($page['image'])) {
            [$iw, $ih] = getimagesize($page['image']);
            $data = (string) file_get_contents($page['image']);
            $image = $add("<< /Type /XObject /Subtype /Image /Width {$iw} /Height {$ih} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n{$data}\nendstream");
            $resources .= " /XObject << /Im1 {$image} 0 R >>";
            $height = (int) round(595 * $ih / $iw);
            $content .= "q 595 0 0 {$height} 0 " . (842 - $height) . " cm /Im1 Do Q\n";
        }
        $y = 800;
        foreach ($page['text'] ?? [] as $line) {
            $content .= "BT /F1 12 Tf 40 {$y} Td (" . addcslashes($line, '()\\') . ") Tj ET\n";
            $y -= 18;
        }
        $stream = $add('<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream");
        $kids[] = $add("<< /Type /Page /Parent {$pagesObject} 0 R /MediaBox [0 0 595 842] /Resources << {$resources} >> /Contents {$stream} 0 R >>");
    }
    $objects[$pagesObject - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn (int $k): string => "{$k} 0 R", $kids)) . '] /Count ' . count($kids) . ' >>';
    $catalog = $add("<< /Type /Catalog /Pages {$pagesObject} 0 R >>");
    $out = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($out);
        $out .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n" . implode('', array_map(static fn (int $o): string => sprintf("%010d 00000 n \n", $o), $offsets));
    $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalog} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    $path = $root . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $out);
    return $path;
};

// ---- Scenarios: [file, expected name reaching Staff ('' = unverified), title evidence expected] ----
$name = 'DELA CRUZ JUAN SANTOS';
$blurry = $card();
for ($i = 0; $i < 6; $i++) {
    imagefilter($blurry, IMG_FILTER_GAUSSIAN_BLUR);
}
$lowContrast = $card();
imagefilter($lowContrast, IMG_FILTER_CONTRAST, 75);
imagefilter($lowContrast, IMG_FILTER_BRIGHTNESS, 45);
$noisy = $card();
mt_srand(7);
for ($i = 0; $i < 90000; $i++) {
    $v = mt_rand(120, 230);
    imagesetpixel($noisy, mt_rand(0, 1299), mt_rand(0, 819), imagecolorallocate($noisy, $v, $v, $v));
}
// A photo of the card on a dark table, turned 8 degrees and soft (its small labels are blurred).
$phone = $onBackground($card(), 1600, 1200, -8, 980, [74, 58, 46]);
imagefilter($phone, IMG_FILTER_GAUSSIAN_BLUR);
$photo = $save($card(), 'clear-photo.jpg');
$scenarios = [
    'clear PNG' => [$save($card(), 'clear.png'), $name, true],
    'clear JPEG photo' => [$photo, $name, true],
    'blurry' => [$save($blurry, 'blurry.jpg'), $name, true],
    'turned 90 degrees' => [$save(imagerotate($card(), 90, 0), 'rotated-90.jpg'), $name, true],
    'upside down' => [$save(imagerotate($card(), 180, 0), 'rotated-180.jpg'), $name, true],
    'skewed 6 degrees on a page' => [$save($onBackground($card(), 1500, 1050, 6, 1300, [235, 235, 235]), 'skewed.jpg'), $name, true],
    'low contrast' => [$save($lowContrast, 'low-contrast.jpg'), $name, true],
    'small (560 px wide)' => [$save($scaled($card(), 560), 'small.jpg'), $name, true],
    'noisy' => [$save($noisy, 'noisy.jpg'), $name, true],
    'without the last name' => [$save($card(false), 'missing-last-name.jpg'), '', true],
    'without printed field labels (values only: fields cannot be told apart)' => [$save($card(true, false), 'no-labels.jpg'), '', true],
    'held at an angle, read confidently but without the name at first (keystone 0.88, 2 degrees)' => [$save($tilted($card(), 0.88, -2), 'tilted-mild.jpg'), $name, true],
    'strongly tilted (keystone 0.75, 12 degrees)' => [$save($tilted($card(), 0.75, 12), 'tilted-strong.jpg'), $name, true],
    'phone photo on a dark table, turned 8 degrees and soft' => [$save($phone, 'phone-photo.jpg'), $name, true],
    'PDF with a text layer' => [$pdf('embedded.pdf', [['text' => ['REPUBLIKA NG PILIPINAS', 'PAMBANSANG PAGKAKAKILANLAN', 'Philippine Identification Card',
        'Apelyido/Last Name', 'DELA CRUZ', 'Mga Pangalan/Given Names', 'JUAN', 'Gitnang Apelyido/Middle Name', 'SANTOS']]]), $name, true],
    'scanned PDF' => [$pdf('scanned.pdf', [['image' => $photo]]), $name, true],
    'two-page PDF (cover text, then the scanned card)' => [$pdf('multipage.pdf', [['text' => ['Cover page: the applicant attached a copy of the Philippine Identification Card on the next page.']], ['image' => $photo]]), $name, true],
    'PDF whose text layer is only the header, over the scanned card' => [$pdf('header-only.pdf', [['image' => $photo, 'text' => ['REPUBLIKA NG PILIPINAS - PAMBANSANG PAGKAKAKILANLAN']]]), $name, true],
];

$service = new OcrService($root, 30, 60, $tools);
// As the worker does for an ID slot: a reading without the ID's name fields gets further passes.
$idCompleteness = static fn (string $text): ?float => DocumentAnalysis::ocrCompleteness('requesterId', $text);
$slowest = 0.0;
$readNames = 0;
$titles = 0;
foreach ($scenarios as $label => [$path, $expected, $title]) {
    $before = hash_file('sha256', $path);
    $started = microtime(true);
    $result = $service->extract($path, $idCompleteness);
    $seconds = microtime(true) - $started;
    $slowest = max($slowest, $seconds);
    $text = $result['success'] ? DocumentAnalysis::normalizeText($result['text']) : '';
    [$output, $matches] = DocumentAnalysis::keepQuotedDetails(['documents' => [['key' => 'requesterId', 'holder_name' => '', 'property_number' => '']]], ['requesterId' => ['text' => $text]]);
    $held = (string) $output['documents'][0]['holder_name'];
    $hasTitle = in_array('philsys_national_id', DocumentAnalysis::kindEvidence($text), true);
    $readNames += $held === $expected ? 1 : 0;
    $titles += $hasTitle === $title ? 1 : 0;
    $check("{$label}: read (success) within 15 s", $result['success'] && $seconds < 15, [$result['error_code'], round($seconds, 2)]);
    $check("{$label}: " . ($expected === '' ? 'the name stays unverified (no last name on the card)' : 'the name reaching Staff is the card\'s own name'),
        $held === $expected && ($expected === '' ? $matches['requesterId'] === 'none' : $matches['requesterId'] === 'parts'), [$held === '' ? 'no name' : 'other name', $matches['requesterId']]);
    $check("{$label}: the printed title is found", $hasTitle === $title);
    $check("{$label}: the uploaded file is unchanged", hash_file('sha256', $path) === $before);
}

// Without the completeness check (as before this change), the mildly tilted card's confident first
// reading is accepted although it lacks the name: shown for comparison, not required.
$plain = $service->extract($root . DIRECTORY_SEPARATOR . 'tilted-mild.jpg');
$withoutCheck = DocumentAnalysis::philsysName($plain['success'] ? $plain['text'] : '') !== null ? 'name read' : 'name missing';

// Memory and time on a 12 MP phone photo (4000 x 3000) of the card on a dark table, in a fresh PHP
// process with the worker pool's memory_limit (512M), so its peak is this extraction's alone.
$bigPhoto = $save(imagescale($phone, 4000, 3000, IMG_BILINEAR_FIXED), 'phone-12mp.jpg');
$probe = $root . DIRECTORY_SEPARATOR . 'probe.php';
file_put_contents($probe, '<?php require ' . var_export(__DIR__ . '/../app/services/OcrService.php', true) . '; require ' . var_export(__DIR__ . '/../app/services/DocumentAnalysis.php', true) . ';'
    . '$scale = PHP_OS_FAMILY === "Darwin" ? 1 : 1024; $before = getrusage()["ru_maxrss"] * $scale; $t = microtime(true);'
    . '$r = (new OcrService($argv[1], 30, 60, json_decode($argv[3], true)))->extract($argv[2], static fn (string $x): ?float => DocumentAnalysis::ocrCompleteness("requesterId", $x));'
    . 'echo json_encode(["ok" => $r["success"], "s" => microtime(true) - $t, "grow" => getrusage()["ru_maxrss"] * $scale - $before, "name" => DocumentAnalysis::philsysName($r["text"]) !== null]);');
$big = json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg($probe) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($bigPhoto) . ' ' . escapeshellarg((string) json_encode($tools))), true) ?: [];
$bigSeconds = (float) ($big['s'] ?? 0);
$bigGrowth = (int) ($big['grow'] ?? 0);
$check('12 MP dark-table photo (worker memory_limit 512M): name read within 15 s; PHP resident memory grows by under 250 MB',
    ($big['ok'] ?? false) === true && ($big['name'] ?? false) === true && $bigSeconds < 15 && $bigGrowth > 0 && $bigGrowth < 250 * 1024 * 1024, $big);

// Not an ID: a typed letter. No PhilSys title, no name read from fields.
$letter = imagecreatetruecolor(1240, 700);
imagefill($letter, 0, 0, imagecolorallocate($letter, 255, 255, 255));
$black = imagecolorallocate($letter, 0, 0, 0);
foreach (['BARANGAY CERTIFICATION', 'This is to certify that the person named in this request is a resident', 'of this barangay. Issued upon request for whatever purpose it may serve.'] as $i => $line) {
    imagettftext($letter, $i === 0 ? 30 : 20, 0, 60, 120 + $i * 80, $black, $i === 0 ? $bold : $regular, $line);
}
$result = $service->extract($save($letter, 'letter.png'));
$text = $result['success'] ? DocumentAnalysis::normalizeText($result['text']) : '';
[$output] = DocumentAnalysis::keepQuotedDetails(['documents' => [['key' => 'requesterId', 'holder_name' => '', 'property_number' => '']]], ['requesterId' => ['text' => $text]]);
$check('a document that is not an ID: no PhilSys title and no name taken from it', $result['success']
    && !in_array('philsys_national_id', DocumentAnalysis::kindEvidence($text), true) && $output['documents'][0]['holder_name'] === '');

$check('no temporary OCR folder is left behind', (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smart-assess-ocr-*' . DIRECTORY_SEPARATOR . 'prepared-*') ?: []) === []);
$check('peak memory of this PHP process stays under 256 MB', memory_get_peak_usage(true) < 256 * 1024 * 1024, memory_get_peak_usage(true));

$failed = 0;
foreach ($results as [$passed, $label, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $label . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
printf("scenarios: names right %d / %d, titles right %d / %d, slowest %.1f s; 12 MP photo %.1f s, +%d MB; mild tilt without the completeness check: %s\n",
    $readNames, count($scenarios), $titles, count($scenarios), $slowest, $bigSeconds, intdiv($bigGrowth, 1048576), $withoutCheck);
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
