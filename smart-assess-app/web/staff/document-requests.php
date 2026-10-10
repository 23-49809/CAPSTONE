<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

mark_overdue_requests(db());

$statusFilter = in_array($_GET['status'] ?? '', STATUS_FLOW, true) ? $_GET['status'] : '';
$searchQuery = trim($_GET['q'] ?? '');
$where = "WHERE flow = 'docreq'";
$params = [];
if ($statusFilter) { $where .= ' AND status = ?'; $params[] = $statusFilter; }
if ($searchQuery !== '') { $where .= ' AND (reference_no LIKE ? OR first_name LIKE ? OR last_name LIKE ?)'; $params[] = $params[] = $params[] = "%$searchQuery%"; }

$countStmt = db()->prepare("SELECT COUNT(*) FROM requests $where");
$countStmt->execute($params);
$pageInfo = paginate_info((int) $countStmt->fetchColumn(), 10);

$sql = "SELECT requests.*, (SELECT MAX(created_at) FROM request_status_log WHERE request_id = requests.id) AS last_action_at FROM requests $where ORDER BY created_at DESC LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Document Requests';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Document Requests</h1><p>CTC-TD and certification requests only.</p></div></div>
  <div class="wrap">
    <div class="toolbar">
      <form method="get" class="search-box">
        <?= icon_span('search') ?>
        <input type="text" name="q" value="<?= esc($searchQuery) ?>" placeholder="Search reference or applicant&hellip;" onchange="this.form.submit()">
        <input type="hidden" name="status" value="<?= esc($statusFilter) ?>">
      </form>
      <form method="get">
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
