<?php
declare(strict_types=1);

/*
 * Release-order check (bin/ai-worker-compat-check.php), no database or network: fixture copies
 * of the application and worker source in a temporary folder, including the release that
 * accepts results version 1 and 2 only (main before the version 3 worker).
 *
 *   php tests/ai_worker_compat_test.php
 */

$root = dirname(__DIR__);
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

$check('this release: the application accepts versions 1, 2 and 3; the worker answers with 3', str_contains($analysis, 'const WORKER_RESULT_VERSIONS = [1, 2, 3];')
    && str_contains($analysis, 'const WORKER_RESULT_VERSION = 3;'));
$oldAnalysis = str_replace(['const WORKER_RESULT_VERSIONS = [1, 2, 3];', 'const WORKER_RESULT_VERSION = 3;'], ['const WORKER_RESULT_VERSIONS = [1, 2];', 'const WORKER_RESULT_VERSION = 2;'], $analysis);
$newApp = $tree('new-app', ['app/services/DocumentAnalysis.php' => $analysis, 'app/services/AiVerification.php' => $verification]);
$oldApp = $tree('old-app', ['app/services/DocumentAnalysis.php' => $oldAnalysis, 'app/services/AiVerification.php' => $verification]);
$newWorker = $tree('new-worker', ['app/services/DocumentAnalysis.php' => $analysis, 'app/services/AiDocumentWorker.php' => $workerRun]);
$oldWorker = $tree('old-worker', ['app/services/DocumentAnalysis.php' => $oldAnalysis, 'app/services/AiDocumentWorker.php' => $workerRun]);

[$code, $out] = $run($newApp, $newWorker);
$check('new application + new worker: compatible (exit 0)', $code === 0 && str_contains($out, 'answers with version 3') && str_contains($out, "\ncompatible"), $out);
[$code, $out] = $run($oldApp, $newWorker);
$check('application still on versions 1-2 + version 3 worker: NOT compatible (exit 1), deploy the application first', $code === 1 && str_contains($out, 'NOT COMPATIBLE')
    && str_contains($out, 'Deploy the application release first'), $out);
[$code, $out] = $run($newApp, $oldWorker);
$check('new application + version 2 worker (application deployed first): compatible (exit 0)', $code === 0 && str_contains($out, 'answers with version 2'), $out);
[$code, $out] = $run($work . '/missing', $newWorker);
$check('an unreadable application folder: exit 2, nothing decided', $code === 2, $out);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
