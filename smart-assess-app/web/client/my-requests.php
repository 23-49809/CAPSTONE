<?php
require_once __DIR__ . '/../includes/client_auth.php';
$client = require_client();

mark_overdue_requests(db());

$stmt = db()->prepare('SELECT * FROM requests WHERE client_id = ? ORDER BY created_at DESC');
$stmt->execute([$client['id']]);
$myRequests = $stmt->fetchAll();

$pageTitle = 'My Requests';
require __DIR__ . '/../includes/client_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>My Requests</h1><p>Every Document Request and Land Transfer you've submitted while signed in.</p></div></div>
  <div class="wrap">
    <div class="table-wrap">
      <?php if (!$myRequests): ?>
        <div class="empty-state">You haven't submitted any requests yet. <a href="/client/document-request.php">Start a Document Request</a> or <a href="/client/land-transfer.php">Land Transfer</a>.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Reference No.</th><th>Service</th><th>Type</th><th>Submitted</th><th>Requirement Check</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($myRequests as $r): ?>
            <tr>
              <td class="mono"><?= esc($r['reference_no']) ?></td>
              <td><?= $r['flow'] === 'docreq' ? 'Document Request' : 'Land Transfer' ?></td>
              <td><?= esc($r['document_type'] ?: $r['transfer_type']) ?></td>
              <td class="mono"><?= fmt_date($r['created_at']) ?></td>
              <td><?php if ($r['requirement_complete']): ?><span class="badge green"><?= icon_span('check','12px') ?> Complete</span><?php else: ?><span class="badge amber"><?= icon_span('alert','12px') ?> Needs attention</span><?php endif; ?></td>
              <td><span class="badge <?= status_badge_class($r['status']) ?>"><?= esc($r['status']) ?></span></td>
              <td><a class="icon-btn" href="/client/track-request.php?ref=<?= urlencode($r['reference_no']) ?>"><?= icon_span('search','14px') ?> View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
