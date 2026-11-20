<?php
declare(strict_types=1);

/**
 * OCR-ready copies of one uploaded image, made with PHP's GD extension only (no other program,
 * no network). Used by OcrService when the first OCR pass reads the image poorly; the variants are
 * then each OCR'd and the best-read one is kept, so no transformation is applied blindly.
 *
 *  - 'gray':   greyscale, enlarged when small (text needs pixels), nothing else.
 *  - 'deskew': 'gray', cropped to a light card on a darker background when one is clearly
 *              there, and straightened by the angle (-12..12 degrees) that lines its text up best.
 *  - 'sharp':  'deskew', lightly sharpened (small print softened by a phone camera).
 *  - 'upside': 'gray' turned over (180 degrees), for a card photographed upside down when
 *              Tesseract's own orientation detection misses it (cards with little text).
 *  - 'clean':  'deskew', lightly smoothed (speckle noise) and turned black and white (Otsu).
 *
 * Safety: only JPEG and PNG are decoded here (WebP is left to Tesseract); the original upload is
 * only read, never written; copies are PNG files in the caller's
 * private temporary folder; images over MAX_SOURCE_PIXELS are not decoded at all (nor when PHP's
 * memory limit is visibly too low); the working copy is at most MAX_WORKING_SIDE pixels long.
 * Memory is bounded by those two limits: about 110 MB more resident memory for a 12 MP photo
 * (measured 19 Nov 2026), whether or not GD's memory counts toward PHP's memory_limit.
 * Nothing here logs.
 */
final class OcrImagePreparer
{
    public const VARIANTS = ['gray', 'deskew', 'sharp', 'clean', 'upside'];
    /** Larger images are left to Tesseract alone: decoding them in PHP would need too much memory. */
    public const MAX_SOURCE_PIXELS = 12_000_000;
    private const MAX_WORKING_SIDE = 2400;
    private const MIN_WORKING_WIDTH = 1800;

    private ?GdImage $base = null;
    private ?GdImage $straight = null;
    private bool $loaded = false;

    public function __construct(private readonly string $source)
    {
    }

    /** True when GD can do everything this class needs. */
    public static function available(): bool
    {
        foreach (['imagecreatefromjpeg', 'imagecreatefrompng', 'imagefilter', 'imagescale', 'imagerotate', 'imagecrop', 'imagepng', 'imagecolorat'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        return defined('IMG_FILTER_GRAYSCALE') && defined('IMG_BILINEAR_FIXED');
    }

    /**
     * Writes the variant into $directory and returns its path, or null when the image cannot be
     * prepared (too large, not decodable by GD, or not enough memory). Never throws.
     */
    public function variant(string $name, string $directory): ?string
    {
        if (!in_array($name, self::VARIANTS, true) || !self::available()) {
            return null;
        }
        try {
            $image = match ($name) {
                'gray' => $this->base(),
                'deskew' => $this->straight(),
                'sharp' => $this->sharp(),
                'upside' => ($base = $this->base()) === null ? null : (imagerotate($base, 180, 0xFFFFFF) ?: null),
                'clean' => $this->clean(),
            };
            if ($image === null) {
                return null;
            }
            $path = $directory . DIRECTORY_SEPARATOR . 'prepared-' . $name . '.png';
            if (!imagepng($image, $path) || !is_file($path)) {
                return null;
            }
            @chmod($path, 0600);
            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    /** Greyscale working copy: never larger than MAX_WORKING_SIDE, enlarged to MIN_WORKING_WIDTH when narrower. */
    private function base(): ?GdImage
    {
        if ($this->loaded) {
            return $this->base;
        }
        $this->loaded = true;
        $size = @getimagesize($this->source);
        if (!is_array($size) || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            return null;
        }
        [$width, $height, $type] = [(int) $size[0], (int) $size[1], (int) ($size[2] ?? 0)];
        // JPEG and PNG only: the formats ID photos and scans arrive in. Other uploads (WebP) are
        // left to Tesseract alone, so PHP never decodes them.
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $width * $height > self::MAX_SOURCE_PIXELS || !self::memoryAllows($width * $height)) {
            return null;
        }
        $original = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($this->source) : @imagecreatefrompng($this->source);
        if (!$original instanceof GdImage) {
            return null;
        }
        $scale = min(1.0, self::MAX_WORKING_SIDE / max($width, $height));
        if ($width * $scale < self::MIN_WORKING_WIDTH) {
            $scale = min(self::MIN_WORKING_WIDTH / $width, self::MAX_WORKING_SIDE / max($width, $height), 4.0);
        }
        $working = abs($scale - 1.0) < 0.01 ? $original
            : (imagescale($original, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BILINEAR_FIXED) ?: $original);
        $gray = imagecreatetruecolor(imagesx($working), imagesy($working));
        if (!$gray instanceof GdImage) {
            return null;
        }
        imagecopy($gray, $working, 0, 0, 0, 0, imagesx($working), imagesy($working));
        imagefilter($gray, IMG_FILTER_GRAYSCALE);
        unset($original, $working);
        return $this->base = $gray;
    }

    /** Cropped to the card when clearly on a darker background, then straightened. */
    private function straight(): ?GdImage
    {
        if ($this->straight !== null) {
            return $this->straight;
        }
        $base = $this->base();
        if ($base === null) {
            return null;
        }
        $image = $base;
        $box = self::cardBox($image);
        if ($box !== null) {
            $image = imagecrop($image, ['x' => $box[0], 'y' => $box[1], 'width' => $box[2], 'height' => $box[3]]) ?: $image;
            if (imagesx($image) < self::MIN_WORKING_WIDTH) {
                $image = imagescale($image, self::MIN_WORKING_WIDTH, -1, IMG_BILINEAR_FIXED) ?: $image;
            }
        }
        $angle = self::skewAngle($image);
        if (abs($angle) >= 1.0) {
            $image = imagerotate($image, $angle, 0xFFFFFF) ?: $image;
        }
        return $this->straight = $image;
    }

    private function sharp(): ?GdImage
    {
        $straight = $this->straight();
        if ($straight === null) {
            return null;
        }
        $copy = imagecreatetruecolor(imagesx($straight), imagesy($straight));
        imagecopy($copy, $straight, 0, 0, 0, 0, imagesx($straight), imagesy($straight));
        imagefilter($copy, IMG_FILTER_MEAN_REMOVAL);
        return $copy;
    }

    private function clean(): ?GdImage
    {
        $straight = $this->straight();
        if ($straight === null) {
            return null;
        }
        $copy = imagecreatetruecolor(imagesx($straight), imagesy($straight));
        imagecopy($copy, $straight, 0, 0, 0, 0, imagesx($straight), imagesy($straight));
        imagefilter($copy, IMG_FILTER_SMOOTH, 6);
        return self::binarize($copy);
    }

    private static function memoryAllows(int $pixels): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        // Decoding needs about 5 bytes a pixel, plus the working copies.
        return $limit <= 0 || memory_get_usage(true) + $pixels * 6 + 64 * 1024 * 1024 < $limit;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function luminance(GdImage $image, int $x, int $y): int
    {
        return imagecolorat($image, $x, $y) & 0xFF;
    }

    /** @param array<int, int> $histogram 256 counts */
    private static function otsu(array $histogram, int $total): int
    {
        $sum = 0;
        foreach ($histogram as $level => $count) {
            $sum += $level * $count;
        }
        $sumBackground = 0;
        $weightBackground = 0;
        $best = -1.0;
        $threshold = 127;
        foreach ($histogram as $level => $count) {
            $weightBackground += $count;
            if ($weightBackground === 0) {
                continue;
            }
            $weightForeground = $total - $weightBackground;
            if ($weightForeground <= 0) {
                break;
            }
            $sumBackground += $level * $count;
            $between = $weightBackground * $weightForeground * (($sumBackground / $weightBackground) - (($sum - $sumBackground) / $weightForeground)) ** 2;
            if ($between > $best) {
                $best = $between;
                $threshold = $level;
            }
        }
        return $threshold;
    }

    private static function small(GdImage $image, int $side): GdImage
    {
        $scale = $side / max(imagesx($image), imagesy($image));
        return imagescale($image, max(1, (int) (imagesx($image) * $scale)), max(1, (int) (imagesy($image) * $scale)), IMG_BILINEAR_FIXED) ?: $image;
    }

    /**
     * [x, y, width, height] of a light card on a clearly darker background, or null when the image
     * is not like that (a scan, a card filling the photo, a dark document).
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private static function cardBox(GdImage $gray): ?array
    {
        $small = self::small($gray, 300);
        $w = imagesx($small);
        $h = imagesy($small);
        $histogram = array_fill(0, 256, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $histogram[self::luminance($small, $x, $y)]++;
            }
        }
        $threshold = self::otsu($histogram, $w * $h);
        $rows = array_fill(0, $h, 0);
        $columns = array_fill(0, $w, 0);
        $bright = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (self::luminance($small, $x, $y) > $threshold) {
                    $rows[$y]++;
                    $columns[$x]++;
                    $bright++;
                }
            }
        }
        if ($bright < 0.15 * $w * $h || $bright > 0.92 * $w * $h) {
            return null;
        }
        $span = static function (array $counts, int $minimum): ?array {
            $first = null;
            $last = null;
            foreach ($counts as $index => $count) {
                if ($count >= $minimum) {
                    $first ??= $index;
                    $last = $index;
                }
            }
            return $first === null ? null : [$first, $last];
        };
        $ys = $span($rows, (int) ($w * 0.2));
        $xs = $span($columns, (int) ($h * 0.2));
        if ($ys === null || $xs === null) {
            return null;
        }
        $scale = imagesx($gray) / $w;
        $box = [(int) ($xs[0] * $scale), (int) ($ys[0] * $scale), (int) (($xs[1] - $xs[0] + 1) * $scale), (int) (($ys[1] - $ys[0] + 1) * $scale)];
        // Only a real crop: the card must leave a margin of background and keep most of its size.
        $area = $box[2] * $box[3];
        $whole = imagesx($gray) * imagesy($gray);
        return $area < 0.2 * $whole || $area > 0.9 * $whole ? null : $box;
    }

    /**
     * The rotation (degrees) that makes ink rows most uneven, i.e. lines of text level. Measured on
     * the central half of the image only, with an Otsu ink threshold, so dark background corners
     * left around a tilted card (a photo on a table) cannot outweigh the text.
     */
    private static function skewAngle(GdImage $gray): float
    {
        $small = self::small($gray, 600);
        $w = imagesx($small);
        $h = imagesy($small);
        $region = imagecrop($small, ['x' => (int) ($w * 0.2), 'y' => (int) ($h * 0.2), 'width' => max(1, (int) ($w * 0.6)), 'height' => max(1, (int) ($h * 0.6))]) ?: $small;
        $histogram = array_fill(0, 256, 0);
        $rw = imagesx($region);
        $rh = imagesy($region);
        for ($y = 0; $y < $rh; $y += 2) {
            for ($x = 0; $x < $rw; $x += 2) {
                $histogram[self::luminance($region, $x, $y)]++;
            }
        }
        $ink = min(self::otsu($histogram, (int) (ceil($rh / 2) * ceil($rw / 2))), 160);
        $bestAngle = 0.0;
        $bestSpread = -1.0;
        for ($angle = -12.0; $angle <= 12.0; $angle += 1.0) {
            $rotated = $angle === 0.0 ? $region : (imagerotate($region, $angle, 0xFFFFFF) ?: $region);
            $width = imagesx($rotated);
            $height = imagesy($rotated);
            // Rows of the middle of the rotated region only: its new corners are fill, not content.
            $x0 = (int) ($width * 0.15);
            $x1 = (int) ($width * 0.85);
            $rows = [];
            for ($y = (int) ($height * 0.1); $y < (int) ($height * 0.9); $y++) {
                $dark = 0;
                for ($x = $x0; $x < $x1; $x += 2) {
                    if (self::luminance($rotated, $x, $y) <= $ink) {
                        $dark++;
                    }
                }
                $rows[] = $dark;
            }
            $mean = array_sum($rows) / max(1, count($rows));
            $spread = 0.0;
            foreach ($rows as $dark) {
                $spread += ($dark - $mean) ** 2;
            }
            $spread /= max(1, count($rows));
            if ($spread > $bestSpread) {
                $bestSpread = $spread;
                $bestAngle = $angle;
            }
        }
        return $bestAngle;
    }

    private static function binarize(GdImage $gray): GdImage
    {
        $w = imagesx($gray);
        $h = imagesy($gray);
        $histogram = array_fill(0, 256, 0);
        $samples = 0;
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $histogram[self::luminance($gray, $x, $y)]++;
                $samples++;
            }
        }
        $threshold = self::otsu($histogram, $samples);
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, 0xFFFFFF);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (self::luminance($gray, $x, $y) <= $threshold) {
                    imagesetpixel($out, $x, $y, 0x000000);
                }
            }
        }
        return $out;
    }
}
