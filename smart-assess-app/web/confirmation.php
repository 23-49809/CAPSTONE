<?php
require_once __DIR__ . '/includes/client_auth.php';

mark_overdue_requests(db());

$refNo = trim($_GET['ref'] ?? '');
$req = $refNo !== '' ? fetch_request_by_reference($refNo) : null;

$pageTitle = 'Request Submitted';
require __DIR__ . '/includes/client_header.php';
?>
<div class="confirm-shell">
  <?php if (!$req): ?>
    <div class="confirm-card fade-in">
      <h2>Request Not Found</h2>
      <p style="color:var(--ink-soft);font-size:14px;margin-top:10px">We couldn't find a request for reference "<?= esc($refNo) ?>".</p>
      <div class="confirm-actions"><a class="btn btn-primary" href="/index.php">Back to Home</a></div>
    </div>
  <?php else: ?>
    <div class="confirm-card fade-in">
      <div class="confirm-icon"><?= icon('check') ?></div>
      <h2>Request Submitted</h2>
      <p style="color:var(--ink-soft);font-size:14px;margin-top:8px">Your <?= $req['flow'] === 'docreq' ? 'document request' : 'land transfer request' ?> has been received by the Municipal Assessor's Office. Use your tracking number below to check your request status, or watch for SMS updates at <?= esc($req['contact_number']) ?>.</p>
      <div style="margin-top:20px;font-size:13px;font-weight:700;color:var(--ink-soft);text-align:center">Your Request Tracking Number</div>
      <div class="confirm-ref"><?= esc($req['reference_no']) ?></div>
      <p class="field-hint" style="margin:10px 0 0;text-align:center">Please save this number. You will need it to check your request status.</p>
      <div class="confirm-actions">
        <a class="btn btn-ghost" href="/client/track-request.php?ref=<?= urlencode($req['reference_no']) ?>"><?= icon_span('search') ?> View My Request Status</a>
        <?php if (current_client()): ?>
          <a class="btn btn-ghost" href="/client/my-requests.php"><?= icon_span('grid') ?> My Requests</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="/index.php">Back to Home</a>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
