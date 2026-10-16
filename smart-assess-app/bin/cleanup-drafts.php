<?php
declare(strict_types=1);

// Deletes expired guest application drafts and their uploaded files.
// Drafts are also cleaned up automatically while clients use the site; run
// this from cron for a guaranteed daily sweep, e.g.
//   15 2 * * *  SMART_ASSESS_DB_PASS=... php /path/to/smart-assess-app/bin/cleanup-drafts.php
// Uses the same DB_* / SMART_ASSESS_DB_PASS environment as run-local.sh.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../web/bootstrap.php';

require_once __DIR__ . '/../web/drafts.php';

if (!sa_drafts_enabled()) {
    fwrite(STDERR, "Draft tables are missing. Run database/migrations/20261015_request_drafts.sql first.\n");
    exit(1);
}
$total = 0;
do {
    $removed = sa_draft_cleanup(200);
    $total += $removed;
} while ($removed === 200);
echo "Removed {$total} expired draft(s).\n";
