<?php
require_once __DIR__ . '/../includes/client_auth.php';
$client = require_client();

mark_overdue_requests(db());

$stmt = db()->prepare('SELECT * FROM requests WHERE client_id = ? ORDER BY created_at DESC');
$stmt->execute([$client['id']]);
$myRequests = $stmt->fetchAll();
$open = array_filter($myRequests, fn($r) => !in_array($r['status'], ['Approved', 'Out for Release', 'Rejected'], true));

$pageTitle = 'My Dashboard';
require __DIR__ . '/../includes/client_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Welcome back, <?= esc($client['first_name']) ?></h1><p>Here's an overview of your requests with the Municipal Assessor's Office.</p></div></div>
  <div class="wrap">
    <div class="stat-row">
      <div class="stat-card"><div class="num"><?= count($myRequests) ?></div><div class="lbl">Total Requests</div></div>
      <div class="stat-card"><div class="num"><?= count($open) ?></div><div class="lbl">In Progress</div></div>
      <div class="stat-card"><div class="num"><?= count(array_filter($myRequests, fn($r) => $r['status'] === 'Approved' || $r['status'] === 'Out for Release')) ?></div><div class="lbl">Approved</div></div>
      <div class="stat-card"><div class="num"><?= count(array_filter($myRequests, fn($r) => $r['status'] === 'Rejected')) ?></div><div class="lbl">Needs Your Attention</div></div>
    </div>

    <div class="panel-card">
      <h3><?= icon_span('grid','16px') ?> Quick Actions</h3>
      <div class="action-row">
        <a class="action-btn primary" href="/client/document-request.php"><?= icon_span('doc','14px') ?> New Document Request</a>
        <a class="action-btn primary" href="/client/land-transfer.php"><?= icon_span('swap','14px') ?> New Land Transfer</a>
        <a class="action-btn" href="/client/my-requests.php"><?= icon_span('checklist','14px') ?> View All My Requests</a>
        <a class="action-btn" href="/client/notifications.php"><?= icon_span('bell','14px') ?> Notifications</a>
        <a class="action-btn" href="/client/profile.php"><?= icon_span('user','14px') ?> My Profile</a>
      </div>
    </div>

    <div class="panel-card">
      <h3><?= icon_span('clock','16px') ?> Recent Requests</h3>
      <?php if (!$myRequests): ?>
        <p style="font-size:13px;color:var(--ink-faint)">You haven't submitted any requests yet.</p>
      <?php else: foreach (array_slice($myRequests, 0, 5) as $r): ?>
        <div class="review-row"><span class="k"><?= esc($r['reference_no']) ?> &middot; <?= $r['flow'] === 'docreq' ? esc($r['document_type']) : esc($r['transfer_type']) . ' Transfer' ?></span><span class="v"><span class="badge <?= status_badge_class($r['status']) ?>"><?= esc($r['status']) ?></span></span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
