<?php
declare(strict_types=1);

/*
 * SMART ASSESS private AI worker: the only file served from worker/.
 * Runs on a separate private host (see docs/AI_DOCUMENT_VERIFICATION.md).
 * Configuration comes only from the environment; nothing here is a default
 * credential. Ollama is reached on loopback (or HTTPS) and is never exposed.
 */

require_once dirname(__DIR__) . '/app/services/AiWorkerHttp.php';
require_once dirname(__DIR__) . '/app/services/OllamaClient.php';

ini_set('display_errors', '0');

$setting = static function (string $name, ?string $default = null): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        if ($default === null) {
            throw new RuntimeException($name . ' is not configured.');
        }
        return $default;
    }
    return trim($value);
};
$number = static function (string $name, int $default) use ($setting): int {
    $value = $setting($name, (string) $default);
    if (!ctype_digit($value)) {
        throw new RuntimeException($name . ' must be a whole number.');
    }
    return (int) $value;
};

try {
    $stateDirectory = $setting('SMART_ASSESS_WORKER_STATE_DIR');
    if (!is_dir($stateDirectory)) {
        throw new RuntimeException('SMART_ASSESS_WORKER_STATE_DIR does not exist.');
    }
    $tools = [];
    foreach (['tesseract' => 'TESSERACT', 'pdfinfo' => 'PDFINFO', 'pdftotext' => 'PDFTOTEXT', 'pdftoppm' => 'PDFTOPPM'] as $tool => $variable) {
        $path = getenv('SMART_ASSESS_WORKER_' . $variable . '_PATH');
        if (is_string($path) && trim($path) !== '') {
            $tools[$tool] = trim($path);
        }
    }
    $ocrLanguages = $setting('SMART_ASSESS_WORKER_OCR_LANGUAGES', 'eng');
    $ocrProcessTimeout = $number('SMART_ASSESS_WORKER_OCR_PROCESS_TIMEOUT', 30);
    $ocrTotalTimeout = $number('SMART_ASSESS_WORKER_OCR_TOTAL_TIMEOUT', 90);
    $ollama = new OllamaClient(
        $setting('SMART_ASSESS_WORKER_OLLAMA_URL', 'http://127.0.0.1:11434'),
        $setting('SMART_ASSESS_WORKER_OLLAMA_MODEL'),
        $number('SMART_ASSESS_WORKER_OLLAMA_TIMEOUT', 180),
        $number('SMART_ASSESS_WORKER_OLLAMA_MAX_RESPONSE_BYTES', 262_144),
        $number('SMART_ASSESS_WORKER_OLLAMA_CONTEXT_TOKENS', 8192),
        $number('SMART_ASSESS_WORKER_OLLAMA_MAX_OUTPUT_TOKENS', 1500)
    );
    $maxInput = $number('SMART_ASSESS_WORKER_MAX_INPUT_CHARS', 16_000);
    $maxBody = $number('SMART_ASSESS_WORKER_MAX_BODY_BYTES', 75 * 1024 * 1024);
    // Longest a job may run, whatever the application asks for. The application's
    // budget (its HTTP timeout minus a margin) is normally shorter.
    $maxJobSeconds = $number('SMART_ASSESS_WORKER_MAX_JOB_SECONDS', 900);
    // PHP must not stop a job that is still within its budget (php-fpm's
    // request_terminate_timeout and the web server's read timeout must be longer too).
    set_time_limit($maxJobSeconds + 60);
    // Each document's OCR is limited to what is left of the job's budget, and its
    // temporary files live inside the job folder, so an interrupted job leaves
    // nothing outside it (WorkerStateCleaner removes the job folder later).
    $ocrFactory = static fn (string $jobDirectory, int $seconds = PHP_INT_MAX): OcrService => new OcrService($jobDirectory,
        max(1, min($ocrProcessTimeout, $seconds)), max(1, min($ocrTotalTimeout, $seconds)), $tools, $ocrLanguages, $jobDirectory);
    $chat = static fn (string $system, string $user, array $schema, float $temperature, ?int $timeout = null): mixed
        => $ollama->chatJson($system, $user, $schema, $temperature, $timeout);
    $makeWorker = static fn (?string $digest): AiDocumentWorker => new AiDocumentWorker($stateDirectory, $ocrFactory, $chat, $ollama->model(),
        $digest, 'tesseract+poppler (' . $ocrLanguages . ')', $maxInput, $maxJobSeconds, $ollama->timeoutSeconds());
    // Validate every setting now, on every request (health included), so a wrong value makes
    // the worker report "not_configured" instead of failing each job when it arrives.
    $ocrCheck = new OcrService($stateDirectory, $ocrProcessTimeout, $ocrTotalTimeout, $tools, $ocrLanguages);
    $makeWorker(null);
    $http = new AiWorkerHttp(
        WorkerSignature::fromEnvironment('SMART_ASSESS_WORKER_SECRET'),
        static fn (): AiDocumentWorker => $makeWorker($ollama->modelDigest()),
        $stateDirectory,
        $maxBody,
        // Ready: state folder writable, OCR tools and languages installed, Ollama lists the model.
        static function () use ($stateDirectory, $ocrCheck, $ollama): bool|string {
            if (!is_writable($stateDirectory)) {
                error_log('[SMART_ASSESS_WORKER] Not configured: the state folder is not writable.');
                return AiWorkerHttp::NOT_CONFIGURED;
            }
            $problems = $ocrCheck->readinessProblems();
            if ($problems !== []) {
                error_log('[SMART_ASSESS_WORKER] Not configured: OCR ' . implode(', ', $problems) . '.');
                return AiWorkerHttp::NOT_CONFIGURED;
            }
            $model = $ollama->modelStatus();
            if ($model === 'missing') {
                error_log('[SMART_ASSESS_WORKER] Not configured: Ollama does not have the configured model.');
                return AiWorkerHttp::NOT_CONFIGURED;
            }
            return $model === 'ready' ? true : AiWorkerHttp::NOT_READY;
        },
        60,
        // A job may run at most MAX_JOB_SECONDS; holding the lock much longer means it is stuck.
        $maxJobSeconds + 120
    );
} catch (Throwable $exception) {
    // Unsigned (there may be no valid secret); the dispatcher reports it as a setting to fix
    // and claims nothing, so no job uses an attempt.
    error_log('[SMART_ASSESS_WORKER] Not configured: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo '{"ok":false,"error":"not_configured"}';
    exit;
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_') && is_string($value)) {
        $headers[str_replace('_', '-', substr($name, 5))] = $value;
    }
}
$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > $maxBody) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo '{"error":"too_large"}';
    exit;
}
$body = $length > 0 ? (string) file_get_contents('php://input', false, null, 0, $length) : '';
[$status, $responseHeaders, $responseBody] = $http->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
    $headers,
    $body
);
http_response_code($status);
foreach ($responseHeaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $responseBody;
