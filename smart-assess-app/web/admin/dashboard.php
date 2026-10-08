<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['admin']);

$pdo = db();
publish_due_announcements($pdo);
mark_overdue_requests($pdo);
$accountTotal = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeTotal = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();
$clientTotal = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$requestTotal = (int) $pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn();
$recentAudit = $pdo->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 8')->fetchAll();
$announcements = $pdo->query("SELECT * FROM announcements WHERE status = 'Published' AND audience IN ('staff','both') ORDER BY created_at DESC LIMIT 3")->fetchAll();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Admin Dashboard</h1><p>System-wide account management, roles, settings, and audit visibility.</p></div></div>
  <div class="wrap">
    <div class="stat-row">
      <div class="stat-card"><div class="num"><?= $accountTotal ?></div><div class="lbl">Internal Accounts</div></div>
      <div class="stat-card"><div class="num"><?= $activeTotal ?></div><div class="lbl">Active</div></div>
      <div class="stat-card"><div class="num"><?= $clientTotal ?></div><div class="lbl">Registered Clients</div></div>
      <div class="stat-card"><div class="num"><?= $requestTotal ?></div><div class="lbl">Total Requests</div></div>
    </div>

    <div class="panel-card">
      <h3><?= icon_span('grid','16px') ?> Quick Links</h3>
      <div class="action-row">
        <a class="action-btn primary" href="/admin/users.php"><?= icon_span('users','14px') ?> Manage Users</a>
        <a class="action-btn" href="/admin/archived-users.php"><?= icon_span('archive','14px') ?> Archived Accounts</a>
        <a class="action-btn" href="/admin/roles.php"><?= icon_span('key','14px') ?> Roles</a>
        <a class="action-btn" href="/admin/settings.php"><?= icon_span('id','14px') ?> Settings</a>
        <a class="action-btn" href="/admin/audit-logs.php"><?= icon_span('checklist','14px') ?> Audit Logs</a>
      </div>
    </div>

    <div class="panel-card">
      <h3><?= icon_span('clock','16px') ?> Recent Activity</h3>
      <?php if (!$recentAudit): ?><p style="font-size:13px;color:var(--ink-faint)">No activity logged yet.</p>
      <?php else: foreach ($recentAudit as $a): ?>
        <div class="review-row"><span class="k"><?= esc($a['actor_name']) ?> (<?= esc(ucfirst($a['actor_type'])) ?>)</span><span class="v"><?= esc($a['action']) ?> &middot; <span class="mono" style="font-size:11.5px"><?= fmt_datetime($a['created_at']) ?></span></span></div>
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
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
