<?php
declare(strict_types=1);

/**
 * Text extraction from an uploaded image or PDF in the private upload folder.
 *
 * Security controls:
 *  - files must be strictly smaller than 5 MB, have a verified MIME type and
 *    resolve inside the configured private upload directory;
 *  - images are measured from their header before any OCR, and anything over
 *    MAX_IMAGE_PIXELS is refused;
 *  - every external program comes from a server-configured absolute path or a
 *    fixed trusted directory, never from PATH, the upload or client input, and
 *    runs from an argument array with the shell bypassed and a minimal environment;
 *  - each program has its own timeout and the whole extraction has a total
 *    deadline: a program only ever gets the time that remains;
 *  - program output is bounded, temporary files live in a private 0700 folder
 *    that is always removed, and the original upload is never modified;
 *  - nothing here logs, so OCR text never reaches a log.
 *
 * PDFs: each of the first 10 pages is judged separately. A page whose embedded
 * text layer has at least MIN_EMBEDDED_PAGE_CHARACTERS letters or digits keeps
 * that text; any other page is rendered and OCR'd. Every page is reported with
 * how it was handled, and a page that could not be processed says why.
 */
final class OcrService
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const MAX_PDF_PAGES = 10;
    private const MAX_PROCESS_OUTPUT_BYTES = 1024 * 1024;
    /** 25 megapixels: room for a phone photo of an ID or a page scan at 300 dpi. */
    public const MAX_IMAGE_PIXELS = 25_000_000;
    /** A page with fewer letters/digits than this in its text layer is treated as scanned. */
    public const MIN_EMBEDDED_PAGE_CHARACTERS = 20;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];
    private const TOOL_NAMES = [
        'tesseract' => 'tesseract',
        'pdfinfo' => 'pdfinfo',
        'pdftotext' => 'pdftotext',
        'pdftoppm' => 'pdftoppm',
    ];
    /** Used only for a tool with no configured path. Package managers install here; PATH is never read. */
    public const TRUSTED_EXECUTABLE_DIRECTORIES = ['/usr/bin', '/usr/local/bin'];
    /** The only environment the programs see. */
    private const PROCESS_PATH = '/usr/bin:/bin';

    private readonly string $privateUploadDirectory;
    /** @var array<string, string> */
    private readonly array $executablePaths;
    private readonly ?string $temporaryParent;

    /**
     * @param array<string, string> $executablePaths Server configuration only: tool name => absolute path.
     *        Tools left out are looked for in TRUSTED_EXECUTABLE_DIRECTORIES.
     */
    public function __construct(
        string $privateUploadDirectory,
        private readonly int $processTimeoutSeconds = 30,
        private readonly int $totalTimeoutSeconds = 60,
        array $executablePaths = [],
        private readonly string $languages = 'eng',
        ?string $temporaryParent = null
    ) {
        $realDirectory = realpath($privateUploadDirectory);
        if ($realDirectory === false ||
            $realDirectory === DIRECTORY_SEPARATOR ||
            !is_dir($realDirectory) ||
            !is_readable($realDirectory)) {
            throw new InvalidArgumentException('A readable private upload directory is required.');
        }
        if ($processTimeoutSeconds < 1 || $processTimeoutSeconds > 120) {
            throw new InvalidArgumentException('The process timeout must be between 1 and 120 seconds.');
        }
        if ($totalTimeoutSeconds < 1 || $totalTimeoutSeconds > 300) {
            throw new InvalidArgumentException('The total extraction timeout must be between 1 and 300 seconds.');
        }
        // Server configuration only, e.g. "eng" or "eng+tgl". Each language's data must be installed for Tesseract.
        if (!preg_match('/^[a-z]{3}(?:_[a-z]{3,4})?(?:\+[a-z]{3}(?:_[a-z]{3,4})?){0,3}$/', $languages)) {
            throw new InvalidArgumentException('OCR languages must look like "eng" or "eng+tgl".');
        }
        foreach ($executablePaths as $tool => $path) {
            if (!is_string($tool) || !isset(self::TOOL_NAMES[$tool])) {
                throw new InvalidArgumentException('Executable paths may only be configured for the supported OCR tools.');
            }
            if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
                throw new InvalidArgumentException('Each configured executable path must be an absolute path.');
            }
        }
        // Where the private temporary folders go (default: the system temp folder). The AI worker
        // passes its job folder, so an interrupted job leaves nothing outside that folder.
        if ($temporaryParent !== null) {
            $realParent = realpath($temporaryParent);
            if ($realParent === false || !is_dir($realParent) || !is_writable($realParent)) {
                throw new InvalidArgumentException('The temporary folder parent must be an existing, writable folder.');
            }
            $temporaryParent = $realParent;
        }
        $this->temporaryParent = $temporaryParent;
        $this->privateUploadDirectory = rtrim($realDirectory, DIRECTORY_SEPARATOR);
        $this->executablePaths = $executablePaths;
    }

    /**
     * Configuration problems that would make every extraction fail, as short
     * codes (empty when ready): a tool that is missing or untrusted, Tesseract
     * not running, or a configured OCR language that is not installed.
     * Reads no document. Used by the AI worker's health check.
     *
     * @return list<string>
     */
    public function readinessProblems(): array
    {
        $problems = [];
        foreach (array_keys(self::TOOL_NAMES) as $tool) {
            $resolved = $this->resolveExecutable($tool);
            if (is_array($resolved)) {
                $problems[] = $tool . '_' . $resolved['error'];
            }
        }
        $tesseract = $this->resolveExecutable('tesseract');
        if (is_array($tesseract)) {
            return $problems;
        }
        $temporaryDirectory = $this->createTemporaryDirectory();
        if ($temporaryDirectory === null) {
            $problems[] = 'temporary_folder_unavailable';
            return $problems;
        }
        try {
            $result = $this->runProcess([$tesseract, '--list-langs'], $temporaryDirectory, microtime(true) + 10);
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
        if ($result['error'] !== null || $result['exit_code'] !== 0) {
            $problems[] = 'tesseract_not_running';
            return $problems;
        }
        // The first line is a heading ("List of available languages ..."); then one name per line.
        $installed = array_slice(array_map('trim', preg_split('/\R/', (string) $result['stdout']) ?: []), 1);
        foreach (explode('+', $this->languages) as $language) {
            if (!in_array($language, $installed, true)) {
                $problems[] = 'language_missing_' . $language;
            }
        }
        return $problems;
    }

    /**
     * @return array{
     *     success: bool,
     *     text: string,
     *     pages_processed: int,
     *     pages: list<array{page: int, method: string, error: ?string}>,
     *     warnings: list<string>,
     *     engine: ?string,
     *     extraction_method: ?string,
     *     error_code: ?string
     * }
     */
    public function extract(string $filePath): array
    {
        $deadline = microtime(true) + $this->totalTimeoutSeconds;
        $resolvedPath = realpath($filePath);
        if ($resolvedPath === false) {
            return $this->failure('file_missing');
        }
        if ($resolvedPath !== $this->privateUploadDirectory && !$this->isInsidePrivateDirectory($resolvedPath)) {
            return $this->failure('outside_upload_directory');
        }
        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            return $this->failure('file_unreadable');
        }

        $size = filesize($resolvedPath);
        if ($size === false || $size < 1) {
            return $this->failure('file_unreadable');
        }
        if ($size >= self::MAX_FILE_BYTES) {
            return $this->failure('file_too_large');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($resolvedPath);
        if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return $this->failure('unsupported_mime_type');
        }

        $temporaryDirectory = $this->createTemporaryDirectory();
        if ($temporaryDirectory === null) {
            return $this->failure('temporary_processing_failed');
        }

        try {
            return $mime === 'application/pdf'
                ? $this->extractPdf($resolvedPath, $temporaryDirectory, $deadline)
                : $this->extractImage($resolvedPath, $temporaryDirectory, $deadline);
        } catch (Throwable) {
            return $this->failure('extraction_failed');
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    private function extractImage(string $filePath, string $temporaryDirectory, float $deadline): array
    {
        // Measured from the header only, before any program decodes the pixels.
        $dimensionError = $this->imageDimensionError($filePath);
        if ($dimensionError !== null) {
            return $this->failure($dimensionError, null, 'image_ocr');
        }

        $executable = $this->resolveExecutable('tesseract');
        if (!is_string($executable)) {
            return $this->failure($executable['error'], 'tesseract', 'image_ocr');
        }

        $process = $this->runProcess([$executable, $filePath, 'stdout', '-l', $this->languages], $temporaryDirectory, $deadline);
        if ($process['error'] !== null) {
            return $this->failure($process['error'], 'tesseract', 'image_ocr');
        }
        if ($process['exit_code'] !== 0) {
            return $this->failure('unreadable_document', 'tesseract', 'image_ocr');
        }

        $text = trim($process['stdout']);
        $warnings = ['OCR confidence was not measured.'];
        if ($text === '') {
            $warnings[] = 'No text could be extracted from the image.';
        }
        return $this->success($text, [['page' => 1, 'method' => 'ocr', 'error' => null]], $warnings, 'tesseract', 'image_ocr');
    }

    private function extractPdf(string $filePath, string $temporaryDirectory, float $deadline): array
    {
        $pdfInfo = $this->resolveExecutable('pdfinfo');
        if (!is_string($pdfInfo)) {
            return $this->failure($pdfInfo['error'], 'poppler', 'embedded_text');
        }
        $info = $this->runProcess([$pdfInfo, $filePath], $temporaryDirectory, $deadline);
        if ($info['error'] !== null) {
            return $this->failure($info['error'], 'poppler', 'embedded_text');
        }
        if ($info['exit_code'] !== 0 ||
            !preg_match('/^Pages:\s*(\d+)\s*$/mi', $info['stdout'], $matches)) {
            return $this->failure('unreadable_document', 'poppler', 'embedded_text');
        }
        $pageCount = (int) $matches[1];
        if ($pageCount < 1) {
            return $this->failure('unreadable_document', 'poppler', 'embedded_text');
        }
        $pagesToProcess = min($pageCount, self::MAX_PDF_PAGES);
        $warnings = $pageCount > self::MAX_PDF_PAGES
            ? ['Processing was limited to the first 10 pages of this document.']
            : [];

        $pdfToText = $this->resolveExecutable('pdftotext');
        if (!is_string($pdfToText)) {
            return $this->failure($pdfToText['error'], 'poppler', 'embedded_text', $warnings);
        }
        $embedded = $this->embeddedPageTexts($pdfToText, $filePath, $pagesToProcess, $temporaryDirectory, $deadline);
        if (isset($embedded['error'])) {
            return $this->failure($embedded['error'], 'poppler', 'embedded_text', $warnings);
        }

        /** @var array<int, string> $texts */
        $texts = [];
        /** @var array<int, array{page: int, method: string, error: ?string}> $pages */
        $pages = [];
        $needsOcr = [];
        foreach ($embedded['pages'] as $number => $pageText) {
            if ($this->letterAndDigitCount($pageText) >= self::MIN_EMBEDDED_PAGE_CHARACTERS) {
                $texts[$number] = $pageText;
                $pages[$number] = ['page' => $number, 'method' => 'embedded_text', 'error' => null];
            } else {
                $needsOcr[] = $number;
            }
        }

        if ($needsOcr !== []) {
            $pdfToPpm = $this->resolveExecutable('pdftoppm');
            $tesseract = $this->resolveExecutable('tesseract');
            $toolError = !is_string($pdfToPpm) ? $pdfToPpm['error'] : (!is_string($tesseract) ? $tesseract['error'] : null);
            if ($toolError !== null) {
                if ($pages === []) {
                    return $this->failure($toolError, 'poppler+tesseract', 'rasterized_ocr', $warnings, $this->notProcessed($needsOcr, $toolError));
                }
                foreach ($needsOcr as $number) {
                    $pages[$number] = ['page' => $number, 'method' => 'not_processed', 'error' => $toolError];
                }
            } else {
                foreach ($needsOcr as $index => $number) {
                    $page = $this->ocrPage((string) $pdfToPpm, (string) $tesseract, $filePath, $number, $temporaryDirectory, $deadline);
                    if ($page['error'] === 'deadline_exceeded') {
                        $remaining = array_slice($needsOcr, $index);
                        $report = $this->sortedPages($pages + $this->notProcessed($remaining, 'deadline_exceeded'));
                        return $this->failure('deadline_exceeded', 'poppler+tesseract', null, $warnings, $report);
                    }
                    if ($page['error'] !== null) {
                        $pages[$number] = ['page' => $number, 'method' => 'not_processed', 'error' => $page['error']];
                        continue;
                    }
                    // OCR text where it found any; otherwise whatever small text layer the page had.
                    $texts[$number] = $this->letterAndDigitCount($page['text']) > 0 ? $page['text'] : $embedded['pages'][$number];
                    $pages[$number] = ['page' => $number, 'method' => 'ocr', 'error' => null];
                }
            }
        }

        $pages = $this->sortedPages($pages);
        $methods = array_unique(array_column($pages, 'method'));
        $processed = array_values(array_filter($methods, static fn (string $method): bool => $method !== 'not_processed'));
        $failedPages = array_values(array_filter($pages, static fn (array $page): bool => $page['method'] === 'not_processed'));
        if ($processed === []) {
            return $this->failure('unreadable_document', 'poppler+tesseract', 'rasterized_ocr', $warnings, $pages);
        }
        foreach ($failedPages as $page) {
            $warnings[] = 'Page ' . $page['page'] . ' could not be processed (' . $page['error'] . ').';
        }
        $usedOcr = in_array('ocr', $processed, true);
        $method = count($processed) > 1 ? 'mixed' : ($usedOcr ? 'rasterized_ocr' : 'embedded_text');
        if ($usedOcr) {
            $warnings[] = 'OCR confidence was not measured.';
        }

        ksort($texts);
        $text = trim(implode("\n", array_map('trim', $texts)));
        if (!$this->hasMeaningfulText($text)) {
            $warnings[] = 'No text could be extracted from the document.';
        }
        return $this->success($text, $pages, $warnings, $usedOcr ? 'poppler+tesseract' : 'poppler', $method);
    }

    /**
     * Embedded text per page, numbered from 1. One pdftotext run normally ends
     * each page with a form feed; if the page count does not match, each page
     * is extracted on its own so text is never attributed to the wrong page.
     *
     * @return array{pages: array<int, string>}|array{error: string}
     */
    private function embeddedPageTexts(string $pdfToText, string $filePath, int $pageCount, string $temporaryDirectory, float $deadline): array
    {
        $run = $this->runProcess([$pdfToText, '-f', '1', '-l', (string) $pageCount, '-layout', $filePath, '-'], $temporaryDirectory, $deadline);
        if ($run['error'] !== null) {
            return ['error' => $run['error']];
        }
        if ($run['exit_code'] !== 0) {
            return ['error' => 'unreadable_document'];
        }
        $parts = explode("\f", $run['stdout']);
        if (count($parts) === $pageCount + 1 && trim((string) end($parts)) === '') {
            array_pop($parts);
        }
        if (count($parts) === $pageCount) {
            return ['pages' => array_combine(range(1, $pageCount), $parts)];
        }

        $pages = [];
        for ($number = 1; $number <= $pageCount; $number++) {
            $single = $this->runProcess([$pdfToText, '-f', (string) $number, '-l', (string) $number, '-layout', $filePath, '-'], $temporaryDirectory, $deadline);
            if ($single['error'] !== null) {
                return ['error' => $single['error']];
            }
            if ($single['exit_code'] !== 0) {
                return ['error' => 'unreadable_document'];
            }
            $pages[$number] = str_replace("\f", '', $single['stdout']);
        }
        return ['pages' => $pages];
    }

    /**
     * Renders one page and OCRs it.
     *
     * @return array{text: string, error: ?string}
     */
    private function ocrPage(string $pdfToPpm, string $tesseract, string $filePath, int $number, string $temporaryDirectory, float $deadline): array
    {
        $prefix = $temporaryDirectory . DIRECTORY_SEPARATOR . 'page-' . $number;
        $image = $prefix . '.png';
        $raster = $this->runProcess([
            $pdfToPpm,
            '-f',
            (string) $number,
            '-l',
            (string) $number,
            '-singlefile',
            '-r',
            '150',
            '-scale-to',
            '2000',
            '-png',
            $filePath,
            $prefix,
        ], $temporaryDirectory, $deadline);
        if ($raster['error'] !== null) {
            return ['text' => '', 'error' => $raster['error']];
        }
        if ($raster['exit_code'] !== 0 || !is_file($image) || is_link($image)) {
            return ['text' => '', 'error' => 'render_failed'];
        }
        $dimensionError = $this->imageDimensionError($image);
        if ($dimensionError !== null) {
            @unlink($image);
            return ['text' => '', 'error' => $dimensionError];
        }

        $ocr = $this->runProcess([$tesseract, $image, 'stdout', '-l', $this->languages], $temporaryDirectory, $deadline);
        @unlink($image);
        if ($ocr['error'] !== null) {
            return ['text' => '', 'error' => $ocr['error']];
        }
        if ($ocr['exit_code'] !== 0) {
            return ['text' => '', 'error' => 'unreadable_document'];
        }
        return ['text' => trim($ocr['stdout']), 'error' => null];
    }

    /** 'image_too_large', 'unreadable_document' when no size can be read, or null when the image may be processed. */
    private function imageDimensionError(string $path): ?string
    {
        $size = @getimagesize($path);
        if (!is_array($size) || !is_int($size[0] ?? null) || !is_int($size[1] ?? null) || $size[0] < 1 || $size[1] < 1) {
            return 'unreadable_document';
        }
        return $size[0] * $size[1] > self::MAX_IMAGE_PIXELS ? 'image_too_large' : null;
    }

    /**
     * Runs one program and returns its output. It gets its own timeout or the
     * time left before the overall deadline, whichever is shorter, and is not
     * started at all once the deadline has passed.
     */
    private function runProcess(array $arguments, string $workingDirectory, float $deadline): array
    {
        $startedAt = microtime(true);
        if ($startedAt >= $deadline) {
            return ['stdout' => '', 'exit_code' => -1, 'error' => 'deadline_exceeded'];
        }
        $processDeadline = $startedAt + $this->processTimeoutSeconds;
        $stopAt = min($processDeadline, $deadline);
        $timeoutError = $deadline <= $processDeadline ? 'deadline_exceeded' : 'process_timeout';

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($arguments, $descriptors, $pipes, $workingDirectory, $this->processEnvironment(), ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['stdout' => '', 'exit_code' => -1, 'error' => 'extraction_failed'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderrBytes = 0;
        $lastExitCode = -1;
        $error = null;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running'] && $status['exitcode'] >= 0) {
                $lastExitCode = $status['exitcode'];
            }

            $read = [];
            foreach ([1, 2] as $index) {
                if (!feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }
            if (!$status['running'] && $read === []) {
                break;
            }
            if (microtime(true) >= $stopAt) {
                $error = $timeoutError;
                @proc_terminate($process, 9);
                break;
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                $wait = (int) max(0, min(100000, ($stopAt - microtime(true)) * 1000000));
                $selected = @stream_select($read, $write, $except, 0, $wait);
                if ($selected === false) {
                    $error = 'extraction_failed';
                    @proc_terminate($process, 9);
                    break;
                }
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                        if (strlen($stdout) > self::MAX_PROCESS_OUTPUT_BYTES) {
                            $error = 'output_limit_exceeded';
                            @proc_terminate($process, 9);
                            break 2;
                        }
                    } else {
                        $stderrBytes += strlen($chunk);
                        if ($stderrBytes > self::MAX_PROCESS_OUTPUT_BYTES) {
                            $error = 'output_limit_exceeded';
                            @proc_terminate($process, 9);
                            break 2;
                        }
                    }
                }
            } else {
                usleep(10000);
            }
        }

        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
        $closeCode = proc_close($process);
        if ($error !== null) {
            return ['stdout' => '', 'exit_code' => -1, 'error' => $error];
        }

        return [
            'stdout' => $stdout,
            'exit_code' => $closeCode >= 0 ? $closeCode : $lastExitCode,
            'error' => null,
        ];
    }

    /** @return array<string, string> */
    private function processEnvironment(): array
    {
        $environment = ['PATH' => self::PROCESS_PATH, 'LC_ALL' => 'C', 'OMP_THREAD_LIMIT' => '1'];
        // Server configuration, kept only so Tesseract can find its language data.
        $tessdata = getenv('TESSDATA_PREFIX');
        if (is_string($tessdata) && $tessdata !== '' && $tessdata[0] === '/' && !str_contains($tessdata, "\0")) {
            $environment['TESSDATA_PREFIX'] = $tessdata;
        }
        return $environment;
    }

    /**
     * The trusted path of a tool, or ['error' => 'missing_tool' | 'untrusted_tool'].
     * PATH is never consulted.
     *
     * @return string|array{error: string}
     */
    private function resolveExecutable(string $tool): string|array
    {
        $name = self::TOOL_NAMES[$tool] ?? null;
        if ($name === null) {
            return ['error' => 'missing_tool'];
        }
        $candidates = isset($this->executablePaths[$tool])
            ? [$this->executablePaths[$tool]]
            : array_map(static fn (string $directory): string => $directory . DIRECTORY_SEPARATOR . $name, self::TRUSTED_EXECUTABLE_DIRECTORIES);
        $untrusted = false;
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
                continue;
            }
            if (!$this->isSafeExecutable($resolved)) {
                $untrusted = true;
                continue;
            }
            return $resolved;
        }
        return ['error' => $untrusted ? 'untrusted_tool' : 'missing_tool'];
    }

    /** Refuses a program that any local user could replace: a world-writable file or folder. */
    private function isSafeExecutable(string $resolvedPath): bool
    {
        clearstatcache(true, $resolvedPath);
        $filePermissions = @fileperms($resolvedPath);
        $directoryPermissions = @fileperms(dirname($resolvedPath));
        return $filePermissions !== false && $directoryPermissions !== false
            && ($filePermissions & 0o002) === 0 && ($directoryPermissions & 0o002) === 0;
    }

    private function createTemporaryDirectory(): ?string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $path = ($this->temporaryParent ?? sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'smart-assess-ocr-' . bin2hex(random_bytes(16));
            } catch (Throwable) {
                return null;
            }
            if (@mkdir($path, 0700)) {
                @chmod($path, 0700);
                $realPath = realpath($path);
                if ($realPath !== false && is_dir($realPath)) {
                    return $realPath;
                }
                @rmdir($path);
            }
        }
        return null;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    private function isInsidePrivateDirectory(string $path): bool
    {
        return str_starts_with($path, $this->privateUploadDirectory . DIRECTORY_SEPARATOR);
    }

    private function hasMeaningfulText(string $text): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }

    private function letterAndDigitCount(string $text): int
    {
        $count = preg_match_all('/[\p{L}\p{N}]/u', $text);
        return $count === false ? 0 : $count;
    }

    /** @return array<int, array{page: int, method: string, error: string}> */
    private function notProcessed(array $pageNumbers, string $error): array
    {
        $pages = [];
        foreach ($pageNumbers as $number) {
            $pages[$number] = ['page' => $number, 'method' => 'not_processed', 'error' => $error];
        }
        return $pages;
    }

    /** @return list<array{page: int, method: string, error: ?string}> */
    private function sortedPages(array $pages): array
    {
        ksort($pages);
        return array_values($pages);
    }

    private function success(
        string $text,
        array $pages,
        array $warnings,
        string $engine,
        string $extractionMethod
    ): array {
        $pages = array_values($pages);
        return [
            'success' => true,
            'text' => $text,
            'pages_processed' => count(array_filter($pages, static fn (array $page): bool => $page['method'] !== 'not_processed')),
            'pages' => $pages,
            'warnings' => $warnings,
            'engine' => $engine,
            'extraction_method' => $extractionMethod,
            'error_code' => null,
        ];
    }

    private function failure(
        string $errorCode,
        ?string $engine = null,
        ?string $extractionMethod = null,
        array $warnings = [],
        array $pages = []
    ): array {
        return [
            'success' => false,
            'text' => '',
            'pages_processed' => 0,
            'pages' => array_values($pages),
            'warnings' => $warnings,
            'engine' => $engine,
            'extraction_method' => $extractionMethod,
            'error_code' => $errorCode,
        ];
    }
}
