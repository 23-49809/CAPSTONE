<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

$statusFilter = in_array($_GET['status'] ?? '', STATUS_FLOW, true) ? $_GET['status'] : '';
$sql = "SELECT * FROM requests WHERE flow = 'landtransfer'";
$params = [];
if ($statusFilter) { $sql .= ' AND status = ?'; $params[] = $statusFilter; }
$sql .= ' ORDER BY created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Land Transfers';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Land Transfers</h1><p>Sale, Donation, and Estate transfer requests only.</p></div></div>
  <div class="wrap">
    <div class="toolbar">
      <form method="get">
        <select name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (STATUS_FLOW as $s): ?><option value="<?= esc($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= esc($s) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="table-wrap"><?= render_requests_table($requests) ?></div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
