<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['head']);

$pdo = db();
mark_overdue_requests($pdo);
$statusCounts = array_fill_keys(STATUS_FLOW, 0);
foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM requests GROUP BY status") as $row) {
    $statusCounts[$row['status']] = (int) $row['n'];
}

$barangays = $pdo->query(
    "SELECT barangay, COUNT(*) AS n FROM requests GROUP BY barangay ORDER BY n DESC LIMIT 8"
)->fetchAll();
$maxBarangay = $barangays ? max(array_column($barangays, 'n')) : 1;

$recent = $pdo->query('SELECT * FROM requests ORDER BY created_at DESC LIMIT 6')->fetchAll();
$requestTotal = array_sum($statusCounts);
$dateGenerated = date('F j, Y g:i A');

$pageTitle = 'Reports';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Reports</h1><p>Status breakdown, barangay demand, and recent activity across the office.</p></div></div>
  <div class="wrap">
    <div class="action-row no-print"><button type="button" class="btn btn-primary" onclick="window.print()"><?= icon_span('print') ?> Print Report</button></div>

    <div id="report-printable">
      <div class="report-print-head">
        <h2>Generated Report</h2>
        <dl>
          <dt>Report Title</dt><dd>Office Performance Report</dd>
          <dt>Report Type</dt><dd>Request Status, Barangay Demand &amp; Recent Activity Summary</dd>
          <dt>Reporting Period</dt><dd>All records to date (<?= (int) $requestTotal ?> total requests)</dd>
          <dt>Date Generated</dt><dd><?= esc($dateGenerated) ?></dd>
          <dt>Prepared By</dt><dd><?= esc($me['name']) ?> &middot; Department Head</dd>
        </dl>
      </div>

      <div class="panel-card">
        <h3><?= icon_span('checklist','16px') ?> Requests by Status</h3>
        <div class="status-legend">
          <?php foreach (STATUS_FLOW as $s): ?>
            <span class="badge <?= status_badge_class($s) ?>"><?= esc($s) ?></span>&nbsp;<span class="mono" style="font-size:12px;color:var(--ink-soft)">(<?= $statusCounts[$s] ?>)</span>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="panel-card">
        <h3><?= icon_span('pin','16px') ?> Barangays with the Most Requests</h3>
        <?php if (!$barangays): ?><p style="font-size:13px;color:var(--ink-faint)">No submissions yet.</p>
        <?php else: foreach ($barangays as $b): $pct = max(4, round($b['n'] / $maxBarangay * 100)); ?>
          <div class="bar-row"><span><?= esc($b['barangay']) ?></span><div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div><span class="bar-count"><?= (int)$b['n'] ?></span></div>
        <?php endforeach; endif; ?>
      </div>
      <div class="panel-card">
        <h3><?= icon_span('clock','16px') ?> Recent Activity</h3>
        <?php if (!$recent): ?><p style="font-size:13px;color:var(--ink-faint)">No submissions yet.</p>
        <?php else: ?>
        <table>
          <thead><tr><th>Reference No.</th><th>Applicant</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr><td class="mono"><?= esc($r['reference_no']) ?></td><td><?= esc(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td><td><span class="badge <?= status_badge_class($r['status']) ?>"><?= esc($r['status']) ?></span></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
