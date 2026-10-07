<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

publish_due_announcements(db());

$totals = db()->query("SELECT
    COUNT(*) AS total,
    SUM(status='Received') AS received,
    SUM(status='Processing') AS processing,
    SUM(requirement_complete=0) AS flagged
  FROM requests")->fetch();

$recent = db()->query('SELECT * FROM requests ORDER BY created_at DESC LIMIT 6')->fetchAll();
$announcements = db()->query("SELECT * FROM announcements WHERE status = 'Published' AND audience IN ('staff','both') ORDER BY created_at DESC LIMIT 3")->fetchAll();

$pageTitle = "Staff Dashboard";
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Assessor's Staff Dashboard</h1><p>Review requests, confirm the AI rule-based checker's results, and update processing status.</p></div></div>
  <div class="wrap">
    <div class="stat-row">
      <div class="stat-card"><div class="num"><?= (int)$totals['total'] ?></div><div class="lbl">Total Requests</div></div>
      <div class="stat-card"><div class="num"><?= (int)$totals['received'] ?></div><div class="lbl">Newly Received</div></div>
      <div class="stat-card"><div class="num"><?= (int)$totals['processing'] ?></div><div class="lbl">Processing</div></div>
      <div class="stat-card"><div class="num"><?= (int)$totals['flagged'] ?></div><div class="lbl">Flagged by AI Checker</div></div>
    </div>

    <div class="panel-card">
      <h3><?= icon_span('grid','16px') ?> Quick Links</h3>
      <div class="action-row">
        <a class="action-btn primary" href="/staff/requests.php"><?= icon_span('checklist','14px') ?> All Requests</a>
        <a class="action-btn" href="/staff/document-requests.php"><?= icon_span('doc','14px') ?> Document Requests</a>
        <a class="action-btn" href="/staff/land-transfers.php"><?= icon_span('swap','14px') ?> Land Transfers</a>
        <a class="action-btn" href="/staff/ai-checker.php"><?= icon_span('shield','14px') ?> AI Checker Results</a>
        <a class="action-btn" href="/staff/notifications.php"><?= icon_span('bell','14px') ?> Notifications Sent</a>
      </div>
    </div>

    <div class="panel-card">
      <h3><?= icon_span('clock','16px') ?> Recently Received</h3>
      <?php if (!$recent): ?><p style="font-size:13px;color:var(--ink-faint)">No requests yet.</p>
      <?php else: foreach ($recent as $r): ?>
        <div class="review-row"><span class="k"><?= esc($r['reference_no']) ?> &middot; <?= esc(trim($r['first_name'] . ' ' . $r['last_name'])) ?></span><span class="v"><a class="icon-btn" href="/staff/detail.php?id=<?= (int)$r['id'] ?>"><?= icon_span('eye','12px') ?> View</a></span></div>
      <?php endforeach; endif; ?>
    </div>

    <div class="panel-card">
      <h3><?= icon_span('bell','16px') ?> Announcements from Department Head</h3>
      <?php if (!$announcements): ?><p style="font-size:13px;color:var(--ink-faint)">No announcements yet.</p>
      <?php else: foreach ($announcements as $a): ?>
        <div class="announcement-card"><h4><?= esc($a['title']) ?></h4><div class="ac-meta"><?= esc($a['author']) ?> &middot; <?= fmt_datetime($a['created_at']) ?></div><p><?= esc($a['body']) ?></p></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
