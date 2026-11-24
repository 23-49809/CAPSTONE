<?php
declare(strict_types=1);

/*
 * Release-order check (bin/ai-worker-compat-check.php), no database or network: fixture copies
 * of the application and worker source in a temporary folder, including releases on each side
 * of a paired job/result protocol upgrade.
 *
 *   php tests/ai_worker_compat_test.php
 */

$root = dirname(__DIR__);
require_once $root . '/app/services/DocumentAnalysis.php';
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 500)];
};
$work = sys_get_temp_dir() . '/sa-compat-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($work): void {
    exec('rm -rf ' . escapeshellarg($work));
});
$analysis = (string) file_get_contents($root . '/app/services/DocumentAnalysis.php');
$verification = (string) file_get_contents($root . '/app/services/AiVerification.php');
$workerRun = (string) file_get_contents($root . '/app/services/AiDocumentWorker.php');
$tree = static function (string $name, array $files) use ($work): string {
    foreach ($files as $path => $content) {
        @mkdir(dirname($work . '/' . $name . '/' . $path), 0700, true);
        file_put_contents($work . '/' . $name . '/' . $path, $content);
    }
    return $work . '/' . $name;
};
$run = static function (string $app, string $worker) use ($root): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/ai-worker-compat-check.php') . ' --app=' . escapeshellarg($app) . ' --worker=' . escapeshellarg($worker) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

$check('this release: the application accepts versions 1 to 7; the worker answers with 7', str_contains($analysis, 'const WORKER_RESULT_VERSIONS = [1, 2, 3, 4, 5, 6, 7];')
    && str_contains($analysis, 'const WORKER_RESULT_VERSION = 7;'));
// The immediately previous release accepts through result version 6.
$oldAnalysis = str_replace(['const WORKER_RESULT_VERSIONS = [1, 2, 3, 4, 5, 6, 7];', 'const WORKER_RESULT_VERSION = 7;'], ['const WORKER_RESULT_VERSIONS = [1, 2, 3, 4, 5, 6];', 'const WORKER_RESULT_VERSION = 6;'], $analysis);
$check('this release: the application accepts versions 1 to 8; the worker answers with 8', str_contains($analysis, 'const WORKER_RESULT_VERSIONS = [1, 2, 3, 4, 5, 6, 7, 8];')
    && str_contains($analysis, 'const WORKER_RESULT_VERSION = 8;'));
// The immediately previous release accepts through result version 7.
$oldAnalysis = str_replace(['const WORKER_RESULT_VERSIONS = [1, 2, 3, 4, 5, 6, 7, 8];', 'const WORKER_RESULT_VERSION = 8;'], ['const WORKER_RESULT_VERSIONS = [1, 2, 3, 4, 5, 6, 7];', 'const WORKER_RESULT_VERSION = 7;'], $analysis);
$oldVerification = str_replace("'version' => 2, 'job_id'", "'version' => 1, 'job_id'", $verification);
$oldWorkerRun = str_replace('public const JOB_VERSIONS = [1, 2];', 'public const JOB_VERSIONS = [1];', $workerRun);
$newApp = $tree('new-app', ['app/services/DocumentAnalysis.php' => $analysis, 'app/services/AiVerification.php' => $verification]);
$oldApp = $tree('old-app', ['app/services/DocumentAnalysis.php' => $oldAnalysis, 'app/services/AiVerification.php' => $oldVerification]);
$newWorker = $tree('new-worker', ['app/services/DocumentAnalysis.php' => $analysis, 'app/services/AiDocumentWorker.php' => $workerRun]);
$oldWorker = $tree('old-worker', ['app/services/DocumentAnalysis.php' => $oldAnalysis, 'app/services/AiDocumentWorker.php' => $oldWorkerRun]);

[$code, $out] = $run($newApp, $newWorker);
$check('new application + new worker: compatible (exit 0)', $code === 0 && str_contains($out, 'answers with version 7') && str_contains($out, "\ncompatible"), $out);
[$code, $out] = $run($oldApp, $newWorker);
$check('old application + new worker: NOT compatible because the old app rejects result version 7', $code === 1 && str_contains($out, 'NOT COMPATIBLE')
$check('new application + new worker: compatible (exit 0)', $code === 0 && str_contains($out, 'answers with version 8') && str_contains($out, "\ncompatible"), $out);
[$code, $out] = $run($oldApp, $newWorker);
$check('old application + new worker: NOT compatible because the old app rejects result version 8', $code === 1 && str_contains($out, 'NOT COMPATIBLE')
    && str_contains($out, 'does not accept the worker\'s result version'), $out);
[$code, $out] = $run($newApp, $oldWorker);
$check('new application + old worker: NOT compatible because the old worker accepts only job version 1', $code === 1 && str_contains($out, 'does not accept the application\'s job version'), $out);
[$code, $out] = $run($oldApp, $oldWorker);
$check('old application + old worker: compatible (exit 0)', $code === 0 && str_contains($out, 'accepts jobs version 1') && str_contains($out, "\ncompatible"), $out);
[$code, $out] = $run($work . '/missing', $newWorker);
$check('an unreadable application folder: exit 2, nothing decided', $code === 2, $out);

// The operator smoke test (bin/ai-worker-smoke-test.php) expects the version this release's worker
// answers with, from the same constant, and still runs the application's full validation.
$smokeTest = (string) file_get_contents($root . '/bin/ai-worker-smoke-test.php');
$check('smoke test: expects DocumentAnalysis::WORKER_RESULT_VERSION exactly (no hard-coded version number)',
    str_contains($smokeTest, "(\$raw['version'] ?? null) === DocumentAnalysis::WORKER_RESULT_VERSION")
    && !preg_match("/\\\$raw\\['version'\\] \\?\\? null\\) === \\d/", $smokeTest), 'version check');
$check('smoke test: still requires the application\'s own validation of the answer', str_contains($smokeTest, "DocumentAnalysis::validateWorkerResult(\$raw, ['ownerId']);")
    && str_contains($smokeTest, "\$check('the application accepts the answer (its own validation)', false, 'rejected');"));
$check('smoke test: the version it expects is one the application accepts', in_array(DocumentAnalysis::WORKER_RESULT_VERSION, DocumentAnalysis::WORKER_RESULT_VERSIONS, true));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
