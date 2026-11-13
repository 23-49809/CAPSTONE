<?php
declare(strict_types=1);

/*
 * AI document verification: time limits, worker readiness, error mapping and
 * the deployment files. No database, no network beyond 127.0.0.1, no real
 * OCR or model (fakes only). All names and numbers are made up.
 *
 *   php tests/ai_retry_timeouts_test.php
 *
 * The database side of retries (attempts, outage counter, lease, claim token)
 * is checked in tests/ai_verification_workflow_test.php.
 * Exit code 0 when every check passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$app = dirname(__DIR__);
require_once $app . '/app/services/AiWorkerClient.php';
require_once $app . '/app/services/AiWorkerHttp.php';
require_once $app . '/app/services/AiDocumentWorker.php';
require_once $app . '/app/services/OllamaClient.php';
require_once $app . '/app/services/DocumentRequirements.php';
require_once $app . '/app/services/AiVerification.php';
require_once $app . '/app/services/WorkerStateCleaner.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};
$rejects = static function (Closure $action): bool {
    try {
        $action();
        return false;
    } catch (Throwable) {
        return true;
    }
};
$work = sys_get_temp_dir() . '/sa-ai-retry-' . bin2hex(random_bytes(4));
mkdir($work . '/state', 0700, true);
$work = (string) realpath($work);
$servers = [];
register_shutdown_function(static function () use (&$servers, $work): void {
    foreach ($servers as $server) {
        proc_terminate($server);
    }
    exec('rm -rf ' . escapeshellarg($work));
});
$freePort = static function (): int {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    return $port;
};
$serve = static function (string $router) use (&$servers, $freePort): int {
    $port = $freePort();
    $servers[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    return $port;
};
$secret = bin2hex(random_bytes(24));
$signer = new WorkerSignature($secret);

// ================= Time limits line up =================
$aligned = true;
$bad = [];
for ($t = AiWorkerClient::MIN_TIMEOUT_SECONDS; $t <= AiWorkerClient::MAX_TIMEOUT_SECONDS; $t += 15) {
    $client = new AiWorkerClient('http://127.0.0.1:1', $signer, $t);
    $budget = $client->workerBudgetSeconds();
    $lease = $client->leaseSeconds();
    if (!($budget >= AiDocumentWorker::MIN_BUDGET_SECONDS && $budget < $t && $lease > $t && $budget <= AiDocumentWorker::MAX_BUDGET_SECONDS)) {
        $aligned = false;
        $bad[] = [$t, $budget, $lease];
    }
}
$check('for every allowed timeout: worker budget < HTTP timeout < lease, and the worker accepts the budget', $aligned, $bad);
$default = new AiWorkerClient('http://127.0.0.1:1', $signer);
$check('defaults: timeout 900 s, worker budget 780 s, lease 1020 s', $default->timeoutSeconds() === 900 && $default->workerBudgetSeconds() === 780
    && $default->leaseSeconds() === 1020 && AiVerification::LEASE_SECONDS === 1020, [$default->workerBudgetSeconds(), $default->leaseSeconds()]);
$check('timeouts below 60 s or above 1800 s are refused', $rejects(static fn () => new AiWorkerClient('http://127.0.0.1:1', $signer, 59))
    && $rejects(static fn () => new AiWorkerClient('http://127.0.0.1:1', $signer, 1801)));

// ================= Worker client against a fake worker =================
file_put_contents($work . '/case.txt', 'ok');
file_put_contents($work . '/fake-worker.php', '<?php
require_once ' . var_export($app . '/app/services/WorkerSignature.php', true) . ';
$signer = new WorkerSignature(' . var_export($secret, true) . ');
$case = trim((string) file_get_contents(__DIR__ . "/case.txt"));
$nonce = (string) ($_SERVER["HTTP_X_SA_NONCE"] ?? "");
header("Content-Type: application/json");
if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/v1/health") {
    [$status, $body] = match ($case) {
        "health-down" => [503, "{\"ok\":false,\"error\":\"not_ready\"}"],
        "health-old" => [503, "{\"ok\":false}"],
        "health-not-configured" => [503, "{\"ok\":false,\"error\":\"not_configured\"}"],
        "health-cleanup" => [503, "{\"ok\":false,\"error\":\"cleanup_failed\"}"],
        "health-stuck" => [503, "{\"ok\":false,\"error\":\"stuck\"}"],
        "health-odd" => [200, "{\"ok\":\"yes\"}"],
        "health-html" => [200, "<html>Welcome to nginx!</html>"],
        "health-404" => [404, "<html>Not Found</html>"],
        "health-502" => [502, "<html>Bad Gateway</html>"],
        default => [200, "{\"ok\":true}"],
    };
    http_response_code($status);
    echo $body;
    return;
}
file_put_contents(__DIR__ . "/last-body.json", file_get_contents("php://input"));
[$status, $body, $signed] = match ($case) {
    "model_timeout" => [503, "{\"error\":\"model_timeout\"}", true],
    "worker_error" => [503, "{\"error\":\"worker_error\"}", true],
    "worker_error_500" => [500, "{\"error\":\"worker_error\"}", true],
    "busy" => [503, "{\"error\":\"busy\"}", true],
    "model_unavailable" => [503, "{\"error\":\"model_unavailable\"}", true],
    "job_storage_failed" => [503, "{\"error\":\"job_storage_failed\"}", true],
    "signed_not_configured" => [503, "{\"error\":\"not_configured\"}", true],
    "signed_cleanup_failed" => [503, "{\"error\":\"cleanup_failed\"}", true],
    "unsigned_not_configured" => [503, "{\"ok\":false,\"error\":\"not_configured\"}", false],
    "proxy_502" => [502, "<html>Bad Gateway</html>", false],
    "php_fatal_500" => [500, "", false],
    "proxy_503" => [503, "<html>Service Unavailable</html>", false],
    "proxy_504" => [504, "<html>Gateway Time-out</html>", false],
    "nginx_413" => [413, "<html>413 Request Entity Too Large</html>", false],
    "nginx_404" => [404, "<html>Not Found</html>", false],
    "replayed_409" => [409, "{\"error\":\"replayed\"}", false],
    "unauthorized" => [401, "{\"error\":\"unauthorized\"}", false],
    "invalid_job" => [400, "{\"error\":\"invalid_job\"}", true],
    "unsigned_ok" => [200, "{\"version\":1}", false],
    "redirect_301" => [301, "", false],
    default => [200, "{\"fine\":true}", true],
};
http_response_code($status);
if ($case === "wrong_signature") { header("X-SA-Response-Signature: " . str_repeat("0", 64)); }
elseif ($signed) { header("X-SA-Response-Signature: " . $signer->signResponse($nonce, $status, $body)); }
echo $body;
');
$workerPort = $serve($work . '/fake-worker.php');
$client = new AiWorkerClient('http://127.0.0.1:' . $workerPort, $signer);
$errorOf = static function (string $case) use ($client, $work): string {
    file_put_contents($work . '/case.txt', $case);
    try {
        $client->analyze(['version' => 1, 'job_id' => 1, 'declared' => [], 'documents' => []]);
        return 'no error';
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
};
file_put_contents($work . '/case.txt', 'ok');
$answer = $client->analyze(['version' => 1, 'job_id' => 5, 'declared' => [], 'documents' => []]);
$sent = json_decode((string) file_get_contents($work . '/last-body.json'), true);
$check('each job tells the worker its time budget (780 s by default), as the last key', ($answer['fine'] ?? null) === true
    && ($sent['time_budget_seconds'] ?? null) === 780 && array_key_last((array) $sent) === 'time_budget_seconds', $sent);
// case => [expected code, expected retry class]
$mapping = [
    'model_timeout' => ['model_timeout', 'counted'], 'worker_error' => ['worker_error', 'counted'], 'worker_error_500' => ['worker_error', 'counted'],
    'busy' => ['worker_busy', 'transient'], 'model_unavailable' => ['model_unavailable', 'crash'], 'job_storage_failed' => ['worker_unavailable', 'transient'],
    'signed_not_configured' => ['worker_not_configured', 'configuration'], 'signed_cleanup_failed' => ['worker_cleanup_failed', 'configuration'],
    'unsigned_not_configured' => ['worker_not_configured', 'configuration'], 'proxy_502' => ['worker_crashed', 'crash'], 'php_fatal_500' => ['worker_crashed', 'crash'],
    'proxy_503' => ['worker_unavailable', 'transient'], 'proxy_504' => ['worker_timeout', 'counted'], 'nginx_413' => ['worker_body_too_large', 'configuration'],
    'nginx_404' => ['worker_proxy_error', 'configuration'], 'replayed_409' => ['worker_unavailable', 'transient'], 'unauthorized' => ['worker_auth_failed', 'configuration'],
    'invalid_job' => ['worker_rejected', 'permanent'], 'unsigned_ok' => ['worker_proxy_error', 'configuration'], 'redirect_301' => ['worker_proxy_error', 'configuration'],
    'wrong_signature' => ['worker_bad_response', 'counted'],
];
$wrong = [];
foreach ($mapping as $case => [$expectedCode, $expectedClass]) {
    $got = $errorOf($case);
    if ($got !== $expectedCode || AiVerification::retryClass($got) !== $expectedClass) {
        $wrong[$case] = [$got, AiVerification::retryClass($got)];
    }
}
$check('every worker answer maps to the right code and retry class (signed codes; unsigned 500/502/503/504/409/413/404/301/200; 401; wrong signature)', $wrong === [], $wrong);
$check('unsigned answers never use an attempt (no unsigned case is "counted" except a web-server 504 timeout)', array_keys(array_filter($mapping,
    static fn (array $m, string $case): bool => $m[1] === 'counted' && !in_array($case, ['model_timeout', 'worker_error', 'worker_error_500', 'wrong_signature'], true), ARRAY_FILTER_USE_BOTH)) === ['proxy_504']);
$check('a successful answer still needs a valid signature (unsigned 200 is refused)', $errorOf('unsigned_ok') !== 'no error' && $errorOf('wrong_signature') !== 'no error');

$healthOf = static function (string $case) use ($client, $work): string {
    file_put_contents($work . '/case.txt', $case);
    return $client->health();
};
$healthCases = ['ok' => 'ready', 'health-down' => 'not_ready', 'health-old' => 'not_ready', 'health-not-configured' => 'not_configured',
    'health-cleanup' => 'cleanup_failed', 'health-stuck' => 'stuck', 'health-odd' => 'not_configured', 'health-html' => 'not_configured', 'health-404' => 'not_configured', 'health-502' => 'not_ready'];
$wrongHealth = array_filter(array_map(static fn (string $case): string => $healthOf($case), array_combine(array_keys($healthCases), array_keys($healthCases))),
    static fn (string $got, string $case): bool => $got !== $healthCases[$case], ARRAY_FILTER_USE_BOTH);
$check('health answers: ready, not ready (503, 502), not configured (worker says so, or a page that is not the worker), cleanup failed', $wrongHealth === [], $wrongHealth);
file_put_contents($work . '/case.txt', 'ok');
$check('health: healthy() is true only when ready', $client->healthy());
$closedPort = $freePort();
$nobody = new AiWorkerClient('http://127.0.0.1:' . $closedPort, $signer);
$check('health: nothing listening is "unreachable" (wait), not a configuration error', $nobody->health() === 'unreachable');
$check('health: nothing listening is not healthy', !$nobody->healthy());
$check('nothing listening: worker_unavailable (transient, not counted)', (static function () use ($nobody): string {
    try {
        $nobody->analyze(['version' => 1]);
        return 'no error';
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
})() === 'worker_unavailable');

// The private CA option must stay CURLOPT_CAINFO (it used to be renumbered by "..." unpacking).
file_put_contents($work . '/ca.pem', "not a real certificate\n");
$withCa = new AiWorkerClient('https://127.0.0.1:' . $closedPort, $signer, 900, 1_048_576, $work . '/ca.pem');
$caOption = (new ReflectionMethod(AiWorkerClient::class, 'caOption'))->invoke($withCa);
$merged = [CURLOPT_HTTPHEADER => [], CURLOPT_SSL_VERIFYHOST => 2] + $caOption;
$check('the CA file is passed to curl as CURLOPT_CAINFO', ($merged[CURLOPT_CAINFO] ?? null) === $work . '/ca.pem' && count($merged) === 3, array_keys($merged));
$check('no other option is set from the CA file when there is none', (new ReflectionMethod(AiWorkerClient::class, 'caOption'))->invoke($default) === []);

// ================= Worker HTTP: readiness and codes =================
$fakeWorker = static fn (Closure $run): Closure => static fn (): object => new class ($run) {
    public function __construct(private readonly Closure $run)
    {
    }

    public function run(array $job): array
    {
        return ($this->run)($job);
    }
};
$job = ['version' => 1, 'job_id' => 9, 'declared' => ['flow' => 'docreq', 'is_owner' => true, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'arp_number' => '', 'document_type' => '', 'transfer_type' => ''],
    'documents' => [['key' => 'ownerId', 'label' => 'Valid ID', 'mime' => 'image/png', 'content_base64' => base64_encode('fake image bytes')]]];
$body = (string) json_encode($job);
$health = static fn (?Closure $ready): array => (new AiWorkerHttp($signer, $fakeWorker(static fn () => []), $work . '/state', 1_000_000, $ready))->handle('GET', '/v1/health', [], '');
$check('worker health: ready -> 200 {"ok":true}', $health(static fn (): bool => true)[0] === 200 && $health(static fn (): bool => true)[2] === '{"ok":true}');
$check('worker health: not ready (e.g. Ollama not answering yet) -> 503 not_ready', $health(static fn (): bool => false)[0] === 503
    && $health(static fn (): bool => false)[2] === '{"ok":false,"error":"not_ready"}' && $health(static fn (): string => 'not_ready')[2] === '{"ok":false,"error":"not_ready"}');
$check('worker health: a setting to fix -> 503 not_configured', $health(static fn (): string => 'not_configured')[2] === '{"ok":false,"error":"not_configured"}');
$check('worker health: a failing readiness check counts as not ready', $health(static fn (): bool => throw new RuntimeException('x'))[2] === '{"ok":false,"error":"not_ready"}');
$timeoutHttp = new AiWorkerHttp($signer, $fakeWorker(static fn () => throw new RuntimeException('model_timeout')), $work . '/state');
$signed = $signer->signRequest('POST', AiWorkerHttp::PATH, $body);
[$status, $headers, $answer] = $timeoutHttp->handle('POST', AiWorkerHttp::PATH, $signed, $body);
$check('worker HTTP: a model timeout is reported as signed 503 model_timeout', $status === 503 && $answer === '{"error":"model_timeout"}'
    && $signer->verifyResponse($signed[WorkerSignature::HEADER_NONCE], 503, $answer, $headers[WorkerSignature::HEADER_RESPONSE_SIGNATURE] ?? ''), [$status, $answer]);
$withBudget = $job + ['time_budget_seconds' => 120];
$signed = $signer->signRequest('POST', AiWorkerHttp::PATH, (string) json_encode($withBudget));
$seen = null;
$budgetHttp = new AiWorkerHttp($signer, $fakeWorker(static function (array $validated) use (&$seen): array {
    $seen = $validated['time_budget_seconds'];
    return ['ok' => true];
}), $work . '/state');
$check('worker HTTP: a job with a time budget is accepted and the budget reaches the worker', $budgetHttp->handle('POST', AiWorkerHttp::PATH, $signed, (string) json_encode($withBudget))[0] === 200 && $seen === 120, $seen);

// ================= A connection dropped mid-job is a crash =================
file_put_contents($work . '/dropper.php', '<?php
$server = stream_socket_server("tcp://127.0.0.1:" . $argv[1]);
file_put_contents($argv[2], "up");
while ($connection = @stream_socket_accept($server, 60)) {
    $request = "";
    while (!str_contains($request, "\r\n\r\n") && !feof($connection)) { $request .= fread($connection, 8192); }
    preg_match("/Content-Length: (\d+)/i", $request, $m);
    $body = substr($request, strpos($request, "\r\n\r\n") + 4);
    while (strlen($body) < (int) ($m[1] ?? 0) && !feof($connection)) { $body .= fread($connection, 8192); }
    fclose($connection);   // hang up without answering, like a worker process that died
}
');
$dropPort = $freePort();
$servers[] = proc_open([PHP_BINARY, $work . '/dropper.php', (string) $dropPort, $work . '/dropper.up'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !is_file($work . '/dropper.up'); $i++) {
    usleep(100000);
}
usleep(200000);
$dropped = (static function () use ($dropPort, $signer): string {
    try {
        (new AiWorkerClient('http://127.0.0.1:' . $dropPort, $signer))->analyze(['version' => 1, 'job_id' => 1, 'declared' => [], 'documents' => []]);
        return 'no error';
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
})();
$check('a connection that drops after the job was sent is worker_crashed (crash class, limited per job)', $dropped === 'worker_crashed'
    && AiVerification::retryClass($dropped) === 'crash', $dropped);

// ================= Leftover job folders (WorkerStateCleaner) =================
$cleanState = $work . '/clean-state';
$outside = $work . '/outside';
mkdir($cleanState, 0700);
mkdir($outside, 0700);
file_put_contents($outside . '/keep.txt', 'not the worker\'s');
$newJobName = static fn (): string => 'job-' . bin2hex(random_bytes(16));
$makeJobFolder = static function (string $state, int $ageSeconds) use ($newJobName, $outside): string {
    $folder = $state . '/' . $newJobName();
    mkdir($folder, 0700);
    file_put_contents($folder . '/document-0.png', 'synthetic client document');
    $ocrFolder = $folder . '/smart-assess-ocr-' . bin2hex(random_bytes(8));
    mkdir($ocrFolder, 0700);
    file_put_contents($ocrFolder . '/page-1.ppm', 'synthetic rendered page');
    symlink($outside . '/keep.txt', $folder . '/link-to-outside');
    symlink($outside, $ocrFolder . '/link-to-outside-folder');
    touch($folder, time() - $ageSeconds);
    return $folder;
};
$stale = $makeJobFolder($cleanState, 600);
$fresh = $makeJobFolder($cleanState, 0);
$linkedJob = $cleanState . '/' . $newJobName();
symlink($outside, $linkedJob);
mkdir($cleanState . '/nonces', 0700);
file_put_contents($cleanState . '/nonces/' . str_repeat('a', 32), '');
file_put_contents($cleanState . '/worker.lock', '');
mkdir($cleanState . '/job-not-hex-' . str_repeat('z', 20), 0700);
file_put_contents($cleanState . '/' . $newJobName() . '.bak', 'x');
touch($cleanState . '/job-not-hex-' . str_repeat('z', 20), time() - 600);
$result = (new WorkerStateCleaner($cleanState, 60))->sweep();
$check('cleanup: an abandoned job folder is removed with everything in it (documents, OCR pages, nested folders)', !file_exists($stale), $result);
$check('cleanup: links inside it are removed as links; what they point to is untouched', is_file($outside . '/keep.txt') && is_dir($outside));
$check('cleanup: a folder changed in the last minute is left alone', is_file($fresh . '/document-0.png'));
$check('cleanup: a link named like a job folder is skipped and its target untouched', is_link($linkedJob) && is_file($outside . '/keep.txt'));
$check('cleanup: nothing else in the state folder is touched (nonces, lock, other names, files)', is_file($cleanState . '/nonces/' . str_repeat('a', 32))
    && is_file($cleanState . '/worker.lock') && is_dir($cleanState . '/job-not-hex-' . str_repeat('z', 20)) && count(glob($cleanState . '/job-*.bak')) === 1);
$check('cleanup: counts are reported (1 removed, 0 failed, 2 skipped)', $result === ['removed' => 1, 'failed' => 0, 'skipped' => 2], $result);
$check('cleanup: the filesystem root is never accepted as the state folder', $rejects(static fn () => new WorkerStateCleaner('/')));
$check('cleanup: a missing state folder is refused', $rejects(static fn () => new WorkerStateCleaner($work . '/does-not-exist')));

$locked = $makeJobFolder($cleanState, 600);
$lockedFolder = glob($locked . '/smart-assess-ocr-*')[0];
chmod($lockedFolder, 0500);   // cannot delete inside: like wrong ownership on the server
$result = (new WorkerStateCleaner($cleanState, 60))->sweep();
$check('cleanup failure: reported as failed, and nothing outside is touched', $result['failed'] === 1 && is_file($lockedFolder . '/page-1.ppm') && is_file($outside . '/keep.txt'), $result);
$cleanHttp = new AiWorkerHttp($signer, $fakeWorker(static fn () => ['should not run' => true]), $cleanState);
[$status, , $answer] = $cleanHttp->handle('GET', '/v1/health', [], '');
$check('cleanup failure: health says cleanup_failed (the dispatcher reports a setting to fix and claims nothing)', $status === 503 && $answer === '{"ok":false,"error":"cleanup_failed"}', $answer);
$signed = $signer->signRequest('POST', AiWorkerHttp::PATH, $body);
[$status, $headers, $answer] = $cleanHttp->handle('POST', AiWorkerHttp::PATH, $signed, $body);
$check('cleanup failure: a job is refused with a signed cleanup_failed and not run', $status === 503 && $answer === '{"error":"cleanup_failed"}'
    && $signer->verifyResponse($signed[WorkerSignature::HEADER_NONCE], 503, $answer, $headers[WorkerSignature::HEADER_RESPONSE_SIGNATURE] ?? ''), $answer);
chmod($lockedFolder, 0700);
[$status, , $answer] = $cleanHttp->handle('GET', '/v1/health', [], '');
$check('after the permissions are fixed, health removes the folder and is ready again', $status === 200 && !file_exists($locked), $answer);

$active = $makeJobFolder($cleanState, 600);
$heldLock = fopen($cleanState . '/worker.lock', 'c');
flock($heldLock, LOCK_EX);
[$status] = $cleanHttp->handle('GET', '/v1/health', [], '');
$check('while a job holds the worker lock, health removes nothing, even an old folder', $status === 200 && is_file($active . '/document-0.png'));
flock($heldLock, LOCK_UN);
fclose($heldLock);
$cleanHttp->handle('GET', '/v1/health', [], '');
$check('once the lock is free, the same folder is removed', !file_exists($active));
$abandonedBeforeJob = $makeJobFolder($cleanState, 600);
$ranJob = false;
$jobHttp = new AiWorkerHttp($signer, $fakeWorker(static function () use (&$ranJob): array {
    $ranJob = true;
    return ['ok' => true];
}), $cleanState);
$signed = $signer->signRequest('POST', AiWorkerHttp::PATH, $body);
$jobHttp->handle('POST', AiWorkerHttp::PATH, $signed, $body);
$check('a new job first removes folders left by interrupted jobs, then runs', !file_exists($abandonedBeforeJob) && $ranJob);
$check('a worker that cannot be built answers a signed not_configured (no attempt), not worker_error', (static function () use ($signer, $cleanState, $body): bool {
    $broken = new AiWorkerHttp($signer, static fn () => throw new InvalidArgumentException('A worker time limit is out of range.'), $cleanState);
    $signed = $signer->signRequest('POST', AiWorkerHttp::PATH, $body);
    [$status, , $answer] = $broken->handle('POST', AiWorkerHttp::PATH, $signed, $body);
    return $status === 503 && $answer === '{"error":"not_configured"}';
})());

// ================= The real worker/index.php: settings, kill and cleanup =================
$tools = $work . '/tools';
mkdir($tools, 0700);
foreach ([
    'tesseract' => 'if (($argv[1] ?? "") === "--list-langs") { echo "List of available languages in \"/fake/\" (2):\neng\nosd\n"; exit(0); }'
        . ' if (is_file(' . var_export($work . '/tesseract-hangs', true) . ')) { sleep(20); }'
        . ' echo "REPUBLIC OF THE PHILIPPINES DRIVER LICENSE DELA CRUZ, JUAN";',
    'pdfinfo' => 'echo "Pages: 1\n";',
    'pdftotext' => 'echo "";',
    'pdftoppm' => 'exit(1);',
] as $name => $source) {
    file_put_contents($tools . '/' . $name, '#!' . PHP_BINARY . "\n<?php\n" . $source);
    chmod($tools . '/' . $name, 0700);
}
file_put_contents($work . '/ollama-ok.php', '<?php header("Content-Type: application/json");
if (str_starts_with($_SERVER["REQUEST_URI"], "/api/tags")) { echo "{\"models\":[{\"name\":\"fake-model:1\",\"digest\":\"sha256fake\"}]}"; return; }
echo json_encode(["message" => ["role" => "assistant", "content" => "{}"]]);');
$ollamaOkPort = $serve($work . '/ollama-ok.php');
$workerState = $work . '/worker-state';
mkdir($workerState, 0700);
$workerEnv = [
    'PATH' => '/usr/bin:/bin', 'SMART_ASSESS_WORKER_SECRET' => $secret, 'SMART_ASSESS_WORKER_STATE_DIR' => $workerState,
    'SMART_ASSESS_WORKER_OLLAMA_URL' => 'http://127.0.0.1:' . $ollamaOkPort, 'SMART_ASSESS_WORKER_OLLAMA_MODEL' => 'fake-model:1',
    'SMART_ASSESS_WORKER_TESSERACT_PATH' => $tools . '/tesseract', 'SMART_ASSESS_WORKER_PDFINFO_PATH' => $tools . '/pdfinfo',
    'SMART_ASSESS_WORKER_PDFTOTEXT_PATH' => $tools . '/pdftotext', 'SMART_ASSESS_WORKER_PDFTOPPM_PATH' => $tools . '/pdftoppm',
];
$startWorker = static function (array $environment) use ($app, $work, $freePort): array {
    $port = $freePort();
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $app . '/worker/index.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $work . '/real-worker.log', 'a']], $pipes, $app . '/worker', $environment);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    return [$process, $port];
};
$stopWorker = static function ($process): void {
    proc_terminate($process, 9);
    proc_close($process);
};
$healthWith = static function (array $environment) use ($startWorker, $stopWorker, $signer): string {
    [$process, $port] = $startWorker($environment);
    $state = (new AiWorkerClient('http://127.0.0.1:' . $port, $signer))->health();
    $stopWorker($process);
    return $state;
};
$check('real worker: valid settings, tools, languages and model -> ready', $healthWith($workerEnv) === 'ready');
$closedOllama = $freePort();
$settingCases = [
    'a job limit above 1800 s (SMART_ASSESS_WORKER_MAX_JOB_SECONDS=2400)' => ['SMART_ASSESS_WORKER_MAX_JOB_SECONDS' => '2400'],
    'an OCR total timeout above 300 s' => ['SMART_ASSESS_WORKER_OCR_TOTAL_TIMEOUT' => '600'],
    'an OCR program timeout of 0' => ['SMART_ASSESS_WORKER_OCR_PROCESS_TIMEOUT' => '0'],
    'a malformed OCR language list (eng+)' => ['SMART_ASSESS_WORKER_OCR_LANGUAGES' => 'eng+'],
    'an OCR language that is not installed (eng+tgl)' => ['SMART_ASSESS_WORKER_OCR_LANGUAGES' => 'eng+tgl'],
    'a model input limit below 1000 characters' => ['SMART_ASSESS_WORKER_MAX_INPUT_CHARS' => '500'],
    'an Ollama timeout above 900 s' => ['SMART_ASSESS_WORKER_OLLAMA_TIMEOUT' => '1000'],
    'a timeout that is not a number' => ['SMART_ASSESS_WORKER_OLLAMA_TIMEOUT' => '3m'],
    'a missing Tesseract program' => ['SMART_ASSESS_WORKER_TESSERACT_PATH' => $tools . '/no-such-tesseract'],
    'a model Ollama does not have' => ['SMART_ASSESS_WORKER_OLLAMA_MODEL' => 'other-model:1'],
    'a shared secret shorter than 32 characters' => ['SMART_ASSESS_WORKER_SECRET' => 'too-short'],
    'a state folder that does not exist' => ['SMART_ASSESS_WORKER_STATE_DIR' => $work . '/no-such-state'],
];
$wrongSettings = [];
foreach ($settingCases as $label => $override) {
    $state = $healthWith($override + $workerEnv);
    if ($state !== 'not_configured') {
        $wrongSettings[$label] = $state;
    }
}
$check('real worker: every invalid setting makes health report not_configured (' . count($settingCases) . ' cases)', $wrongSettings === [], $wrongSettings);
$check('real worker: Ollama not answering is not_ready (temporary), not a setting', $healthWith(['SMART_ASSESS_WORKER_OLLAMA_URL' => 'http://127.0.0.1:' . $closedOllama] + $workerEnv) === 'not_ready');
[$process, $port] = $startWorker(['SMART_ASSESS_WORKER_MAX_JOB_SECONDS' => '2400'] + $workerEnv);
$misconfiguredJob = (static function () use ($port, $signer): string {
    try {
        (new AiWorkerClient('http://127.0.0.1:' . $port, $signer))->analyze(['version' => 1, 'job_id' => 1, 'declared' => [], 'documents' => []]);
        return 'no error';
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
})();
$stopWorker($process);
$check('real worker: a job sent to a misconfigured worker is worker_not_configured (configuration, no attempt used)', $misconfiguredJob === 'worker_not_configured'
    && AiVerification::retryClass($misconfiguredJob) === 'configuration', $misconfiguredJob);
$check('real worker: its log names the problem but never the secret', str_contains((string) file_get_contents($work . '/real-worker.log'), 'Not configured')
    && !str_contains((string) file_get_contents($work . '/real-worker.log'), $secret));

if (extension_loaded('gd')) {
    $image = imagecreatetruecolor(60, 40);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    file_put_contents($work . '/kill-job.json', json_encode(['version' => 1, 'job_id' => 77,
        'declared' => ['flow' => 'docreq', 'is_owner' => true, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'arp_number' => '', 'document_type' => '', 'transfer_type' => ''],
        'documents' => [['key' => 'ownerId', 'label' => 'Valid ID', 'mime' => 'image/png', 'content_base64' => base64_encode($png)]]]));
    file_put_contents($work . '/send-job.php', '<?php require ' . var_export($app . '/app/services/AiWorkerClient.php', true) . ';
$client = new AiWorkerClient($argv[1], new WorkerSignature((string) getenv("SA_TEST_SECRET")), 120);
try { $client->analyze(json_decode((string) file_get_contents($argv[2]), true)); echo "no error"; } catch (RuntimeException $e) { echo $e->getMessage(); }');
    touch($work . '/tesseract-hangs');
    [$process, $port] = $startWorker($workerEnv);
    $sender = proc_open([PHP_BINARY, $work . '/send-job.php', 'http://127.0.0.1:' . $port, $work . '/kill-job.json'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $senderPipes, $work, ['SA_TEST_SECRET' => $secret, 'PATH' => '/usr/bin:/bin']);
    $ocrFolders = [];
    for ($i = 0; $i < 100 && $ocrFolders === []; $i++) {
        usleep(100000);
        $ocrFolders = glob($workerState . '/job-*/smart-assess-ocr-*') ?: [];
    }
    $jobFolders = glob($workerState . '/job-*') ?: [];
    $check('real worker: OCR temporary files are created inside the job folder, not in the system temp folder', count($ocrFolders) === 1 && count($jobFolders) === 1, [$ocrFolders, $jobFolders]);
    $stopWorker($process);   // SIGKILL mid-job: PHP's "finally" never runs
    $senderResult = (string) stream_get_contents($senderPipes[1]);
    proc_close($sender);
    unlink($work . '/tesseract-hangs');
    $check('real worker killed mid-job: the dispatcher side sees worker_crashed', $senderResult === 'worker_crashed', $senderResult);
    $check('real worker killed mid-job: its job folder and the client document are left behind (what the cleanup is for)', $jobFolders !== []
        && is_file($jobFolders[0] . '/document-0.png'));
    $lockFree = ($lockHandle = fopen($workerState . '/worker.lock', 'c')) !== false && flock($lockHandle, LOCK_EX | LOCK_NB);
    if ($lockFree) {
        flock($lockHandle, LOCK_UN);
    }
    $check('real worker killed mid-job: the operating system released the worker lock', $lockFree);
    [$process, $port] = $startWorker($workerEnv);
    $workerClient = new AiWorkerClient('http://127.0.0.1:' . $port, $signer);
    $firstHealth = $workerClient->health();
    $check('restarted worker: a folder changed less than a minute ago is kept for now', $firstHealth === 'ready' && $jobFolders !== [] && is_dir($jobFolders[0]), $firstHealth);
    if ($jobFolders !== []) {
        touch($jobFolders[0], time() - 120);
    }
    $secondHealth = $workerClient->health();
    $stopWorker($process);
    $check('restarted worker: the next health check removes the abandoned folder with the document and OCR pages', $secondHealth === 'ready'
        && glob($workerState . '/job-*') === [], [$secondHealth, glob($workerState . '/job-*')]);
    $check('restarted worker: its log says how many folders it removed, without names', str_contains((string) file_get_contents($work . '/real-worker.log'), 'Removed 1 folder(s) left by interrupted jobs.')
        && !preg_match('/job-[a-f0-9]{32}/', (string) file_get_contents($work . '/real-worker.log')));
} else {
    $check('the kill test needs the GD extension to make a synthetic image', false);
}

// ================= Privacy Policy effective date: parsing (fail closed) =================
$parsed = [
    '2026-11-20T00:00:00+08:00' => gmmktime(16, 0, 0, 11, 19, 2026),
    '2026-11-19T16:00:00Z' => gmmktime(16, 0, 0, 11, 19, 2026),
    '2026-11-20T08:30:15-05:00' => gmmktime(13, 30, 15, 11, 20, 2026),
    ' 2026-11-20T00:00:00+08:00 ' => gmmktime(16, 0, 0, 11, 19, 2026),
];
$check('effective date: a full date, time and offset (or Z) is read exactly', array_map(static fn (string $v): ?int => AiVerification::privacyEffectiveFrom($v), array_keys($parsed)) === array_values($parsed));
$invalid = ['', '2026-11-20', '2026-11-20 00:00:00+08:00', '2026-11-20T00:00:00', '2026-11-20T00:00+08:00', '2026-02-30T00:00:00+08:00', '2026-13-01T00:00:00+08:00',
    '2026-11-20T24:00:00+08:00', '2026-11-20T00:60:00+08:00', '2026-11-20T00:00:00+15:00', '2026-11-20T00:00:00+0800', 'tomorrow', 'now', '<confirmed Privacy Policy effective date>'];
$accepted = array_values(array_filter($invalid, static fn (string $v): bool => AiVerification::privacyEffectiveFrom($v) !== null));
$check('effective date: anything incomplete, ambiguous or impossible is treated as unset (' . count($invalid) . ' cases)', $accepted === [], $accepted);
putenv(AiVerification::PRIVACY_EFFECTIVE_VARIABLE);
$check('effective date: unset in the environment means none, so nothing is eligible', AiVerification::privacyEffectiveFrom() === null);

// ================= Recovery confirmation code (--retire-mismatched) =================
$set = [['id' => 4, 'status' => 'pending', 'recorded' => 100, 'leased' => false], ['id' => 7, 'status' => 'processing', 'recorded' => null, 'leased' => false],
    ['id' => 9, 'status' => 'processing', 'recorded' => 50, 'leased' => true]];
$base0 = AiVerification::retirementCode($set, 1000, 1000);
$variants = [
    'another dispatcher date' => AiVerification::retirementCode($set, 1001, 1000),
    'another website date' => AiVerification::retirementCode($set, 1000, 1001),
    'a job more' => AiVerification::retirementCode([...$set, ['id' => 11, 'status' => 'pending', 'recorded' => 100, 'leased' => false]], 1000, 1000),
    'a job fewer' => AiVerification::retirementCode(array_slice($set, 1), 1000, 1000),
    'a status changed' => AiVerification::retirementCode([['status' => 'processing'] + $set[0], $set[1], $set[2]], 1000, 1000),
    'a recorded date changed' => AiVerification::retirementCode([['recorded' => 101] + $set[0], $set[1], $set[2]], 1000, 1000),
    'a job became leased' => AiVerification::retirementCode([['leased' => true] + $set[0], $set[1], $set[2]], 1000, 1000),
];
$check('recovery code: counts only the jobs to retire (leased ones excluded) and is stable', str_starts_with($base0, '2-') && strlen($base0) === 14
    && $base0 === AiVerification::retirementCode($set, 1000, 1000));
$check('recovery code: any change to the jobs, the dispatcher date or the website date gives a different code', array_filter($variants, static fn (string $c): bool => $c === $base0) === [], $variants);
$check('recovery code: a leased job going away does not change the code of the jobs to retire', AiVerification::retirementCode(array_slice($set, 0, 2), 1000, 1000) === $base0);
$check('a retired job is final (permanent class) and Staff get a plain reason', AiVerification::retryClass('effective_date_changed') === 'permanent'
    && str_contains(AiVerification::errorMessage('effective_date_changed'), 'stopped by an administrator'));

// ================= A stuck job holding the worker lock =================
$stuckState = $work . '/stuck-state';
mkdir($stuckState, 0700);
$stuckHttp = new AiWorkerHttp($signer, $fakeWorker(static fn () => []), $stuckState, 1_000_000, null, 60, 100);
$holder = fopen($stuckState . '/worker.lock', 'c');
flock($holder, LOCK_EX);
ftruncate($holder, 0);
fwrite($holder, (string) (time() - 30));
fflush($holder);
[$status, , $answer] = $stuckHttp->handle('GET', '/v1/health', [], '');
$check('worker lock held for 30 s of an allowed 100 s: a normal long job, health ready', $status === 200, $answer);
ftruncate($holder, 0);
fwrite($holder, (string) (time() - 500));
fflush($holder);
[$status, , $answer] = $stuckHttp->handle('GET', '/v1/health', [], '');
$check('worker lock held for 500 s of an allowed 100 s: health says stuck (the dispatcher reports it, exit 3)', $status === 503 && $answer === '{"ok":false,"error":"stuck"}', $answer);
flock($holder, LOCK_UN);
fclose($holder);
$seenLock = null;
$stampHttp = new AiWorkerHttp($signer, $fakeWorker(static function () use (&$seenLock, $stuckState): array {
    $seenLock = trim((string) file_get_contents($stuckState . '/worker.lock'));
    return ['ok' => true];
}), $stuckState);
$signed = $signer->signRequest('POST', AiWorkerHttp::PATH, $body);
$stampHttp->handle('POST', AiWorkerHttp::PATH, $signed, $body);
$check('a job records when it took the lock, so a stuck job can be told from a long one', ctype_digit((string) $seenLock) && abs(time() - (int) $seenLock) <= 5, $seenLock);

// ================= L-a: overlapping health checks never mistake an old job time for a stuck worker =================
$overlapState = $work . '/overlap-state';
mkdir($overlapState, 0700);
file_put_contents($overlapState . '/worker.lock', (string) (time() - 500));   // the previous job took the lock 500 s ago
$overlapHttp = new AiWorkerHttp($signer, $fakeWorker(static fn () => []), $overlapState, 1_000_000, null, 60, 100);
[$status] = $overlapHttp->handle('GET', '/v1/health', [], '');
$check('L-a: a health check that takes the lock records its own time', $status === 200 && abs(time() - (int) trim((string) file_get_contents($overlapState . '/worker.lock'))) <= 2);
file_put_contents($work . '/lock-holder.php', '<?php
[$script, $lockFile, $flag, $mode] = $argv;
$lock = fopen($lockFile, "c");
flock($lock, LOCK_EX);
touch($flag);                       // holding the lock, still with the previous holder\'s time
usleep(100000);
if ($mode === "stamp") { ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) time()); fflush($lock); usleep(1500000); }
flock($lock, LOCK_UN);              // mode "release": let go after 0.1 s without stamping
');
foreach (['stamp' => 'a new holder that records its time 0.1 s after taking the lock', 'release' => 'a holder that lets go 0.1 s later'] as $mode => $label) {
    file_put_contents($overlapState . '/worker.lock', (string) (time() - 500));
    @unlink($work . '/holder.flag');
    $holder = proc_open([PHP_BINARY, $work . '/lock-holder.php', $overlapState . '/worker.lock', $work . '/holder.flag', $mode],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $holderPipes);
    for ($i = 0; $i < 100 && !is_file($work . '/holder.flag'); $i++) {
        usleep(10000);
    }
    [$status, , $answer] = $overlapHttp->handle('GET', '/v1/health', [], '');
    proc_close($holder);
    $check("L-a: lock taken a moment ago, old time still in the file ({$label}): not reported as stuck", $status === 200, $answer);
}
file_put_contents($overlapState . '/worker.lock', (string) (time() - 500));
file_put_contents($work . '/health-loop.php', '<?php
require ' . var_export($app . '/app/services/AiWorkerHttp.php', true) . ';
$http = new AiWorkerHttp(new WorkerSignature(str_repeat("s", 40)), static fn () => null, $argv[1], 1000000, null, 60, 100);
$stuck = 0;
$until = microtime(true) + 1.5;
while (microtime(true) < $until) { if ($http->handle("GET", "/v1/health", [], "")[0] !== 200) { $stuck++; } }
echo $stuck;
');
$looper = proc_open([PHP_BINARY, $work . '/health-loop.php', $overlapState], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $loopPipes);
$parentStuck = 0;
$until = microtime(true) + 1.5;
while (microtime(true) < $until) {
    if ($overlapHttp->handle('GET', '/v1/health', [], '')[0] !== 200) {
        $parentStuck++;
    }
}
$childStuck = (int) stream_get_contents($loopPipes[1]);
proc_close($looper);
$check('L-a: two processes running health checks at the same time for 1.5 s (old job time in the file): never "stuck"', $parentStuck === 0 && $childStuck === 0, [$parentStuck, $childStuck]);
$holder = fopen($overlapState . '/worker.lock', 'c');
flock($holder, LOCK_EX);
ftruncate($holder, 0);
rewind($holder);
fwrite($holder, (string) (time() - 500));
fflush($holder);
[$status, , $answer] = $overlapHttp->handle('GET', '/v1/health', [], '');
flock($holder, LOCK_UN);
fclose($holder);
$check('L-a: a holder that really took the lock 500 s ago (limit 100 s) is still reported as stuck', $status === 503 && $answer === '{"ok":false,"error":"stuck"}', $answer);

// ================= The dispatcher starts no PHP session (L1) =================
$readOnlySessions = $work . '/read-only-sessions';
mkdir($readOnlySessions, 0500);
$cliOutput = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d session.save_path=' . escapeshellarg($readOnlySessions) . ' -d display_errors=stderr -d error_reporting=-1 -r '
    . escapeshellarg('require ' . var_export($app . '/web/bootstrap.php', true) . '; echo "session_status=", session_status();') . ' 2>&1');
$check('command line: loading web/bootstrap.php starts no session and logs no warning (read-only session folder)', trim($cliOutput) === 'session_status=' . PHP_SESSION_NONE, $cliOutput);
chmod($readOnlySessions, 0700);

// ================= Worker job: the time budget is respected =================
$jobWith = static fn (array $extra, int $documents = 4): array => AiDocumentWorker::validateJob(array_merge($job, [
    'documents' => array_map(static fn (int $i): array => ['key' => 'doc' . $i, 'label' => 'Document ' . $i, 'mime' => 'image/png', 'content_base64' => base64_encode('image ' . $i)], range(1, $documents)),
], $extra));
$check('validateJob: no budget is fine (older callers)', AiDocumentWorker::validateJob($job)['time_budget_seconds'] === null);
foreach (['29 s' => 29, '1801 s' => 1801, 'a string' => '120', 'a float' => 120.5] as $label => $value) {
    $check("validateJob: a budget of {$label} is refused", $rejects(static fn () => AiDocumentWorker::validateJob($job + ['time_budget_seconds' => $value])));
}
$check('validateJob: any other extra key is still refused', $rejects(static fn () => AiDocumentWorker::validateJob($job + ['deadline' => 120])));
$check('validateJob: the budget must come last, keys stay exact', $rejects(static fn () => AiDocumentWorker::validateJob(['time_budget_seconds' => 120] + $job)));

$now = 0.0;
$clock = static function () use (&$now): float {
    return $now;
};
$ocrGiven = [];
$ocrFactory = static function (string $directory, int $seconds) use (&$now, &$ocrGiven): object {
    $ocrGiven[] = $seconds;
    $now += 25;   // each document's OCR takes 25 s (counted when it starts; nothing else runs in between)
    return new class () {
        public function extract(string $path): array
        {
            return ['success' => true, 'text' => 'REPUBLIC OF THE PHILIPPINES DRIVER LICENSE DELA CRUZ, JUAN Address Poblacion Mabini Batangas', 'error_code' => null,
                'pages_processed' => 1, 'extraction_method' => 'image_ocr'];
        }
    };
};
$modelTimeouts = [];
$modelAnswer = null;
$chat = static function (string $system, string $user, array $schema, float $temperature, int $timeout) use (&$modelTimeouts, &$modelAnswer): mixed {
    $modelTimeouts[] = $timeout;
    return $modelAnswer instanceof Closure ? $modelAnswer($user) : $modelAnswer;
};
$goodAnswer = static function (string $prompt): array {
    preg_match_all('/<<<DOCUMENT key=([A-Za-z0-9_]+) /', $prompt, $keys);
    return ['documents' => array_map(static fn (string $key): array => ['key' => $key, 'expected_type_match' => 'yes', 'missing_fields' => [], 'note' => ''], $keys[1]),
        'findings' => [], 'manual_review_recommended' => false];
};
$worker = new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'fake-model:1', null, 'tesseract (eng)', 16_000, 900, 40, $clock);
$modelAnswer = $goodAnswer;
$result = $worker->run($jobWith(['time_budget_seconds' => 100]));
$validated = DocumentAnalysis::validateWorkerResult($result, ['doc1', 'doc2', 'doc3', 'doc4']);
$check('budget 100 s, model reserve 40 s: OCR gets 60, 35, 10 s for the first three documents', $ocrGiven === [60, 35, 10], $ocrGiven);
$check('the fourth document no longer fits: reported as not read (time_budget_exceeded), OCR not started', $validated['documents'][3]['ocr_status'] === 'failed'
    && $validated['documents'][3]['ocr_error'] === 'time_budget_exceeded' && $validated['documents'][3]['readability'] === 'unreadable' && count($ocrGiven) === 3, $validated['documents'][3]);
$check('the model is given only the time left (25 s)', $modelTimeouts === [25], $modelTimeouts);
$check('the job ends within its budget', $now <= 100, $now);
$classified = DocumentAnalysis::classify((new DocumentRequirements())->evaluate('docreq', true, ['ownerId' => ['mime' => 'image/png', 'size' => 10]]), $validated,
    ['doc1' => 'Document 1', 'doc2' => 'Document 2', 'doc3' => 'Document 3', 'doc4' => 'Document 4']);
$check('a document skipped for time makes the result Needs review, never Passed or Missing', $classified['outcome'] === DocumentAnalysis::OUTCOME_NEEDS_REVIEW
    && str_contains((string) json_encode($classified['findings']), 'time budget exceeded'), $classified['outcome']);
$check('job files are removed afterwards', glob($work . '/state/job-*') === []);

$now = 0.0;
$ocrGiven = [];
$modelTimeouts = [];
$modelAnswer = 'not valid';
$result = (new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'fake-model:1', null, 'tesseract (eng)', 16_000, 900, 40, $clock))->run($jobWith(['time_budget_seconds' => 100]));
$check('an invalid model answer is not retried when less than 30 s are left', $result['model']['status'] === 'invalid_output' && $modelTimeouts === [25], $modelTimeouts);
$now = 0.0;
$ocrGiven = [];
$modelTimeouts = [];
$result = (new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'fake-model:1', null, 'tesseract (eng)', 16_000, 900, 40, $clock))->run($jobWith(['time_budget_seconds' => 300], 1));
$check('with time left, an invalid answer gets its second try, each within the time left', $result['model']['status'] === 'invalid_output' && $modelTimeouts === [40, 40], $modelTimeouts);

$now = 0.0;
$modelAnswer = static fn () => throw new RuntimeException('ollama_timeout');
$error = '';
try {
    (new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'fake-model:1', null, 'tesseract (eng)', 16_000, 900, 40, $clock))->run($jobWith(['time_budget_seconds' => 300], 1));
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}
$check('Ollama timing out becomes model_timeout (counted), not model_unavailable', $error === 'model_timeout', $error);
$modelAnswer = static fn () => throw new RuntimeException('ollama_unavailable');
try {
    (new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'fake-model:1', null, 'tesseract (eng)', 16_000, 900, 40, $clock))->run($jobWith(['time_budget_seconds' => 300], 1));
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}
$check('Ollama unreachable stays model_unavailable (transient)', $error === 'model_unavailable', $error);
$capped = new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'm', null, 'tesseract', 16_000, 300, 40, $clock);
$check('the worker never takes more than its own maximum, whatever the job asks', $capped->budgetSeconds(['time_budget_seconds' => 1800]) === 300
    && $capped->budgetSeconds(['time_budget_seconds' => null]) === 300 && $capped->budgetSeconds(['time_budget_seconds' => 120]) === 120);
$check('worker time limits out of range are refused', $rejects(static fn () => new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'm', null, 't', 16_000, 10))
    && $rejects(static fn () => new AiDocumentWorker($work . '/state', $ocrFactory, $chat, 'm', null, 't', 16_000, 900, 2)));

// ================= Ollama: a per-call timeout can only shorten =================
file_put_contents($work . '/sleep.txt', '3');
file_put_contents($work . '/slow-ollama.php', '<?php header("Content-Type: application/json"); if (str_starts_with($_SERVER["REQUEST_URI"], "/api/tags")) { echo "{\"models\":[]}"; return; } sleep((int) file_get_contents(__DIR__ . "/sleep.txt")); echo "{}";');
$ollamaPort = $serve($work . '/slow-ollama.php');
$ollamaError = static function (Closure $call): string {
    try {
        $call();
        return 'no error';
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
};
$started = microtime(true);
$shortened = $ollamaError(static fn () => (new OllamaClient('http://127.0.0.1:' . $ollamaPort, 'fake-model:1', 30))->chatJson('s', 'u', [], 0.0, 1));
$elapsed = microtime(true) - $started;
$check('Ollama: a per-call timeout shortens the configured one', $shortened === 'ollama_timeout' && $elapsed < 2.5, [$shortened, round($elapsed, 2)]);
sleep(3);   // let the fake finish
file_put_contents($work . '/sleep.txt', '7');
$started = microtime(true);
$capped = $ollamaError(static fn () => (new OllamaClient('http://127.0.0.1:' . $ollamaPort, 'fake-model:1', 5))->chatJson('s', 'u', [], 0.0, 60));
$elapsed = microtime(true) - $started;
$check('Ollama: a per-call timeout can never lengthen the configured one (60 s asked, 5 s configured)', $capped === 'ollama_timeout' && $elapsed > 4.5 && $elapsed < 6.5, [$capped, round($elapsed, 2)]);

// ================= Deployment files =================
$unit = static function (string $file): array {
    $values = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Za-z]+)=(.*)$/', $line, $m)) {
            $values[$m[1]][] = $m[2];
        }
    }
    return $values;
};
$deploy = $app . '/deploy/systemd';
$service = $unit($deploy . '/smart-assess-ai-dispatch.service');
$timer = $unit($deploy . '/smart-assess-ai-dispatch.timer');
$serviceText = (string) file_get_contents($deploy . '/smart-assess-ai-dispatch.service');
$check('service: oneshot, runs as www-data:www-data', ($service['Type'][0] ?? '') === 'oneshot' && ($service['User'][0] ?? '') === 'www-data' && ($service['Group'][0] ?? '') === 'www-data');
$check('service: its own KEY=VALUE environment file, not apache.env', ($service['EnvironmentFile'] ?? []) === ['/etc/smart-assess/ai-dispatch.env']);
$check('service: runs the dispatcher of /var/www/smart-assess with --limit and --max-seconds', str_contains($service['ExecStart'][0] ?? '', '/var/www/smart-assess/bin/ai-verification-dispatch.php --limit=20 --max-seconds=1800'));
$maxSeconds = preg_match('/--max-seconds=(\d+)/', $service['ExecStart'][0] ?? '', $m) ? (int) $m[1] : 0;
$check('service: TimeoutStartSec covers the run plus one leased job', (int) ($service['TimeoutStartSec'][0] ?? 0) >= $maxSeconds + $default->leaseSeconds());
$check('service: resource limits (MemoryMax, CPUQuota, TasksMax, Nice)', isset($service['MemoryMax'], $service['CPUQuota'], $service['TasksMax'], $service['Nice']));
$check('service: hardened and read-only (NoNewPrivileges, ProtectSystem=strict, empty CapabilityBoundingSet)', ($service['NoNewPrivileges'][0] ?? '') === 'yes'
    && ($service['ProtectSystem'][0] ?? '') === 'strict' && ($service['CapabilityBoundingSet'][0] ?? 'x') === '' && ($service['ReadOnlyPaths'][0] ?? '') === '/var/www/smart-assess');
$check('service: no [Install] section, so only the timer can be enabled', !str_contains($serviceText, '[Install]'));
$check('timer: one minute after the previous run ends (never overlapping), and after boot', ($timer['OnUnitInactiveSec'][0] ?? '') === '1min'
    && isset($timer['OnBootSec']) && ($timer['Unit'][0] ?? '') === 'smart-assess-ai-dispatch.service' && ($timer['WantedBy'][0] ?? '') === 'timers.target');
$envLines = array_values(array_filter(array_map('trim', file($deploy . '/ai-dispatch.env.example') ?: []), static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
$envKeys = array_map(static fn (string $l): string => explode('=', $l, 2)[0], $envLines);
$check('env example: only KEY=VALUE lines, no spaces around "="', $envLines !== [] && array_filter($envLines, static fn (string $l): bool => !preg_match('/^[A-Z][A-Z0-9_]*=\S/', $l)) === [], $envLines);
$check('env example: every required setting is listed', array_diff(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'SMART_ASSESS_DB_PASS', 'SMART_ASSESS_AI_WORKER_URL',
    'SMART_ASSESS_AI_WORKER_SECRET', 'SMART_ASSESS_AI_WORKER_TIMEOUT', 'SMART_ASSESS_AI_WORKER_CA_FILE', 'SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT'], $envKeys) === [], $envKeys);
$envValues = array_combine($envKeys, array_map(static fn (string $l): string => explode('=', $l, 2)[1], $envLines));
$check('env example: the Sydney database name, and placeholders instead of secrets', $envValues['DB_NAME'] === 'smart_assess_sydney'
    && str_starts_with($envValues['SMART_ASSESS_DB_PASS'], '<') && str_starts_with($envValues['SMART_ASSESS_AI_WORKER_SECRET'], '<'), $envValues['DB_NAME']);
$check('env example: DB_HOST is copied from the website, not assumed (localhost and 127.0.0.1 match different accounts)', str_starts_with($envValues['DB_HOST'], '<'));
$check('env example: the effective date is a placeholder, never an invented date, and as such is refused', str_starts_with($envValues['SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT'], '<')
    && AiVerification::privacyEffectiveFrom($envValues['SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT']) === null);
$check('env example: the timeout is the default whose limits are documented', (int) $envValues['SMART_ASSESS_AI_WORKER_TIMEOUT'] === AiWorkerClient::DEFAULT_TIMEOUT_SECONDS);
$pool = (string) file_get_contents($deploy . '/worker-php-fpm-pool.conf.example');
$terminate = preg_match('/^request_terminate_timeout = (\d+)s$/m', $pool, $m) ? (int) $m[1] : 0;
$poolMaxJob = preg_match('/^env\[SMART_ASSESS_WORKER_MAX_JOB_SECONDS\] = (\d+)$/m', $pool, $m) ? (int) $m[1] : 0;
$check('worker pool example: php-fpm allows longer than the longest job; two children so health answers during a job', $terminate > $poolMaxJob && $poolMaxJob === 900
    && str_contains($pool, 'pm.max_children = 2') && str_contains($pool, 'post_max_size] = 80M'), [$terminate, $poolMaxJob]);
$check('worker pool example: no real secret', str_contains($pool, 'env[SMART_ASSESS_WORKER_SECRET] = <'));

// ================= Privacy Policy =================
// Rendered as the website would, with a given AI effective-date setting (null: unset).
// The values here are test fixtures only, never the real date.
$renderPolicy = static function (?string $effectiveAt) use ($app): string {
    $environment = $effectiveAt === null ? 'env -u SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT ' : 'env SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT=' . escapeshellarg($effectiveAt) . ' ';
    return (string) shell_exec('cd ' . escapeshellarg($app . '/web') . ' && ' . $environment . escapeshellarg(PHP_BINARY) . ' privacy-policy.php 2>&1');
};
$policyText = static fn (string $html): string => html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
$rendered = $renderPolicy('2031-05-06T07:08:09+08:00');
$text = $policyText($rendered);
$check('privacy (M-A): the AI section shows the configured effective date and time, labelled as such', str_contains($text,
    'Effective date of the automatic document check: May 6, 2031, 7:08:09 AM Philippine time (UTC+08:00).'), substr($text, strpos($text, 'Automatic document check') ?: 0, 600));
$check('privacy (M-A): the date comes from the setting (another value, given in UTC, is shown in Philippine time)', str_contains($policyText($renderPolicy('2032-01-01T00:00:00Z')),
    'Effective date of the automatic document check: January 1, 2032, 8:00:00 AM Philippine time (UTC+08:00).'));
$check('privacy (M-A): it is kept separate from the "Last updated" date, which does not control AI eligibility', str_contains($text, 'This date is separate from the "Last updated" date')
    && str_contains($text, 'Last updated: ') && !str_contains($text, 'shown at the top of this page'));
$unsetTexts = [$policyText($renderPolicy(null)), $policyText($renderPolicy('2026-11-20')), $policyText($renderPolicy('not a date'))];
$check('privacy (M-A): unset or invalid setting -> "not yet enabled", effective date not established, no date shown', array_filter($unsetTexts, static fn (string $t): bool =>
    !str_contains($t, 'The automatic document check is not yet enabled: its effective date has not been established, so no request is checked by AI.')
    || str_contains($t, 'Effective date of the automatic document check') || str_contains($t, 'Philippine time (UTC+08:00)')
    || str_contains($t, 'SMART ASSESS checks the uploaded documents automatically')) === []);
$policySource = (string) file_get_contents($app . '/web/privacy-policy.php');
$aiSection = substr($policySource, (int) strpos($policySource, 'id="document-check"'), 2500);
$check('privacy (M-A): no date is hard-coded in the AI section', !preg_match('/\b20\d\d\b/', $aiSection) && !preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December) \d/', $aiSection));
$check('privacy (M-A): the rest of the policy is the same in both states', str_replace(['not yet enabled'], '', $policyText($renderPolicy(null))) !== ''
    && substr_count($text, 'What is sent for the check') === 1 && substr_count($unsetTexts[0], 'What is sent for the check') === 1
    && strlen($unsetTexts[0]) > 0.9 * strlen($text));
$check('privacy: the page renders', str_contains($text, 'Privacy Policy') && str_contains($text, 'Contact and privacy concerns'), substr($text, 0, 200));
$check('privacy: the outdated "does not read uploaded documents automatically" is gone', !str_contains($text, 'does not read uploaded documents automatically'));
$check('privacy: Singapore is no longer named; Sydney is', !str_contains($text, 'Singapore') && str_contains($text, 'Asia Pacific (Sydney)'));
$check('privacy: the automatic check, OCR, what is sent and the separate server are described', str_contains($text, 'Automatic document check')
    && str_contains($text, 'optical character recognition (OCR)') && str_contains($text, 'What is sent for the check') && str_contains($text, 'separate server'));
$check('privacy: advisory only; Staff decide; results are Staff-only', str_contains($text, 'does not approve or reject requests') && str_contains($text, 'Staff open your original documents and make every decision')
    && str_contains($text, 'Staff only'));
$check('privacy: the website\'s requirement rules are described separately from the AI model', str_contains($text, "the website's own fixed rules check which documents")
    && str_contains($text, 'no AI is used for this step') && !str_contains($text, 'compares that text with the details you entered and with the documents the service requires'));
$check('privacy: embedded PDF text is read without OCR, and not every file goes through OCR', str_contains($text, 'text already stored inside a PDF is read directly')
    && str_contains($text, 'Not every file goes through OCR'));
$check('privacy: the actual limits are stated (10 documents, first 10 pages, 25 megapixels) and match the code', str_contains($text, 'up to 10 documents per request')
    && str_contains($text, 'the first 10 pages of each PDF') && str_contains($text, '25 megapixels') && AiDocumentWorker::MAX_DOCUMENTS === 10
    && OcrService::MAX_IMAGE_PIXELS === 25_000_000 && str_contains((string) file_get_contents($app . '/app/services/OcrService.php'), 'MAX_PDF_PAGES = 10;'));
$check('privacy: no unconditional promise of immediate deletion; interrupted checks and the cleanup are explained', !str_contains($text, 'deletes each file as soon as the check is finished')
    && !str_contains($text, 'keeps the files only while it checks them and then deletes them') && str_contains($text, 'If a check is interrupted')
    && str_contains($text, 'stops taking checks until an administrator removes them'));
$check('privacy: only requests submitted on or after the policy\'s effective date are checked by AI', str_contains($text, 'requests submitted earlier are not checked by AI'));
$check('privacy: review markers stay in PHP comments and never reach visitors', !str_contains($rendered, 'CONFIRM') && !str_contains($rendered, 'BEFORE PUBLICATION')
    && substr_count((string) file_get_contents($app . '/web/privacy-policy.php'), 'CONFIRM') >= 3);
$check('privacy: no new retention period was invented', !preg_match('/\b(\d+)\s+(days|months|years)\b[^.]*(document check|AI)/i', $text));
$check('Terms version is 2026-11-13 on the server and in the client form', str_contains((string) file_get_contents($app . '/web/terms-acceptance.php'), "SA_TERMS_VERSION = '2026-11-13'")
    && str_contains((string) file_get_contents(dirname($app) . '/smart-assess.html'), "TERMS_VERSION = '2026-11-13'"));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
