<?php
declare(strict_types=1);

/**
 * FICTIONAL Philippine-government-style ID cards for OCR evaluation, drawn with GD at run time.
 * Generic layouts: header lines, a grey photo box, label/value rows (and a machine-readable zone
 * for the passport). They are not copies of any real card design, and every person, number and
 * date is invented. Every card says "SYNTHETIC TEST CARD - NOT A REAL ID".
 *
 * What a card prints is defined HERE; what OCR should read from it is defined separately, by hand,
 * in tests/fixtures/ocr-ground-truth/*.json (the evaluation compares the two, never OCR to itself).
 *
 * Conditions (IdCardFixtures::CONDITIONS) are applied to a clean card: blur, turns, a dark photo,
 * glare, skew, low resolution, a cropped edge, a missing printed field, and PDFs (scanned, with a
 * text layer, with an incomplete text layer).
 */
final class IdCardFixtures
{
    public const CONDITIONS = ['clear', 'blurry', 'rotated_90', 'upside_down', 'dark_photo', 'glare', 'skewed', 'low_resolution', 'cropped', 'missing_field',
        'pdf_scanned', 'pdf_embedded_text', 'pdf_incomplete_text'];

    private string $regular;
    private string $bold;
    private string $mono;

    public function __construct()
    {
        $fonts = [
            ['/System/Library/Fonts/Supplemental/Arial.ttf', '/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/System/Library/Fonts/Supplemental/Courier New Bold.ttf'],
            ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf'],
        ];
        foreach ($fonts as [$regular, $bold, $mono]) {
            if (is_file($regular) && is_file($bold) && is_file($mono)) {
                [$this->regular, $this->bold, $this->mono] = [$regular, $bold, $mono];
                return;
            }
        }
        throw new RuntimeException('No TrueType fonts for the synthetic cards.');
    }

    public static function available(): bool
    {
        try {
            new self();
            return function_exists('imagettftext');
        } catch (RuntimeException) {
            return false;
        }
    }

    /** ICAO 9303 check digit, for the fictional passport's machine-readable zone. */
    private static function check(string $data): string
    {
        $sum = 0;
        foreach (str_split($data) as $i => $char) {
            $value = ctype_digit($char) ? (int) $char : ($char === '<' ? 0 : ord($char) - 55);
            $sum += $value * [7, 3, 1][$i % 3];
        }
        return (string) ($sum % 10);
    }

    /**
     * What each fictional card prints. 'rows': [label, value, value size]; a value of '' prints the
     * label only. 'inline': [label, value] printed on one line. 'number': a line printed under the photo.
     * 'omit': for the missing_field condition, the row/inline/number label whose value is left out.
     */
    public function layouts(): array
    {
        $mrzName = 'P<PHLMAGBANUA<<ROSALINDA' . str_repeat('<', 44 - strlen('P<PHLMAGBANUA<<ROSALINDA'));
        // TD3 line 2: number, nationality, birth date, sex, expiry (each with its check digit), an empty
        // personal-number field (14 fillers, filler check digit) and the composite check digit.
        $second = 'P4821736B' . self::check('P4821736B') . 'PHL' . '850314' . self::check('850314') . 'F' . '320313' . self::check('320313') . str_repeat('<', 14) . '<';
        $second .= self::check(substr($second, 0, 10) . substr($second, 13, 7) . substr($second, 21, 22));
        return [
            'philsys-a' => [
                'header' => [['REPUBLIKA NG PILIPINAS', 22, true], ['Republic of the Philippines', 15, false], ['PAMBANSANG PAGKAKAKILANLAN', 26, true], ['Philippine Identification Card', 16, false]],
                'rows' => [['Apelyido/Last Name', 'DELA CRUZ', 34], ['Mga Pangalan/Given Names', 'JUAN', 34], ['Gitnang Apelyido/Middle Name', 'SANTOS', 34],
                    ['Petsa ng Kapanganakan/Date of Birth', 'JANUARY 01, 1990', 26], ['Tirahan/Address', '123 RIZAL ST., POBLACION, MABINI, BATANGAS', 20]],
                'number' => '1234-5678-9012-3456',
                'omit' => 'Mga Pangalan/Given Names',
                'embedded_lines' => ['REPUBLIKA NG PILIPINAS', 'PAMBANSANG PAGKAKAKILANLAN', 'Philippine Identification Card', 'Apelyido/Last Name', 'DELA CRUZ',
                    'Mga Pangalan/Given Names', 'JUAN', 'Gitnang Apelyido/Middle Name', 'SANTOS', 'Petsa ng Kapanganakan/Date of Birth', 'JANUARY 01, 1990', '1234-5678-9012-3456'],
            ],
            'philsys-b' => [
                'header' => [['REPUBLIC OF THE PHILIPPINES', 20, true], ['PHILIPPINE IDENTIFICATION CARD', 26, true]],
                'rows' => [['LAST NAME', 'REYES', 34], ['GIVEN NAMES', 'ANA MARIE', 34], ['MIDDLE NAME', 'LOPEZ', 34], ['DATE OF BIRTH', '1993/04/18', 26]],
                'inline' => [['PCN', '2345-6789-0123-4567']],
                'omit' => 'LAST NAME',
            ],
            'passport' => [
                'header' => [['REPUBLIKA NG PILIPINAS', 20, true], ['REPUBLIC OF THE PHILIPPINES', 15, false], ['PASAPORTE / PASSPORT', 26, true]],
                'rows' => [['Apelyido/Surname', 'MAGBANUA', 28], ['Mga Pangalan/Given names', 'ROSALINDA', 28], ['Panggitnang apelyido/Middle name', 'IBARRA', 28],
                    ['Nasyonalidad/Nationality', 'FILIPINO', 22], ['Petsa ng kapanganakan/Date of birth', '14 MAR 1985', 22], ['Pasaporte Blg./Passport No.', 'P4821736B', 22],
                    ['Petsa ng pagkawalang-bisa/Date of expiry', '13 MAR 2032', 22]],
                'mrz' => [$mrzName, $second],
                'omit' => 'Pasaporte Blg./Passport No.',
                'height' => 1020,
                'embedded_lines' => ['REPUBLIKA NG PILIPINAS', 'PASAPORTE / PASSPORT', 'Apelyido/Surname', 'MAGBANUA', 'Mga Pangalan/Given names', 'ROSALINDA',
                    'Panggitnang apelyido/Middle name', 'IBARRA', 'Nasyonalidad/Nationality', 'FILIPINO', 'Petsa ng kapanganakan/Date of birth', '14 MAR 1985',
                    'Pasaporte Blg./Passport No.', 'P4821736B', 'Petsa ng pagkawalang-bisa/Date of expiry', '13 MAR 2032', $mrzName, $second],
            ],
            'drivers-license-a' => [
                'header' => [['REPUBLIC OF THE PHILIPPINES', 16, false], ['DEPARTMENT OF TRANSPORTATION', 18, true], ['LAND TRANSPORTATION OFFICE', 22, true], ['DRIVER\'S LICENSE', 26, true]],
                'rows' => [['Last Name, First Name, Middle Name', 'VILLAREAL, MARCO DIZON', 30], ['Nationality    Sex    Date of Birth', 'PHL    M    1987/11/23', 22],
                    ['License No.', 'N03-21-004512', 26], ['Expiration Date', '2028/11/23', 22]],
                'omit' => 'License No.',
                'embedded_lines' => ['REPUBLIC OF THE PHILIPPINES', 'LAND TRANSPORTATION OFFICE', 'DRIVER\'S LICENSE', 'Last Name, First Name, Middle Name', 'VILLAREAL, MARCO DIZON',
                    'Date of Birth', '1987/11/23', 'License No.', 'N03-21-004512', 'Expiration Date', '2028/11/23'],
            ],
            'drivers-license-b' => [
                'header' => [['REPUBLIC OF THE PHILIPPINES', 16, false], ['LAND TRANSPORTATION OFFICE', 22, true], ['NON-PROFESSIONAL DRIVER\'S LICENSE', 22, true]],
                'rows' => [['Name', 'CASTILLO, BRENDA OCAMPO', 30]],
                'inline' => [['License No.', 'D12-09-778201'], ['Date of Birth', '1990/02/14'], ['Valid Until', '2024/05/10']],
                'omit' => 'Name',
            ],
            'umid' => [
                'header' => [['REPUBLIC OF THE PHILIPPINES', 18, true], ['UNIFIED MULTI-PURPOSE ID', 26, true]],
                'number' => 'CRN-0111-2345678-9',
                'rows' => [['SURNAME', 'PASCUAL', 32], ['GIVEN NAME', 'LORENZO', 32], ['MIDDLE NAME', 'AQUINO', 32], ['DATE OF BIRTH', '1979/06/30', 24]],
                'omit' => 'CRN-0111-2345678-9',
                'embedded_lines' => ['REPUBLIC OF THE PHILIPPINES', 'UNIFIED MULTI-PURPOSE ID', 'CRN-0111-2345678-9', 'SURNAME', 'PASCUAL', 'GIVEN NAME', 'LORENZO',
                    'MIDDLE NAME', 'AQUINO', 'DATE OF BIRTH', '1979/06/30'],
            ],
            'sss' => [
                'header' => [['Republic of the Philippines', 16, false], ['SOCIAL SECURITY SYSTEM', 28, true]],
                'inline' => [['SS No.', '34-5678901-2']],
                'free' => [['TOLENTINO, GRACE MENDOZA', 32]],
                'omit' => 'TOLENTINO, GRACE MENDOZA',
                'embedded_lines' => ['Republic of the Philippines', 'SOCIAL SECURITY SYSTEM', 'SS No. 34-5678901-2', 'TOLENTINO, GRACE MENDOZA'],
            ],
            'philhealth' => [
                'header' => [['PhilHealth', 30, true], ['Philippine Health Insurance Corporation', 18, false]],
                'number' => '12-345678901-2',
                'free' => [['NAVARRO, ELENA CRUZ', 32]],
                'inline' => [['Date of Birth', 'JULY 21, 1985'], ['Sex', 'FEMALE']],
                'omit' => '12-345678901-2',
                'embedded_lines' => ['PhilHealth', 'Philippine Health Insurance Corporation', '12-345678901-2', 'NAVARRO, ELENA CRUZ', 'Date of Birth JULY 21, 1985'],
            ],
            'postal' => [
                'header' => [['PHILIPPINE POSTAL CORPORATION', 20, true], ['POSTAL IDENTITY CARD', 28, true]],
                'inline' => [['PRN', '100141234567P']],
                'rows' => [['Surname', 'SORIANO', 30], ['First Name', 'RAFAEL', 30], ['Middle Name', 'GATCHALIAN', 30], ['Date of Birth', '05 AUG 1991', 22], ['Valid Until', '05 AUG 2027', 22]],
                'omit' => 'First Name',
                'embedded_lines' => ['PHILIPPINE POSTAL CORPORATION', 'POSTAL IDENTITY CARD', 'PRN 100141234567P', 'Surname', 'SORIANO', 'First Name', 'RAFAEL',
                    'Middle Name', 'GATCHALIAN', 'Date of Birth', '05 AUG 1991', 'Valid Until', '05 AUG 2027'],
            ],
        ];
    }

    /** Non-ID documents (negative controls) that name ID issuers in their text. */
    public function negatives(): array
    {
        return [
            'barangay-certification' => ['BARANGAY CERTIFICATION', 'This is to certify that the person named in this request is a bona fide resident of this barangay.',
                'Please bring one passport-size photo, your PhilHealth contribution record and your SSS number.', 'Issued upon request for whatever legal purpose it may serve.'],
            'requirements-letter' => ['LIST OF REQUIREMENTS', 'Bring any one valid ID: Passport, Driver\'s License, UMID, Postal ID or PhilSys National ID.',
                'Date of Birth and Name must match the application form.', 'Social Security System and PhilHealth members may present their member data record.'],
            'deed-of-sale' => ['DEED OF ABSOLUTE SALE', 'KNOW ALL MEN BY THESE PRESENTS:', 'That I, the VENDOR, hereby sell, transfer and convey to the VENDEE',
                'the parcel covered by Tax Declaration No. 018-00045.'],
            'receipt' => ['OFFICIAL RECEIPT', 'Received from the payor the amount of five hundred pesos', 'for the certification fee.', 'Thank you.'],
        ];
    }

    public function card(string $id, bool $omit = false): GdImage
    {
        $layout = $this->layouts()[$id];
        $w = 1300;
        $h = $layout['height'] ?? 820;
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 244, 247, 251));
        $blue = imagecolorallocate($im, 196, 218, 238);
        $pink = imagecolorallocate($im, 240, 205, 214);
        for ($k = 0; $k < (int) ($h / 32); $k++) {
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
        $skip = $omit ? $layout['omit'] : null;
        $y = 60;
        foreach ($layout['header'] as [$text, $size, $isBold]) {
            imagettftext($im, $size, 0, 360, $y, $isBold ? $navy : $grey, $isBold ? $this->bold : $this->regular, $text);
            $y += $size + 20;
        }
        imagefilledrectangle($im, 50, 220, 320, 560, imagecolorallocate($im, 184, 194, 204));
        if (isset($layout['number']) && $layout['number'] !== $skip) {
            imagettftext($im, 18, 0, 50, 610, $ink, $this->bold, $layout['number']);
        }
        $y = max($y + 10, 245);
        foreach ($layout['inline'] ?? [] as [$label, $value]) {
            imagettftext($im, 18, 0, 370, $y, $ink, $this->regular, $label . '  ' . ($label === $skip ? '' : $value));
            $y += 46;
        }
        foreach ($layout['free'] ?? [] as [$text, $size]) {
            if ($text !== $skip) {
                imagettftext($im, $size, 0, 370, $y + $size, $ink, $this->bold, $text);
            }
            $y += $size + 40;
        }
        foreach ($layout['rows'] ?? [] as [$label, $value, $size]) {
            imagettftext($im, 14, 0, 370, $y, $grey, $this->regular, $label);
            if ($value !== '' && $label !== $skip) {
                imagettftext($im, $size, 0, 370, $y + $size + 10, $ink, $this->bold, $value);
            }
            $y += $size + 56;
        }
        if (isset($layout['mrz'])) {
            imagettftext($im, 26, 0, 40, $h - 90, $ink, $this->mono, $layout['mrz'][0]);
            imagettftext($im, 26, 0, 40, $h - 40, $ink, $this->mono, $layout['mrz'][1]);
        }
        imagettftext($im, 12, 0, 50, isset($layout['mrz']) ? $h - 150 : $h - 25, $grey, $this->regular, 'SYNTHETIC TEST CARD - NOT A REAL ID');
        return $im;
    }

    public function negative(string $id): GdImage
    {
        $lines = $this->negatives()[$id];
        $im = imagecreatetruecolor(1400, 700);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $black = imagecolorallocate($im, 0, 0, 0);
        foreach ($lines as $i => $line) {
            imagettftext($im, $i === 0 ? 28 : 17, 0, 50, 100 + $i * 70, $black, $i === 0 ? $this->bold : $this->regular, $line);
        }
        return $im;
    }

    /**
     * Writes the fixture for (card, condition) into $directory and returns its path.
     */
    public function write(string $id, string $condition, string $directory): string
    {
        $base = $directory . DIRECTORY_SEPARATOR . $id . '--' . $condition;
        $card = fn (): GdImage => $this->card($id, $condition === 'missing_field');
        $jpeg = static function (GdImage $im, string $path, int $quality = 88): string {
            imagejpeg($im, $path, $quality);
            return $path;
        };
        switch ($condition) {
            case 'clear':
            case 'missing_field':
                imagepng($card(), $base . '.png');
                return $base . '.png';
            case 'blurry':
                $im = $card();
                for ($i = 0; $i < 5; $i++) {
                    imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
                }
                return $jpeg($im, $base . '.jpg');
            case 'rotated_90':
                return $jpeg(imagerotate($card(), 90, 0), $base . '.jpg');
            case 'upside_down':
                return $jpeg(imagerotate($card(), 180, 0), $base . '.jpg');
            case 'dark_photo':
                $im = $this->onBackground($card(), 1700, 1300, -5, 1050, [52, 44, 38]);
                imagefilter($im, IMG_FILTER_BRIGHTNESS, -45);
                imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
                return $jpeg($im, $base . '.jpg', 80);
            case 'glare':
                return $jpeg($this->glare($card()), $base . '.jpg');
            case 'skewed':
                return $jpeg($this->onBackground($card(), 1500, 1150, 6, 1300, [235, 235, 235]), $base . '.jpg');
            case 'low_resolution':
                $im = $card();
                return $jpeg(imagescale($im, 520, -1, IMG_BILINEAR_FIXED) ?: $im, $base . '.jpg', 85);
            case 'cropped':
                $im = $card();
                return $jpeg(imagecrop($im, ['x' => 0, 'y' => 0, 'width' => (int) (imagesx($im) * 0.85), 'height' => imagesy($im)]) ?: $im, $base . '.jpg');
            case 'pdf_scanned':
                $photo = $jpeg($card(), $base . '-page.jpg');
                $pdf = $this->pdf($base . '.pdf', [['image' => $photo]]);
                @unlink($photo);
                return $pdf;
            case 'pdf_embedded_text':
                return $this->pdf($base . '.pdf', [['text' => $this->layouts()[$id]['embedded_lines'] ?? []]]);
            case 'pdf_incomplete_text':
                $photo = $jpeg($card(), $base . '-page.jpg');
                // As in a scanned PDF with a partial OCR layer: the header as invisible text over the picture.
                $pdf = $this->pdf($base . '.pdf', [['image' => $photo, 'text' => [$this->layouts()[$id]['header'][0][0]], 'invisible' => true]]);
                @unlink($photo);
                return $pdf;
        }
        throw new InvalidArgumentException('unknown condition');
    }

    public function writeNegative(string $id, string $directory): string
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'negative-' . $id . '.png';
        imagepng($this->negative($id), $path);
        return $path;
    }

    private function onBackground(GdImage $card, int $bw, int $bh, float $angle, int $cardWidth, array $bg): GdImage
    {
        $scaled = imagescale($card, $cardWidth, -1, IMG_BILINEAR_FIXED) ?: $card;
        $rotated = imagerotate($scaled, $angle, imagecolorallocate($scaled, ...$bg));
        $canvas = imagecreatetruecolor($bw, $bh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$bg));
        imagecopy($canvas, $rotated, (int) (($bw - imagesx($rotated)) / 2), (int) (($bh - imagesy($rotated)) / 2), 0, 0, imagesx($rotated), imagesy($rotated));
        return $canvas;
    }

    /**
     * A bright, soft reflection over the upper right of the card (over part of the header and the
     * name rows). At its centre it is about 55% opaque white: it washes the print out without
     * erasing it (a reflection that erases text completely cannot be read by any OCR).
     */
    private function glare(GdImage $card): GdImage
    {
        imagealphablending($card, true);
        $cx = (int) (imagesx($card) * 0.68);
        $cy = (int) (imagesy($card) * 0.22);
        for ($r = 260; $r > 0; $r -= 8) {
            $alpha = (int) max(57, min(127, 127 - (260 - $r) * 0.42));
            imagefilledellipse($card, $cx, $cy, $r * 3, $r * 2, imagecolorallocatealpha($card, 255, 255, 255, $alpha));
        }
        return $card;
    }

    /** A minimal PDF: per page an optional JPEG image (top of an A4 page) and optional Helvetica text lines. */
    private function pdf(string $path, array $pages): string
    {
        $objects = [];
        $kids = [];
        $add = static function (string $body) use (&$objects): int {
            $objects[] = $body;
            return count($objects);
        };
        $font = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $pagesObject = $add('PAGES');
        foreach ($pages as $page) {
            $content = '';
            $resources = "/Font << /F1 {$font} 0 R >>";
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
                $content .= 'BT ' . (($page['invisible'] ?? false) ? '3 Tr ' : '') . "/F1 12 Tf 40 {$y} Td (" . addcslashes($line, '()\\') . ") Tj ET\n";
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
        file_put_contents($path, $out);
        return $path;
    }
}
