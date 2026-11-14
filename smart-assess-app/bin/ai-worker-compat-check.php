<?php
declare(strict_types=1);

/*
 * Release-order check for the AI worker: is a worker copy compatible with the deployed
 * application? Run it BEFORE refreshing /opt/smart-assess-ai-worker (and before installing
 * or starting the dispatcher). It only reads source files; it changes nothing.
 *
 *   php bin/ai-worker-compat-check.php --app=/var/www/smart-assess --worker=/path/to/new/worker/copy
 *
 * --app     the application root the dispatcher runs (its app/services/DocumentAnalysis.php
 *           decides which worker result versions it accepts)
 * --worker  the worker root (worker/index.php and app/services/), e.g. a staged new copy, or
 *           /opt/smart-assess-ai-worker for the one installed now
 *
 * Exit codes: 0 compatible; 1 NOT compatible (do not install this worker with this
 * application: deploy the application first); 2 the files could not be read or understood.
 *
 * Why: worker results carry a version. A version 2 worker (it checks the model's quotes
 * against the OCR text) is understood only by an application that accepts version 2; the
 * release 6482fcd0 accepts version 1 only and would fail every job ("invalid worker result").
 * Release order: deploy the application first, then refresh the worker.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['app:', 'worker:']);
$app = rtrim((string) ($options['app'] ?? ''), '/');
$worker = rtrim((string) ($options['worker'] ?? ''), '/');
if ($app === '' || $worker === '') {
    fwrite(STDERR, "Usage: php bin/ai-worker-compat-check.php --app=<application root> --worker=<worker root>\n");
    exit(2);
}
$read = static function (string $path): string {
    $text = @file_get_contents($path);
    if (!is_string($text) || $text === '') {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(2);
    }
    return $text;
};

/** Worker result versions the application accepts. */
$appAnalysis = $read($app . '/app/services/DocumentAnalysis.php');
if (preg_match('/const WORKER_RESULT_VERSIONS = \[([0-9,\s]+)\];/', $appAnalysis, $m)) {
    $accepted = array_map('intval', preg_split('/\s*,\s*/', trim($m[1])));
} elseif (str_contains($appAnalysis, "if (\$object['version'] !== 1) {")) {
    $accepted = [1];   // releases before the versioned check (e.g. 6482fcd0)
} else {
    fwrite(STDERR, "Cannot tell which worker result versions {$app} accepts.\n");
    exit(2);
}

/** Result version the worker answers with. */
$workerRun = $read($worker . '/app/services/AiDocumentWorker.php');
if (str_contains($workerRun, "'version' => DocumentAnalysis::WORKER_RESULT_VERSION,")) {
    $workerAnalysis = $read($worker . '/app/services/DocumentAnalysis.php');
    if (!preg_match('/const WORKER_RESULT_VERSION = (\d+);/', $workerAnalysis, $m)) {
        fwrite(STDERR, "Cannot read the worker result version in {$worker}.\n");
        exit(2);
    }
    $answers = (int) $m[1];
} elseif (preg_match("/return \[\s*'version' => (\d+),\s*'documents' => \\\$checks,/", $workerRun, $m)) {
    $answers = (int) $m[1];   // older workers write the number directly
} else {
    fwrite(STDERR, "Cannot tell which result version the worker in {$worker} answers with.\n");
    exit(2);
}

/** The job format the application sends, and the one the worker accepts (both version 1 so far). */
$appJob = $read($app . '/app/services/AiVerification.php');
$workerJob = $workerRun;
$sends = preg_match("/'payload' => \[\s*'version' => (\d+),/", $appJob, $m) ? (int) $m[1] : null;
$takes = preg_match("/\\\$job\['version'\] !== (\d+)\)/", $workerJob, $m) ? (int) $m[1] : null;
if ($sends === null || $takes === null) {
    fwrite(STDERR, "Cannot read the job format version on both sides.\n");
    exit(2);
}

printf("application %s accepts worker results version %s and sends jobs version %d\n", $app, implode(', ', $accepted), $sends);
printf("worker      %s answers with version %d and accepts jobs version %d\n", $worker, $answers, $takes);
if (!in_array($answers, $accepted, true) || $sends !== $takes) {
    echo "NOT COMPATIBLE: do not install this worker with this application. Deploy the application release first, then refresh the worker.\n";
    exit(1);
}
echo "compatible\n";
exit(0);
