<?php
require_once __DIR__ . '/../includes/client_auth.php';
$client = require_client();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['action'] ?? '') === 'mark_read') {
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND client_id = ? AND read_at IS NULL')
        ->execute([$id, $client['id']]);
}

$stmt = db()->prepare(
    'SELECT n.id, n.message, n.read_at, n.created_at, r.reference_no
     FROM notifications n
     LEFT JOIN requests r ON r.id = n.request_id
     WHERE n.client_id = ?
     ORDER BY n.created_at DESC'
);
$stmt->execute([$client['id']]);
$notes = $stmt->fetchAll();
$unreadCount = count(array_filter($notes, fn($n) => $n['read_at'] === null));

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/client_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Notifications</h1><p>Status updates for all of your requests<?= $unreadCount ? ' — ' . $unreadCount . ' unread' : '' ?> (also delivered by SMS to your contact number).</p></div></div>
  <div class="wrap">
    <div class="panel-card">
      <?php if (!$notes): ?>
        <p style="font-size:13px;color:var(--ink-faint)">No notifications yet.</p>
      <?php else: foreach ($notes as $n): ?>
        <div class="sms-item<?= $n['read_at'] === null ? ' unread' : '' ?>">
          <strong class="mono"><?= esc($n['reference_no'] ?? '') ?></strong> &middot; <?= esc($n['message']) ?>
          <div class="sms-when">
            <?= fmt_datetime($n['created_at']) ?>
            <?php if ($n['read_at'] === null): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button type="submit" class="icon-btn" style="margin-left:8px;padding:3px 8px;font-size:11px">Mark as read</button></form>
            <?php else: ?>
              <span style="margin-left:8px;color:var(--ink-faint)">Read</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
