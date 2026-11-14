<?php
declare(strict_types=1);

/*
 * What a submission does when its COMMIT fails (Step 10, R5): sa_commit_submission() in
 * web/api.php, with real database connections and simulated failures:
 *   - the commit really happened but the answer was lost (connection dropped right after):
 *     a fresh connection finds the request, so it counts as saved (files are kept);
 *   - the commit failed (the database rolled back): a fresh connection finds nothing, so it
 *     counts as not saved (the usual clean-up);
 *   - not even a fresh connection works: "unknown", and the caller keeps every file;
 *   - a normal commit.
 * Plus source checks that sa_submit_request() uses it, keeps files on "unknown", and never
 * lets a failing rollBack() skip the clean-up. The end-to-end lost-connection case (the
 * connection killed mid-transaction) is in ai_verification_workflow_test.php.
 *
 *   SMART_ASSESS_TEST_DB=<name>_test DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=... \
 *   SMART_ASSESS_DB_PASS='...' php tests/submission_commit_recovery_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise. Uses only a database whose name ends in _test.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dbName = (string) getenv('SMART_ASSESS_TEST_DB');
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $dbName)) {
    fwrite(STDERR, "Refusing to run: set SMART_ASSESS_TEST_DB to a dedicated database whose name ends in _test.\n");
    exit(2);
}
$app = dirname(__DIR__);
$work = sys_get_temp_dir() . '/sa-commit-test-' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
ini_set('log_errors', '1');
ini_set('error_log', $work . '/php-errors.log');
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($work)));
putenv('DB_NAME=' . $dbName);
foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USER' => 'root'] as $key => $default) {
    putenv($key . '=' . (getenv($key) ?: $default));
}
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), (int) getenv('DB_PORT'), $dbName);
$user = (string) getenv('DB_USER');
$pass = (string) getenv('SMART_ASSESS_DB_PASS');
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

require $app . '/web/bootstrap.php';
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 300)];
};

// ---- The real function from web/api.php ----
$api = (string) file_get_contents($app . '/web/api.php');
$extract = static function (string $source, string $name): string {
    $start = strpos($source, "function {$name}(");
    if ($start === false) {
        throw new RuntimeException("{$name} not found");
    }
    $depth = 0;
    $code = '';
    foreach (array_slice(token_get_all('<?php ' . substr($source, $start)), 1) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $code .= $text;
        if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($text === '}' && --$depth === 0) {
            return $code;
        }
    }
    throw new RuntimeException("Could not read {$name}");
};
file_put_contents($work . '/functions.php', "<?php\n" . $extract($api, 'sa_commit_submission') . "\n");
require $work . '/functions.php';

// ---- Connections whose commit fails ----
final class CommittedThenLostPDO extends PDO
{
    public function commit(): bool
    {
        parent::commit();   // the database committed ...
        throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');   // ... but the answer was lost
    }
}
/** The commit is reported as failed, but the server finishes it a moment later (in doubt). */
final class LateCommitPDO extends PDO
{
    public function commit(): bool
    {
        throw new PDOException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query');
    }
    public function finishOnServer(): void
    {
        parent::commit();
    }
}
final class FailedCommitPDO extends PDO
{
    public function commit(): bool
    {
        parent::rollBack();   // the database did not commit
        throw new PDOException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query');
    }
}

$admin = new PDO($dsn, $user, $pass, $options);
$admin->exec('DROP TABLE IF EXISTS requests');
$admin->exec('CREATE TABLE requests (id INT AUTO_INCREMENT PRIMARY KEY, reference_no VARCHAR(20) NOT NULL) ENGINE=InnoDB');
$saved = static fn (int $id): bool => (int) $admin->query('SELECT COUNT(*) FROM requests WHERE id = ' . $id)->fetchColumn() === 1;
$begin = static function (PDO $pdo, string $reference): int {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO requests (reference_no) VALUES (?)')->execute([$reference]);
    return (int) $pdo->lastInsertId();
};
$fresh = static fn (): PDO => new PDO($dsn, $user, $pass, $options);

$pdo = new PDO($dsn, $user, $pass, $options);
$id = $begin($pdo, 'DR-2026-NORMAL1');
$check('a normal commit: committed, and the request is saved', sa_commit_submission($pdo, $id, $fresh) === 'committed' && $saved($id));

$pdo = new CommittedThenLostPDO($dsn, $user, $pass, $options);
$id = $begin($pdo, 'DR-2026-LOSTACK');
$outcome = sa_commit_submission($pdo, $id, $fresh);
$check('commit happened but its answer was lost: a fresh connection finds it, so it is "committed" (never deleted)', $outcome === 'committed' && $saved($id), $outcome);

$pdo = new FailedCommitPDO($dsn, $user, $pass, $options);
$id = $begin($pdo, 'DR-2026-NOTSAVED');
$started = microtime(true);
$outcome = sa_commit_submission($pdo, $id, $fresh, [0, 0.2, 0.2]);
$check('commit failed: looked for several times, never found, so "not_found" (the caller still keeps every file)', $outcome === 'not_found' && !$saved($id)
    && microtime(true) - $started >= 0.35, $outcome);

$pdo = new LateCommitPDO($dsn, $user, $pass, $options);
$id = $begin($pdo, 'DR-2026-INDOUBT');
$looks = 0;
$outcome = sa_commit_submission($pdo, $id, static function () use (&$looks, $pdo, $fresh): PDO {
    if (++$looks === 2) {
        $pdo->finishOnServer();   // the server completes the commit only after the first look
    }
    return $fresh();
}, [0, 0.1, 0.1]);
$check('in doubt: the commit completes on the server after the first look; a later look finds it, so "committed"', $outcome === 'committed' && $saved($id) && $looks === 2, [$outcome, $looks]);

$pdo = new FailedCommitPDO($dsn, $user, $pass, $options);
$id = $begin($pdo, 'DR-2026-UNKNOWN');
$outcome = sa_commit_submission($pdo, $id, static fn (): PDO => throw new PDOException('SQLSTATE[HY000] [2002] Connection refused'), [0, 0.1]);
$check('commit failed and no fresh connection is possible: "unknown"', $outcome === 'unknown', $outcome);

$pdo = new CommittedThenLostPDO($dsn, $user, $pass, $options);
$id = $begin($pdo, 'DR-2026-DEFAULT');
$outcome = sa_commit_submission($pdo, $id);   // the real default: sa_db_connect() with the environment's settings
$check('by default the fresh connection is sa_db_connect() (same settings, a new connection)', $outcome === 'committed' && $saved($id), $outcome);
$defaults = (new ReflectionFunction('sa_commit_submission'))->getParameters()[3]->getDefaultValue();
$check('by default an uncertain commit is looked for at least 3 times over at least 3 seconds (a late commit is found)',
    is_array($defaults) && count($defaults) >= 3 && array_sum($defaults) >= 3, $defaults);
$log = (string) @file_get_contents($work . '/php-errors.log');
$check('each uncertain commit is logged by request ID only (no names or references)', substr_count($log, 'The commit of a submission (request ID') === 5
    && str_contains($log, 'not found after its commit failed; its files are kept') && str_contains($log, 'not checked (no connection); its files are kept')
    && !preg_match('/DR-2026-/', $log), $log);

// ---- sa_submit_request() uses it as intended ----
$submit = $extract($api, 'sa_submit_request');
// One direct commit remains: the duplicate-submission return, which only ends a read (a locked draft) and saves nothing.
$check('the submission saves only through sa_commit_submission()', str_contains($submit, '$committed = sa_commit_submission($pdo, $requestId);')
    && preg_match_all('/\$pdo->commit\(\)/', $submit) === 1
    && str_contains($submit, "\$pdo->commit();\n                return ['reference_no' => (string) \$draft['submitted_reference'], 'status' => 'Received', 'duplicate' => true];"),
    'direct commits: ' . preg_match_all('/\$pdo->commit\(\)/', $submit));
$check('any uncertain commit (not found or unknown) keeps every uploaded file and the draft\'s files', str_contains($submit, '$keepFiles = true;')
    && (bool) preg_match('/if \(\$keepFiles\) \{[^}]*throw \$exception;/s', $submit));
$check('a failing rollBack() is caught, so the clean-up always runs', (bool) preg_match('/try \{\s*if \(\$pdo->inTransaction\(\)\) \{\s*\$pdo->rollBack\(\);\s*\}\s*\} catch \(Throwable\)/s', $submit));
$check('the clean-up after a failed submission cannot itself fail on a missing file', str_contains($submit, '@unlink($path);'));
$check('a definitely failed submission removes only its own copies; the draft\'s files are removed only after a commit (F7)',
    !str_contains($submit, '$movedDraftFiles') && str_contains($submit, "foreach (array_merge(\$draftOriginals, \$unusedDraftFiles) as \$path)")
    && strpos($submit, 'array_merge($draftOriginals') > strpos($submit, '$committed = sa_commit_submission('));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
