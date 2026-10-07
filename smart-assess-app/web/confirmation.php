<?php
require_once __DIR__ . '/includes/client_auth.php';

mark_overdue_requests(db());

$refNo = trim($_GET['ref'] ?? '');
$req = $refNo !== '' ? fetch_request_by_reference($refNo) : null;

$pageTitle = 'Request Submitted';
require __DIR__ . '/includes/client_header.php';
?>
<div class="form-shell"><div class="form-col narrow">
  <?php if (!$req): ?>
    <div class="confirm-card">
      <h2>Request Not Found</h2>
      <p style="color:var(--ink-soft);font-size:14px;margin-top:10px">We couldn't find a request for reference "<?= esc($refNo) ?>".</p>
      <div class="confirm-actions"><a class="btn btn-primary" href="/index.php">Back to Home</a></div>
    </div>
  <?php else: ?>
    <div class="confirm-card">
      <div class="confirm-icon"><?= icon('check') ?></div>
      <h2>Request Submitted</h2>
      <p style="color:var(--ink-soft);font-size:14px;margin-top:8px">Your <?= $req['flow'] === 'docreq' ? 'document request' : 'land transfer request' ?> has been received by the Municipal Assessor's Office. You will get SMS updates at <?= esc($req['contact_number']) ?>.</p>
      <div class="confirm-ref"><?= esc($req['reference_no']) ?></div>
      <div style="text-align:left">
        <?php $showPersonal = false; require __DIR__ . '/includes/request_view.php'; ?>
      </div>
      <div class="confirm-actions">
        <a class="btn btn-ghost" href="/client/track-request.php?ref=<?= urlencode($req['reference_no']) ?>"><?= icon_span('search') ?> Track This Request</a>
        <?php if (current_client()): ?>
          <a class="btn btn-ghost" href="/client/my-requests.php"><?= icon_span('grid') ?> My Requests</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="/index.php">Back to Home</a>
      </div>
    </div>
  <?php endif; ?>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
