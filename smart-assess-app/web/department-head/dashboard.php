<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['head']);

$pdo = db();
publish_due_announcements($pdo);
mark_overdue_requests($pdo);
$totals = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(flow='docreq') AS docreq_count,
    SUM(flow='landtransfer') AS landtransfer_count,
    SUM(requirement_complete=1) AS complete_count
  FROM requests")->fetch();
$total = (int) $totals['total'];
$completeRate = $total ? round(((int)$totals['complete_count']) / $total * 100) : 0;
$announcementCount = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();

$pageTitle = 'Department Head Dashboard';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Department Head Dashboard</h1><p>Monitor system-wide performance and communicate with staff and admin.</p></div></div>
  <div class="wrap">
    <div class="stat-row">
      <div class="stat-card"><div class="num"><?= $total ?></div><div class="lbl">Total Requests</div></div>
      <div class="stat-card"><div class="num"><?= (int)$totals['docreq_count'] ?></div><div class="lbl">Document Requests</div></div>
      <div class="stat-card"><div class="num"><?= (int)$totals['landtransfer_count'] ?></div><div class="lbl">Land Transfers</div></div>
      <div class="stat-card"><div class="num"><?= $completeRate ?>%</div><div class="lbl">Requirement-Complete Rate</div></div>
    </div>
    <div class="panel-card">
      <h3><?= icon_span('grid','16px') ?> Quick Links</h3>
      <div class="action-row">
        <a class="action-btn primary" href="/department-head/reports.php"><?= icon_span('bar','14px') ?> Reports</a>
        <a class="action-btn" href="/department-head/announcements.php"><?= icon_span('bell','14px') ?> Announcements (<?= $announcementCount ?>)</a>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
