<?php
declare(strict_types=1);

require_once __DIR__ . '/DocumentAnalysis.php';
require_once __DIR__ . '/OcrService.php';

/**
 * The private AI worker's job: OCR each document, run the rule checks, ask the
 * model for structured findings and return a result that matches
 * DocumentAnalysis::validateWorkerResult(). Keeps nothing: each job's files
 * live in a private 0700 folder that is always removed. Never logs document
 * text, names or numbers.
 */
final class AiDocumentWorker
{
    public const MAX_DOCUMENTS = 10;
    public const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;
    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    private const MODEL_ATTEMPTS = 2;
    /** A second model try is only made if at least this much of the job's time budget is left. */
    private const MIN_SECOND_MODEL_SECONDS = 30;
    public const MIN_BUDGET_SECONDS = 30;
    public const MAX_BUDGET_SECONDS = 1800;

    private readonly Closure $clock;

    /**
     * Time limits: each job has a budget (the smaller of the application's
     * time_budget_seconds and $maxJobSeconds). Part of it is kept for the
     * model (its timeout, at most half the budget); OCR gets the rest, and
     * each document's OCR is limited to what is left. Documents that no longer
     * fit are reported as not read ("time_budget_exceeded"), so Staff check
     * them by hand, and the worker still answers within the budget.
     *
     * @param Closure(string,int):OcrService $ocrFactory builds an OCR service confined to a job folder,
     *        allowed at most the given number of seconds
     * @param null|Closure(string,string,array,float,int):mixed $chat asks the model for JSON within the
     *        given number of seconds (null: no model)
     * @param null|Closure():float $clock seconds, for tests; defaults to a monotonic clock
     */
    public function __construct(
        private readonly string $stateDirectory,
        private readonly Closure $ocrFactory,
        private readonly ?Closure $chat,
        private readonly string $modelName,
        private readonly ?string $modelDigest,
        private readonly string $ocrEngine,
        private readonly int $maxInputCharacters = 16_000,
        private readonly int $maxJobSeconds = 900,
        private readonly int $modelTimeoutSeconds = 180,
        ?Closure $clock = null
    ) {
        if (!is_dir($stateDirectory) || !is_writable($stateDirectory)) {
            throw new InvalidArgumentException('The worker state directory must exist and be writable.');
        }
        if ($maxInputCharacters < 1000 || $maxInputCharacters > 200_000) {
            throw new InvalidArgumentException('The model input limit is out of range.');
        }
        if ($maxJobSeconds < self::MIN_BUDGET_SECONDS || $maxJobSeconds > self::MAX_BUDGET_SECONDS || $modelTimeoutSeconds < 5 || $modelTimeoutSeconds > 900) {
            throw new InvalidArgumentException('A worker time limit is out of range.');
        }
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * Strictly validates a job sent by the application.
     *
     * @throws UnexpectedValueException
     */
    public static function validateJob(mixed $job): array
    {
        $keys = ['version', 'job_id', 'declared', 'documents'];
        if (!is_array($job) || (array_keys($job) !== $keys && array_keys($job) !== [...$keys, 'time_budget_seconds']) || $job['version'] !== 1) {
            throw new UnexpectedValueException('job must contain exactly version, job_id, declared, documents and optionally time_budget_seconds');
        }
        $budget = $job['time_budget_seconds'] ?? null;
        if ($budget !== null && (!is_int($budget) || $budget < self::MIN_BUDGET_SECONDS || $budget > self::MAX_BUDGET_SECONDS)) {
            throw new UnexpectedValueException('time_budget_seconds is out of range');
        }
        if (!is_int($job['job_id']) || $job['job_id'] < 1) {
            throw new UnexpectedValueException('job_id must be a positive integer');
        }
        $declared = $job['declared'];
        $declaredKeys = ['flow', 'is_owner', 'first_name', 'last_name', 'arp_number', 'document_type', 'transfer_type'];
        if (!is_array($declared) || array_keys($declared) !== $declaredKeys
            || !in_array($declared['flow'], ['docreq', 'landtransfer'], true) || !is_bool($declared['is_owner'])) {
            throw new UnexpectedValueException('declared details are not valid');
        }
        foreach (['first_name', 'last_name', 'arp_number', 'document_type', 'transfer_type'] as $field) {
            if (!is_string($declared[$field]) || mb_strlen($declared[$field]) > 150 || !mb_check_encoding($declared[$field], 'UTF-8')) {
                throw new UnexpectedValueException('declared ' . $field . ' is not valid');
            }
        }
        if (!is_array($job['documents']) || !array_is_list($job['documents']) || count($job['documents']) > self::MAX_DOCUMENTS) {
            throw new UnexpectedValueException('documents must be a list of at most ' . self::MAX_DOCUMENTS);
        }
        $documents = [];
        foreach ($job['documents'] as $document) {
            if (!is_array($document) || array_keys($document) !== ['key', 'label', 'mime', 'content_base64']
                || !is_string($document['key']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $document['key'])
                || isset($documents[$document['key']])
                || !is_string($document['label']) || mb_strlen($document['label']) > 150
                || !is_string($document['mime']) || !isset(self::MIME_EXTENSIONS[$document['mime']])
                || !is_string($document['content_base64'])) {
                throw new UnexpectedValueException('a document entry is not valid');
            }
            $bytes = base64_decode($document['content_base64'], true);
            // Same limit as uploads and OCR: strictly smaller than 5 MB.
            if ($bytes === false || $bytes === '' || strlen($bytes) >= self::MAX_DOCUMENT_BYTES) {
                throw new UnexpectedValueException('a document is empty, not base64, or not smaller than 5 MB');
            }
            $documents[$document['key']] = ['key' => $document['key'], 'label' => $document['label'], 'mime' => $document['mime'], 'bytes' => $bytes];
        }
        return ['job_id' => $job['job_id'], 'declared' => $declared, 'documents' => array_values($documents), 'time_budget_seconds' => $budget];
    }

    /** Seconds this job may take: the application's budget, never more than this worker allows. */
    public function budgetSeconds(array $job): int
    {
        return min($this->maxJobSeconds, (int) ($job['time_budget_seconds'] ?? $this->maxJobSeconds));
    }

    /**
     * Runs a validated job within its time budget. Throws
     * RuntimeException('model_unavailable') when the model cannot be reached,
     * ('model_timeout') when it did not answer in time, and
     * ('worker_not_configured') when an OCR setting is invalid, so the
     * application can retry later. If the process is killed, the job folder is
     * left behind and WorkerStateCleaner removes it under the worker lock.
     */
    public function run(array $job): array
    {
        $budget = $this->budgetSeconds($job);
        $deadline = ($this->clock)() + $budget;
        $modelReserve = $this->chat === null ? 0 : min($this->modelTimeoutSeconds, intdiv($budget, 2));
        $jobDirectory = $this->stateDirectory . DIRECTORY_SEPARATOR . 'job-' . bin2hex(random_bytes(16));
        if (!@mkdir($jobDirectory, 0700) || !chmod($jobDirectory, 0700)) {
            throw new RuntimeException('job_storage_failed');
        }
        try {
            $labels = [];
            $checks = [];
            $texts = [];
            foreach ($job['documents'] as $index => $document) {
                $labels[$document['key']] = $document['label'];
                $ocrSeconds = (int) floor($deadline - $modelReserve - ($this->clock)());
                if ($ocrSeconds < 1) {
                    // No time left for this document: say so instead of overrunning the budget.
                    $ocr = ['success' => false, 'text' => '', 'error_code' => 'time_budget_exceeded', 'pages_processed' => 0, 'extraction_method' => null];
                } else {
                    $path = $jobDirectory . DIRECTORY_SEPARATOR . 'document-' . $index . '.' . self::MIME_EXTENSIONS[$document['mime']];
                    if (file_put_contents($path, $document['bytes'], LOCK_EX) === false || !chmod($path, 0600)) {
                        throw new RuntimeException('job_storage_failed');
                    }
                    try {
                        $ocrService = ($this->ocrFactory)($jobDirectory, $ocrSeconds);
                    } catch (InvalidArgumentException) {
                        // An OCR setting is invalid: not this job's fault, and no attempt should count.
                        throw new RuntimeException('worker_not_configured');
                    }
                    $ocr = $ocrService->extract($path);
                    @unlink($path);
                }
                $text = $ocr['success'] ? DocumentAnalysis::normalizeText($ocr['text']) : '';
                $readability = DocumentAnalysis::readability($document['key'], $text, $ocr['success']);
                $texts[$document['key']] = ['text' => $text, 'readability' => $readability, 'label' => $document['label']];
                $checks[] = [
                    'key' => $document['key'],
                    'ocr_status' => $ocr['success'] ? 'ok' : 'failed',
                    'ocr_error' => $ocr['success'] ? null : (string) $ocr['error_code'],
                    'readability' => $readability,
                    'pages' => (int) $ocr['pages_processed'],
                    'method' => $ocr['extraction_method'],
                    'text_characters' => mb_strlen($text),
                ];
            }

            $declared = $job['declared'];
            $ruleFindings = DocumentAnalysis::ruleFindings($texts, [
                'last_name' => $declared['last_name'],
                'is_owner' => $declared['is_owner'],
                'arp_number' => $declared['arp_number'],
            ], $labels);

            return [
                // Version 2: the model's "evidence" quotes were checked against the OCR text (askModel()).
                'version' => DocumentAnalysis::WORKER_RESULT_VERSION,
                'documents' => $checks,
                'rule_findings' => $ruleFindings,
                'model' => $this->askModel($declared, $texts, $deadline),
                'ocr_engine' => $this->ocrEngine,
            ];
        } finally {
            foreach (glob($jobDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($jobDirectory);
        }
    }

    private function askModel(array $declared, array $texts, float $deadline): array
    {
        $readable = array_filter($texts, static fn (array $document): bool => $document['readability'] !== 'unreadable');
        if ($this->chat === null || $readable === []) {
            return ['status' => 'skipped', 'name' => $this->chat === null ? null : $this->modelName, 'digest' => null, 'output' => null];
        }
        $documents = [];
        foreach ($texts as $key => $document) {
            $documents[] = ['key' => $key, 'label' => $document['label'], 'text' => $document['text']];
        }
        $perDocument = max(500, intdiv($this->maxInputCharacters, max(1, count($documents))));
        $prompt = DocumentAnalysis::userPrompt([
            'request_type' => $declared['flow'] === 'landtransfer' ? 'Land Transfer' : 'Document Request',
            'applicant_is_property_owner' => $declared['is_owner'],
            'applicant_name' => trim($declared['first_name'] . ' ' . $declared['last_name']),
            'arp_or_title_number' => $declared['arp_number'],
            'document_requested' => $declared['document_type'],
            'transfer_type' => $declared['transfer_type'],
        ], $documents, $perDocument, bin2hex(random_bytes(16)));

        $keys = array_keys($texts);
        for ($attempt = 1; $attempt <= self::MODEL_ATTEMPTS; $attempt++) {
            $remaining = (int) floor($deadline - ($this->clock)());
            if ($attempt > 1 && $remaining < self::MIN_SECOND_MODEL_SECONDS) {
                break;   // no time for a second try: report the invalid answer instead of overrunning
            }
            // The first try always gets a few seconds, even if OCR used slightly more than its share.
            $timeout = max(5, min($this->modelTimeoutSeconds, $remaining));
            try {
                $output = ($this->chat)(DocumentAnalysis::systemPrompt(), $prompt, DocumentAnalysis::modelSchema($keys), $attempt === 1 ? 0.0 : 0.1, $timeout);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'ollama_invalid_json') {
                    continue;
                }
                throw new RuntimeException($exception->getMessage() === 'ollama_timeout' ? 'model_timeout' : 'model_unavailable');
            }
            try {
                // Checked here so a bad answer can be retried; returned in the model's own shape,
                // because the application validates it again independently. Only here is the OCR
                // text known, so here every "evidence" that is not an exact quote of it is removed.
                DocumentAnalysis::validateModelOutput($output, $keys);
                return ['status' => 'ok', 'name' => $this->modelName, 'digest' => $this->modelDigest,
                    'output' => DocumentAnalysis::keepQuotedEvidence($output, $texts)];
            } catch (UnexpectedValueException) {
                continue;   // malformed or unexpected output is never used
            }
        }
        return ['status' => 'invalid_output', 'name' => $this->modelName, 'digest' => $this->modelDigest, 'output' => null];
    }
}
