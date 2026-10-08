<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

mark_overdue_requests(db());

$flowFilter = in_array($_GET['flow'] ?? '', ['docreq', 'landtransfer'], true) ? $_GET['flow'] : '';
// "Time Stamp Tracking" in the sidebar links here with ?status=overdue —
// by the time mark_overdue_requests() above has run, every genuinely
// overdue request already carries the real 'Timed Out' status, so this is
// just a friendlier alias for that filter rather than a second code path.
$requestedStatus = $_GET['status'] ?? '';
$statusFilter = $requestedStatus === 'overdue' ? 'Timed Out' : (in_array($requestedStatus, STATUS_FLOW, true) ? $requestedStatus : '');

$sql = 'SELECT requests.*, (SELECT MAX(created_at) FROM request_status_log WHERE request_id = requests.id) AS last_action_at FROM requests WHERE 1=1';
$params = [];
if ($flowFilter) { $sql .= ' AND flow = ?'; $params[] = $flowFilter; }
if ($statusFilter) { $sql .= ' AND status = ?'; $params[] = $statusFilter; }
$sql .= ' ORDER BY created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

function qs(array $overrides): string
{
    $base = ['flow' => $_GET['flow'] ?? '', 'status' => $_GET['status'] ?? ''];
    return '?' . http_build_query(array_merge($base, $overrides));
}

$pageTitle = 'All Requests';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>All Requests</h1><p>Every Document Request and Land Transfer submitted to the office.</p></div></div>
  <div class="wrap">
    <div class="toolbar">
      <div class="seg">
        <a class="<?= $flowFilter === '' ? 'active' : '' ?>" href="<?= qs(['flow' => '']) ?>">All</a>
        <a class="<?= $flowFilter === 'docreq' ? 'active' : '' ?>" href="<?= qs(['flow' => 'docreq']) ?>">Document Request</a>
        <a class="<?= $flowFilter === 'landtransfer' ? 'active' : '' ?>" href="<?= qs(['flow' => 'landtransfer']) ?>">Land Transfer</a>
      </div>
      <form method="get">
        <input type="hidden" name="flow" value="<?= esc($flowFilter) ?>">
        <select name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (STATUS_FLOW as $s): ?><option value="<?= esc($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="table-wrap"><?= render_requests_table($requests) ?></div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
