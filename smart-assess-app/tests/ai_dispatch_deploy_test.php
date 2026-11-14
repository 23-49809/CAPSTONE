<?php
declare(strict_types=1);

/*
 * The AI dispatcher's deployment files, checked without a database or a server:
 *   deploy/systemd/smart-assess-ai-dispatch.service  - its own account, loopback-only network, hidden secrets
 *   deploy/systemd/ai-dispatch.env.example            - the local worker (http://127.0.0.1:8095, no CA file)
 *   deploy/mysql/ai-dispatch-db-user.sql.example      - least-privilege grants (exercised for real in
 *                                                       tests/ai_verification_workflow_test.php)
 * and that the worker client accepts exactly that worker address. When systemd-analyze is
 * available (Linux), the unit is also verified with it. Nothing is installed or changed.
 *
 *   php tests/ai_dispatch_deploy_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$app = dirname(__DIR__);
require_once $app . '/app/services/AiWorkerClient.php';
require_once $app . '/app/services/WorkerSignature.php';

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};

// ---- The unit: [Service] settings (repeated keys collected) ----
$unitText = (string) file_get_contents($app . '/deploy/systemd/smart-assess-ai-dispatch.service');
$service = [];
$section = '';
foreach (preg_split('/\R/', $unitText) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (preg_match('/^\[(.+)\]$/', $line, $m)) {
        $section = $m[1];
        continue;
    }
    if ($section === 'Service' && str_contains($line, '=')) {
        [$key, $value] = explode('=', $line, 2);
        $service[$key][] = $value;
    }
}
$one = static fn (string $key): ?string => isset($service[$key]) && count($service[$key]) === 1 ? $service[$key][0] : null;
$words = static fn (string $key): array => preg_split('/\s+/', trim(implode(' ', $service[$key] ?? [])), -1, PREG_SPLIT_NO_EMPTY);

$check('the dispatcher runs as its own account sa-ai-dispatch, not www-data or root', $one('User') === 'sa-ai-dispatch' && $one('Group') === 'sa-ai-dispatch');
$check('its only extra group is www-data (read-only access to the code and request uploads)', $words('SupplementaryGroups') === ['www-data']);
$check('the unit says how to create the account as a non-login system account', (bool) preg_match('/useradd --system .*--shell \/usr\/sbin\/nologin sa-ai-dispatch/', $unitText));
$check('network: everything denied except loopback (IPAddressDeny=any, IPAddressAllow=localhost)', $one('IPAddressDeny') === 'any' && $one('IPAddressAllow') === 'localhost');
$check('no raw or netlink sockets: only AF_UNIX, AF_INET, AF_INET6', $words('RestrictAddressFamilies') === ['AF_UNIX', 'AF_INET', 'AF_INET6']);
$check('no privileges or capabilities can be gained', $one('NoNewPrivileges') === 'yes' && $one('CapabilityBoundingSet') === '' && $one('AmbientCapabilities') === '');
$check('the whole file system is read-only for it, home folders hidden, nothing writable', $one('ProtectSystem') === 'strict' && $one('ProtectHome') === 'yes'
    && !isset($service['ReadWritePaths']) && $one('ReadOnlyPaths') === '/var/www/smart-assess');
$check('other processes are invisible to it', $one('ProtectProc') === 'invisible' && $one('ProcSubset') === 'pid');
$hidden = array_map(static fn (string $path): string => ltrim($path, '-'), $words('InaccessiblePaths'));
foreach ([
    '/etc/smart-assess/apache.env' => 'the website settings (Gmail and IPROG credentials)',
    '/etc/smart-assess/gmail' => 'the Gmail token folder',
    '/var/www/smart-assess/private_uploads/drafts' => 'draft uploads',
    '/var/backups' => 'database backups',
    '/etc/smart-assess-ai-worker' => 'the worker\'s secret file',
    '/var/lib/smart-assess-ai-worker' => 'the worker\'s state',
    '/opt/actions-runner' => 'the GitHub runner',
    '/etc/mysql/debian.cnf' => 'MySQL maintenance credentials',
] as $path => $what) {
    $check('hidden from the dispatcher: ' . $what . ' (' . $path . ')', in_array($path, $hidden, true));
}
$check('the request uploads it needs are not hidden', !preg_grep('#^/var/www/smart-assess(/private_uploads(/requests)?)?$#', $hidden));
$check('its settings file is read by systemd as root before the sandbox starts', $one('EnvironmentFile') === '/etc/smart-assess/ai-dispatch.env');

// ---- The settings example ----
$env = [];
foreach (preg_split('/\R/', (string) file_get_contents($app . '/deploy/systemd/ai-dispatch.env.example')) as $line) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m)) {
        $env[$m[1]] = $m[2];
    }
}
$check('the example points at the local worker installed in Steps 4-5', ($env['SMART_ASSESS_AI_WORKER_URL'] ?? '') === 'http://127.0.0.1:8095');
$check('the example sets no CA file (not needed for the loopback worker)', !array_key_exists('SMART_ASSESS_AI_WORKER_CA_FILE', $env));
$check('the example uses the dispatcher\'s own database account over the local socket', ($env['DB_USER'] ?? '') === 'smart_assess_ai_dispatch'
    && ($env['DB_HOST'] ?? '') === 'localhost' && ($env['DB_NAME'] ?? '') === 'smart_assess_sydney');
$check('the example holds no real secret (placeholders only)', str_starts_with($env['SMART_ASSESS_AI_WORKER_SECRET'] ?? '', '<') && str_starts_with($env['SMART_ASSESS_DB_PASS'] ?? '', '<'));
$workerHost = (string) parse_url($env['SMART_ASSESS_AI_WORKER_URL'] ?? '', PHP_URL_HOST);
$check('the worker address is inside the unit\'s IPAddressAllow=localhost (127.0.0.0/8)', str_starts_with($workerHost, '127.'), $workerHost);

// ---- The worker client accepts exactly that address ----
$signer = new WorkerSignature(str_repeat('s', 40));
$accepts = static function (callable $make): bool {
    try {
        $make();
        return true;
    } catch (Throwable) {
        return false;
    }
};
$check('the worker client accepts http://127.0.0.1:8095 without a CA file', $accepts(static fn () => new AiWorkerClient('http://127.0.0.1:8095', $signer)));
$check('the worker client still refuses plain HTTP to any non-loopback address', !$accepts(static fn () => new AiWorkerClient('http://10.0.0.5:8095', $signer))
    && !$accepts(static fn () => new AiWorkerClient('http://smartassess-mabini.com:8095', $signer)));

// ---- The grant file ----
$sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($app . '/deploy/mysql/ai-dispatch-db-user.sql.example'));
$grants = array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => str_starts_with($s, 'GRANT')));
$check('the grant file creates the account for local socket connections only', (bool) preg_match("/CREATE USER IF NOT EXISTS 'smart_assess_ai_dispatch'@'localhost'/", $sql));
$check('exactly three grants: two column-limited reads and the AI job table', count($grants) === 3, $grants);
$check('no broad privilege (ALL, GRANT OPTION, FILE, SUPER, *.*)', !preg_match('/\bALL\b|GRANT OPTION|\bFILE\b|\bSUPER\b|\*\.\*/i', $sql));
$requestGrant = (string) (preg_grep('/ON smart_assess_sydney\.requests TO/', $grants)[array_key_first(preg_grep('/ON smart_assess_sydney\.requests TO/', $grants) ?: [0])] ?? '');
$check('requests: only the columns the check needs, no contact details or address', $requestGrant !== ''
    && !preg_match('/contact_number|email|address_line|province|city|zip_code|client_id|middle_name|property_address/', $requestGrant), $requestGrant);
$check('ai_verification_jobs: read and update only (it never inserts or deletes)', (bool) preg_grep('/^GRANT SELECT, UPDATE ON smart_assess_sydney\.ai_verification_jobs TO/', $grants));
$check('no grant on any other table', preg_grep('/ON smart_assess_sydney\.(?!requests |request_documents |ai_verification_jobs )/', $grants) === []);

// ---- systemd's own check, where available ----
$analyze = trim((string) shell_exec('command -v systemd-analyze 2>/dev/null'));
if ($analyze === '') {
    echo "SKIP  systemd-analyze verify: not available on this machine (run it on the server before installing; see the unit's header)\n";
} else {
    exec(escapeshellarg($analyze) . ' verify ' . escapeshellarg($app . '/deploy/systemd/smart-assess-ai-dispatch.service') . ' 2>&1', $lines, $code);
    $check('systemd-analyze verify accepts the unit', $code === 0, implode("\n", $lines));
}

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
