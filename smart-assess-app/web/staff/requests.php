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

$searchQuery = trim($_GET['q'] ?? '');
$sql = 'SELECT requests.*, (SELECT MAX(created_at) FROM request_status_log WHERE request_id = requests.id) AS last_action_at FROM requests WHERE 1=1';
$countSql = 'SELECT COUNT(*) FROM requests WHERE 1=1';
$params = [];
if ($flowFilter) { $sql .= ' AND flow = ?'; $countSql .= ' AND flow = ?'; $params[] = $flowFilter; }
if ($statusFilter) { $sql .= ' AND status = ?'; $countSql .= ' AND status = ?'; $params[] = $statusFilter; }
if ($searchQuery !== '') {
    $sql .= ' AND (reference_no LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $countSql .= ' AND (reference_no LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $like = "%$searchQuery%";
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY created_at DESC';

$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$pageInfo = paginate_info((int) $countStmt->fetchColumn(), 10);
$sql .= " LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

function qs(array $overrides): string
{
    $base = ['flow' => $_GET['flow'] ?? '', 'status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? ''];
    return '?' . http_build_query(array_merge($base, $overrides));
}

$pageTitle = 'All Requests';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>All Requests</h1><p>Every Document Request and Land Transfer submitted to the office.</p></div></div>
  <div class="wrap">
    <div class="toolbar">
      <form method="get" class="search-box">
        <?= icon_span('search') ?>
        <input type="text" name="q" value="<?= esc($searchQuery) ?>" placeholder="Search reference or applicant&hellip;" onchange="this.form.submit()">
        <input type="hidden" name="flow" value="<?= esc($flowFilter) ?>">
        <input type="hidden" name="status" value="<?= esc($statusFilter) ?>">
      </form>
      <div class="seg">
        <a class="<?= $flowFilter === '' ? 'active' : '' ?>" href="<?= qs(['flow' => '']) ?>">All</a>
        <a class="<?= $flowFilter === 'docreq' ? 'active' : '' ?>" href="<?= qs(['flow' => 'docreq']) ?>">Document Request</a>
        <a class="<?= $flowFilter === 'landtransfer' ? 'active' : '' ?>" href="<?= qs(['flow' => 'landtransfer']) ?>">Land Transfer</a>
      </div>
      <form method="get">
        <input type="hidden" name="flow" value="<?= esc($flowFilter) ?>">
        <input type="hidden" name="q" value="<?= esc($searchQuery) ?>">
        <select name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (STATUS_FLOW as $s): ?><option value="<?= esc($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="table-wrap"><?= render_requests_table($requests) ?><?= render_pager($pageInfo) ?></div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
