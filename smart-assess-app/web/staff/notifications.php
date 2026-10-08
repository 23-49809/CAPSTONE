<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

$notes = db()->query(
    "SELECT l.status, l.sms_body, l.created_at, r.reference_no, r.first_name, r.last_name, r.contact_number
     FROM request_status_log l JOIN requests r ON r.id = l.request_id
     ORDER BY l.created_at DESC LIMIT 100"
)->fetchAll();

$pageTitle = 'Notifications Sent';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Notifications Sent</h1><p>Every SMS status notification the system has sent to clients (most recent 100).</p></div></div>
  <div class="wrap">
    <div class="panel-card">
      <?php if (!$notes): ?><p style="font-size:13px;color:var(--ink-faint)">No notifications sent yet.</p>
      <?php else: foreach ($notes as $n): ?>
        <div class="sms-item">
          <strong class="mono"><?= esc($n['reference_no']) ?></strong> &middot; <?= esc(trim($n['first_name'] . ' ' . $n['last_name'])) ?> (<?= esc($n['contact_number']) ?>)<br>
          <?= esc($n['sms_body']) ?>
          <div class="sms-when"><?= fmt_datetime($n['created_at']) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
