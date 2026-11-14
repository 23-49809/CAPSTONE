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
$check('its only extra group is its own read group sa-ai-read, never www-data (Step 10, R2)', $words('SupplementaryGroups') === ['sa-ai-read']);
$check('the unit says how to create the account (non-login) and the read group', (bool) preg_match('/useradd --system .*--shell \/usr\/sbin\/nologin sa-ai-dispatch/', $unitText)
    && str_contains($unitText, 'groupadd --system sa-ai-read') && str_contains($unitText, 'usermod -a -G sa-ai-read sa-ai-dispatch'));
$check('network, second layer: everything denied except loopback (IPAddressDeny=any, IPAddressAllow=localhost)', $one('IPAddressDeny') === 'any' && $one('IPAddressAllow') === 'localhost');
$check('network, first layer: the egress filter unit is required and started first (Step 10, R1)', in_array('smart-assess-ai-dispatch-egress.service', preg_split('/\s+/', (string) (preg_match('/^Requires=(.*)$/m', $unitText, $m) ? $m[1] : '')), true)
    && (bool) preg_match('/^After=.*smart-assess-ai-dispatch-egress\.service/m', $unitText));
$check('Step 13: only Unix sockets (no IP, IPv6, raw or netlink sockets at all)', $words('RestrictAddressFamilies') === ['AF_UNIX']);
$check('Step 13: no network at all (PrivateNetwork=yes): a flushed or broken host firewall cannot open anything to it', $one('PrivateNetwork') === 'yes');
$check('Step 13: it reaches the worker only through the link socket, set in the unit itself',
    $one('Environment') === 'SMART_ASSESS_AI_WORKER_SOCKET=/run/smart-assess-ai-link/worker.sock'
    && (bool) preg_match('/^Requires=.*\bsmart-assess-ai-worker-link\.socket\b/m', $unitText) && (bool) preg_match('/^After=.*\bsmart-assess-ai-worker-link\.socket\b/m', $unitText));
$check('no privileges or capabilities can be gained', $one('NoNewPrivileges') === 'yes' && $one('CapabilityBoundingSet') === '' && $one('AmbientCapabilities') === '');
$check('the whole file system is read-only for it, home folders hidden, nothing writable', $one('ProtectSystem') === 'strict' && $one('ProtectHome') === 'yes'
    && !isset($service['ReadWritePaths']) && !isset($service['ReadOnlyPaths']));
$check('only its code and the request uploads exist in its view of /var/www (Step 10, R2)', $one('TemporaryFileSystem') === '/var/www:ro'
    && $words('BindReadOnlyPaths') === ['/var/www/smart-assess/bin/ai-verification-dispatch.php', '/var/www/smart-assess/bin/ai-worker-compat-check.php', '/var/www/smart-assess/web/bootstrap.php',
        '/var/www/smart-assess/app/services', '/var/www/smart-assess/private_uploads/requests'] && !isset($service['BindPaths']), $words('BindReadOnlyPaths'));
$check('the permissions unit is required and runs before every dispatcher run (outside its read-only sandbox)', !str_contains(implode(' ', $service['ExecStartPre'] ?? []), 'permissions')
    && (bool) preg_match('/^Requires=.*smart-assess-ai-dispatch-permissions\.service/m', $unitText) && (bool) preg_match('/^After=.*smart-assess-ai-dispatch-permissions\.service/m', $unitText));
$permUnit = (string) file_get_contents($app . '/deploy/systemd/smart-assess-ai-dispatch-permissions.service');
$check('the permissions unit is a oneshot WITHOUT RemainAfterExit (so it runs again on every start), offline, writing only the application folder',
    str_contains($permUnit, 'Type=oneshot') && !preg_match('/^RemainAfterExit=/m', $permUnit) && str_contains($permUnit, 'ExecStart=/usr/local/sbin/smart-assess-ai-dispatch-permissions.sh')
    && str_contains($permUnit, 'PrivateNetwork=yes') && str_contains($permUnit, 'ReadWritePaths=/var/www/smart-assess') && str_contains($permUnit, 'ProtectSystem=strict'));
$check('other processes are invisible to it, and system calls are filtered', $one('ProtectProc') === 'invisible' && $one('ProcSubset') === 'pid'
    && $one('SystemCallFilter') === '@system-service');
$hidden = array_map(static fn (string $path): string => ltrim($path, '-'), $words('InaccessiblePaths'));
foreach ([
    '/etc/smart-assess' => 'ALL of /etc/smart-assess: website settings, the website\'s database login (database.env), Gmail (Step 10, D1)',
    '/run/systemd/resolve' => 'the resolver\'s local sockets (no DNS through NSS, Step 10, R1)',
    '/run/dbus' => 'D-Bus (no DNS through resolved\'s bus API)',
    '/var/backups' => 'database backups',
    '/etc/smart-assess-ai-worker' => 'the worker\'s secret file',
    '/var/lib/smart-assess-ai-worker' => 'the worker\'s state',
    '/opt/actions-runner' => 'the GitHub runner',
    '/etc/mysql/debian.cnf' => 'MySQL maintenance credentials',
    '/var/lib/mysql' => 'MySQL data files',
] as $path => $what) {
    $check('hidden from the dispatcher: ' . $what . ' (' . $path . ')', in_array($path, $hidden, true));
}
$check('no single file of /etc/smart-assess is listed instead of the whole folder', preg_grep('#^/etc/smart-assess/#', $hidden) === [], $hidden);
$check('the request uploads it needs are not hidden', !preg_grep('#^/var/www/smart-assess(/private_uploads(/requests)?)?$#', $hidden));
$check('its settings file is read by systemd as root before the sandbox starts', $one('EnvironmentFile') === '/etc/smart-assess/ai-dispatch.env');

// ---- The egress filter (Step 10, R1) ----
$nft = (string) file_get_contents($app . '/deploy/nftables/ai-dispatch-egress.nft');
$rules = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) preg_replace('/#.*$/m', '', $nft))), static fn (string $l): bool => $l !== ''));
$check('egress: only packets of the sa-ai-dispatch account are filtered; everything else keeps the default', in_array('meta skuid "sa-ai-dispatch" jump dispatch', $rules, true)
    && in_array('type filter hook output priority filter; policy accept;', $rules, true));
$dispatchChain = (string) (preg_match('/chain dispatch \{(.*?)\}/s', $nft, $m) ? $m[1] : '');
$accepts = array_values(preg_grep('/\baccept\b/', array_map('trim', explode("\n", $dispatchChain))));
$check('egress: exactly one thing is allowed, TCP to 127.0.0.1:8095 (the worker)', $accepts === ['oifname "lo" ip daddr 127.0.0.1 tcp dport 8095 counter name allowed_worker accept'], $accepts);
$check('egress: everything else is rejected (TCP reset, then any other protocol)', str_contains($dispatchChain, 'meta l4proto tcp counter name rejected reject with tcp reset')
    && (bool) preg_match('/counter name rejected reject with icmpx admin-prohibited\s*$/', trim($dispatchChain)));
$egressUnit = (string) file_get_contents($app . '/deploy/systemd/smart-assess-ai-dispatch-egress.service');
$check('egress unit (Step 12, F2): reloads and verifies the table on every dispatcher run (no RemainAfterExit), enabled at boot',
    str_contains($egressUnit, 'ExecStart=/usr/sbin/nft -f /usr/local/share/smart-assess/ai-dispatch-egress.nft')
    && str_contains($egressUnit, 'ExecStartPost=/usr/sbin/nft list table inet sa_ai_dispatch_egress') && !preg_match('/^RemainAfterExit=/m', $egressUnit)
    && str_contains($egressUnit, 'WantedBy=multi-user.target'));
$check('the egress rules replace their own table in one transaction (so a reload never opens a gap)', (bool) preg_match('/table inet sa_ai_dispatch_egress\s+delete table inet sa_ai_dispatch_egress\s+table inet sa_ai_dispatch_egress \{/', (string) preg_replace('/#.*$/m', '', $nft)));

// ---- The egress guard: a flushed firewall fails closed, for the worker and the dispatcher (Step 12, F2) ----
$guard = (string) file_get_contents($app . '/deploy/sbin/smart-assess-ai-egress-guard.sh');
$guardCode = (string) preg_replace('/^\s*#.*$/m', '', $guard);
$check('guard: covers the live worker filter and the dispatcher filter, each with the services it protects',
    str_contains($guardCode, 'guard sa_ai_worker_egress /etc/smart-assess-ai-worker-runtime/worker-egress.nft') && str_contains($guardCode, 'smart-assess-ai-worker.service smart-assess-ai-worker-fpm.service')
    && str_contains($guardCode, 'guard sa_ai_dispatch_egress /usr/local/share/smart-assess/ai-dispatch-egress.nft') && str_contains($guardCode, 'smart-assess-ai-dispatch.timer smart-assess-ai-dispatch.service'));
$check('guard: reloads, verifies, and stops the protected services when a filter cannot be restored', str_contains($guardCode, 'nft -f "$file"')
    && str_contains($guardCode, 'nft list table inet "$table"') && str_contains($guardCode, 'systemctl stop "$@"') && str_contains($guardCode, 'exit "$status"'));
$guardUnit = (string) file_get_contents($app . '/deploy/systemd/smart-assess-ai-egress-guard.service');
$guardTimer = (string) file_get_contents($app . '/deploy/systemd/smart-assess-ai-egress-guard.timer');
$check('guard unit: a root oneshot limited to CAP_NET_ADMIN; its timer runs every 30 s and soon after boot', str_contains($guardUnit, 'CapabilityBoundingSet=CAP_NET_ADMIN')
    && str_contains($guardUnit, 'Type=oneshot') && str_contains($guardTimer, 'OnUnitActiveSec=30s') && str_contains($guardTimer, 'OnBootSec=20s') && str_contains($guardTimer, 'WantedBy=timers.target'));
$dropIn = (string) file_get_contents($app . '/deploy/systemd/nftables.service.d/50-smart-assess-ai-egress.conf');
$check('nftables.service drop-in: the guard runs straight after its start, reload and stop (its config flushes everything)',
    str_contains($dropIn, 'ExecStartPost=-/usr/local/sbin/smart-assess-ai-egress-guard.sh') && str_contains($dropIn, 'ExecReload=-/usr/local/sbin/smart-assess-ai-egress-guard.sh')
    && str_contains($dropIn, 'ExecStopPost=-/usr/local/sbin/smart-assess-ai-egress-guard.sh'));
$check('nftables.service drop-in: a guard failure never fails the host firewall unit ("-" on every line)',
    preg_match_all('/^Exec\w+=(?!-)/m', $dropIn) === 0 && preg_match_all('/^Exec\w+=-/m', $dropIn) === 3);

// ---- Release order, enforced (Step 12, F3) ----
$check('dispatcher: the compatibility check runs before every run (installed worker vs deployed application)',
    $one('ExecStartPre') === '/usr/bin/php -n /var/www/smart-assess/bin/ai-worker-compat-check.php --app=/var/www/smart-assess --worker=/opt/smart-assess-ai-worker');
$refresh = (string) file_get_contents($app . '/deploy/sbin/smart-assess-ai-worker-refresh.sh');
$refreshCode = (string) preg_replace('/^\s*#.*$/m', '', $refresh);
$check('worker refresh: stages a copy, runs the compatibility check and REFUSES (exit 1, nothing changed) unless it passes',
    str_contains($refreshCode, 'ai-worker-compat-check.php" --app="$APP" --worker="$STAGE"') && (bool) preg_match('/if ! .*ai-worker-compat-check\.php.*\n\s*rm -rf -- "\$STAGE"\n.*REFUSED.*\n\s*exit 1/', $refreshCode)
    && strpos($refreshCode, 'ai-worker-compat-check.php') < strpos($refreshCode, 'mv -- "$STAGE" "$LIVE"'));
$check('worker refresh: health-checks the new copy and rolls back to the previous one when unhealthy', str_contains($refreshCode, 'v1/health')
    && str_contains($refreshCode, 'mv -- "$PREV" "$LIVE"') && str_contains($refreshCode, '--check-only'));

// ---- The permissions script (Steps 10 and 12: R2, F4, F5) ----
$perm = (string) file_get_contents($app . '/deploy/sbin/smart-assess-ai-dispatch-permissions.sh');
$permCode = (string) preg_replace('/^\s*#.*$/m', '', $perm);
$check('permissions: fails closed (set -euo pipefail; stops without setfacl, the group or a safe path)', str_contains($permCode, 'set -euo pipefail')
    && str_contains($permCode, 'command -v setfacl') && str_contains($permCode, 'getent group "$GROUP"') && str_contains($permCode, '[[ -e "$part" && ! -L "$part" ]]'));
$check('permissions (F5): every component of every path is checked (no symbolic link, safe owner, not world-writable), before AND after',
    str_contains($permCode, 'IFS=/ read -ra parts') && str_contains($permCode, 'root|ubuntu|www-data') && str_contains($permCode, '& 02')
    && substr_count($permCode, "\ncheck_all\n") === 2);
$check('permissions (F5): files are granted only by a physical walk that never follows symbolic links (-R -P), and paths end option parsing (--)',
    !preg_match('/setfacl -R (?!-P)/', $permCode) && preg_match_all('/setfacl -R -P /', $permCode) === 2 && preg_match_all('/^\s*setfacl -[^\n]* -- /m', $permCode) === preg_match_all('/^\s*setfacl -/m', $permCode) && preg_match_all('/^\s*setfacl -/m', $permCode) === 5);
$check('permissions: grants only to group sa-ai-read and never changes owners, groups or modes', str_contains($permCode, 'GROUP=sa-ai-read')
    && !preg_match('/\b(chown|chgrp|chmod|usermod|useradd)\b/', $permCode) && !preg_match('/setfacl[^\n]*www-data/', $permCode));
preg_match_all('/setfacl[^\n]*/', $permCode, $setfacl);
$granted = implode("\n", $setfacl[0]);
$check('permissions: read only, never write (no "w" in any entry)', !preg_match('/g:\$GROUP:[rxX]*w/', $granted), $granted);
$check('permissions: recursion only inside app/services and private_uploads/requests', !preg_match('/setfacl -R[^\n]*"\$APP"\s*$/m', $permCode)
    && preg_match_all('/setfacl -R -P -m "g:\$GROUP:rX" -- "\$APP\/(app\/services|private_uploads\/requests)"/', $permCode) === 2);
$permUnitText = (string) file_get_contents($app . '/deploy/systemd/smart-assess-ai-dispatch-permissions.service');
$check('permissions unit (F4): no CAP_DAC_OVERRIDE, only CAP_FOWNER and CAP_DAC_READ_SEARCH', str_contains($permUnitText, 'CapabilityBoundingSet=CAP_FOWNER CAP_DAC_READ_SEARCH')
    && !str_contains($permUnitText, 'CAP_DAC_OVERRIDE'));
$check('permissions unit (F5): drafts, logs and every folder it never changes are mounted read only', (bool) preg_match('/^ReadOnlyPaths=.*private_uploads\/drafts.*\/logs.*\/vendor/m', $permUnitText));
foreach (['drafts', 'logs', 'vendor', '/etc/', 'gmail', 'database.env', 'apache.env'] as $never) {
    $check('permissions: nothing for ' . $never, !str_contains($permCode, $never));
}

// ---- Release order: the compatibility check (Step 10, D2) ----
$fixture = sys_get_temp_dir() . '/sa-compat-' . bin2hex(random_bytes(4));
foreach (['old-app', 'old-worker'] as $tree) {
    mkdir($fixture . '/' . $tree . '/app/services', 0700, true);
}
// The exact lines that decide the versions in release 6482fcd0.
file_put_contents($fixture . '/old-app/app/services/DocumentAnalysis.php', "<?php\n        if (\$object['version'] !== 1) {\n");
file_put_contents($fixture . '/old-app/app/services/AiVerification.php', "<?php\n            'payload' => [\n                'version' => 1,\n");
file_put_contents($fixture . '/old-worker/app/services/AiDocumentWorker.php', "<?php\n        if (!is_array(\$job) || \$job['version'] !== 1) {}\n            return [\n                'version' => 1,\n                'documents' => \$checks,\n");
$compat = static function (string $appDir, string $workerDir) use ($app): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($app . '/bin/ai-worker-compat-check.php') . ' --app=' . escapeshellarg($appDir) . ' --worker=' . escapeshellarg($workerDir) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
[$code, $out] = $compat($app, $app);
$check('release order: this application with this worker is compatible (exit 0)', $code === 0 && str_contains($out, 'compatible'), $out);
[$code, $out] = $compat($app, $fixture . '/old-worker');
$check('release order: this application with the worker installed now (6482fcd0) is compatible', $code === 0, $out);
[$code, $out] = $compat($fixture . '/old-app', $app);
$check('release order: the live application (6482fcd0) with this worker is REFUSED (exit 1), naming the order', $code === 1
    && str_contains($out, 'Deploy the application release first, then refresh the worker'), $out);
[$code, $out] = $compat($fixture . '/missing', $app);
$check('release order: unreadable trees are an error (exit 2), never "compatible"', $code === 2, $out);
exec('rm -rf ' . escapeshellarg($fixture));
$docs = (string) file_get_contents($app . '/docs/AI_DOCUMENT_VERIFICATION.md');
$check('the docs state the release order and the check to run', str_contains($docs, '### Release order') && str_contains($docs, 'bin/ai-worker-compat-check.php'));

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

$permLines = preg_grep('/^\s*setfacl /', explode("\n", (string) file_get_contents($app . '/deploy/sbin/smart-assess-ai-dispatch-permissions.sh')));
$check('Step 13: every setfacl line is physical (-P): a symbolic link swapped in after the check is never followed',
    count($permLines) === 5 && count(preg_grep('/^\s*setfacl (-R )?-P /', $permLines)) === 5, array_values($permLines));

// ---- Step 13: the two links and the worker pool's isolation ----
$unitValue = static function (string $text, string $key): array {
    preg_match_all('/^' . preg_quote($key, '/') . '=(.*)$/m', $text, $m);
    return array_map('trim', $m[1]);
};
foreach ([['smart-assess-ai-worker-link', '/run/smart-assess-ai-link/worker.sock', 'sa-ai-dispatch', '127.0.0.1:8095'],
          ['smart-assess-ai-ollama-link', '/run/smart-assess-ai-ollama/ollama.sock', 'sa-ai-worker', '127.0.0.1:11434']] as [$link, $path, $group, $target]) {
    $sock = (string) @file_get_contents($app . '/deploy/systemd/' . $link . '.socket');
    $svc = (string) @file_get_contents($app . '/deploy/systemd/' . $link . '.service');
    $check("Step 13: {$link}.socket: {$path}, root:{$group} 0660, one forwarder (Accept=no)", $unitValue($sock, 'ListenStream') === [$path]
        && $unitValue($sock, 'SocketUser') === ['root'] && $unitValue($sock, 'SocketGroup') === [$group] && $unitValue($sock, 'SocketMode') === ['0660']
        && $unitValue($sock, 'Accept') === ['no']);
    $check("Step 13: {$link}.service forwards only to {$target} (systemd-socket-proxyd)", $unitValue($svc, 'ExecStart') === ['/usr/lib/systemd/systemd-socket-proxyd --exit-idle-time=120s ' . $target]
        && $unitValue($svc, 'Requires') === [$link . '.socket']);
    $check("Step 13: {$link}.service: no privileges, may reach only 127.0.0.1", $unitValue($svc, 'DynamicUser') === ['yes'] && $unitValue($svc, 'CapabilityBoundingSet') === ['']
        && $unitValue($svc, 'NoNewPrivileges') === ['yes'] && $unitValue($svc, 'IPAddressDeny') === ['any'] && $unitValue($svc, 'IPAddressAllow') === ['127.0.0.1/32']
        && $unitValue($svc, 'ProtectSystem') === ['strict'] && $unitValue($svc, 'SystemCallFilter') === ['@system-service']);
}
$fpmDropIn = (string) @file_get_contents($app . '/deploy/systemd/smart-assess-ai-worker-fpm.service.d/50-private-network.conf');
$check('Step 13: the worker pool (it parses the documents) gets no network and only Unix sockets', $unitValue($fpmDropIn, 'PrivateNetwork') === ['yes']
    && $unitValue($fpmDropIn, 'RestrictAddressFamilies') === ['', 'AF_UNIX'] && $unitValue($fpmDropIn, 'Requires') === ['smart-assess-ai-ollama-link.socket']
    && $unitValue($fpmDropIn, 'After') === ['smart-assess-ai-ollama-link.socket'], $fpmDropIn);
$pool = (string) file_get_contents($app . '/deploy/systemd/worker-php-fpm-pool.conf.example');
$check('Step 13: the pool passes the Ollama socket to PHP (clear_env)', str_contains($pool, 'env[SMART_ASSESS_WORKER_OLLAMA_SOCKET] = /run/smart-assess-ai-ollama/ollama.sock'));
$check('Step 13: the dispatcher cannot use the Ollama link and the worker cannot use the dispatcher link (different groups)',
    !str_contains((string) file_get_contents($app . '/deploy/systemd/smart-assess-ai-ollama-link.socket'), 'sa-ai-dispatch')
    && !preg_match('/^SupplementaryGroups=.*sa-ai-worker/m', $unitText));

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
