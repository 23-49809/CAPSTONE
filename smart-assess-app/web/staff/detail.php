<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$me = require_role(['staff']);

$id = (int) ($_GET['id'] ?? 0);
$req = $id ? fetch_request_by_id($id) : null;
if (!$req) {
    header('Location: /staff/requests.php');
    exit;
}
$statusError = $_GET['error'] ?? '';

$pageTitle = $req['reference_no'];
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap">
    <a href="/staff/requests.php" style="font-size:13px;color:var(--ink-soft);text-decoration:none">&larr; Back to requests</a>
    <h1 style="margin-top:8px"><?= esc($req['reference_no']) ?></h1>
    <p><?= esc(trim($req['first_name'] . ' ' . $req['last_name'])) ?> &middot; Received <?= fmt_date($req['created_at']) ?></p>
  </div></div>
  <div class="wrap" style="max-width:820px;padding-top:26px;padding-bottom:60px">
    <span class="badge <?= status_badge_class($req['status']) ?>"><?= esc($req['status']) ?></span>
    &nbsp;<?php if ($req['requirement_complete']): ?><span class="badge green"><?= icon_span('check','12px') ?> Complete</span><?php else: ?><span class="badge amber"><?= icon_span('alert','12px') ?> Needs review</span><?php endif; ?>
    <?php if ($statusError === 'incomplete_requirements'): ?><div class="flash error" style="margin-top:16px"><?= icon_span('alert') ?> Approval is blocked while required documents are incomplete.</div><?php endif; ?>

    <div class="review-card" style="margin:16px 0">
      <div class="review-row"><span class="k">Service</span><span class="v"><?= $req['flow'] === 'docreq' ? 'Document Request' : 'Land Transfer' ?></span></div>
      <div class="review-row"><span class="k">Type</span><span class="v"><?= esc($req['document_type'] ?: $req['transfer_type']) ?></span></div>
      <div class="review-row"><span class="k">Purpose</span><span class="v"><?= esc($req['purpose']) ?></span></div>
      <div class="review-row"><span class="k">ARP / Tax Decl. No.</span><span class="v"><?= esc($req['arp_number']) ?></span></div>
      <div class="review-row"><span class="k">Property Address</span><span class="v"><?= esc($req['property_address']) ?></span></div>
      <div class="review-row"><span class="k">Barangay</span><span class="v"><?= esc($req['barangay']) ?></span></div>
      <div class="review-row"><span class="k">Contact / Email</span><span class="v"><?= esc($req['contact_number']) ?> &middot; <?= esc($req['email']) ?></span></div>
      <div class="review-row"><span class="k">Address</span><span class="v"><?= esc($req['address_line'] . ', ' . $req['city'] . ', ' . $req['province'] . ' ' . $req['zip_code']) ?></span></div>
      <div class="review-row"><span class="k">Ownership</span><span class="v"><?= $req['is_owner'] ? 'Registered owner' : 'Filed on behalf of owner' ?></span></div>
    </div>

    <div class="section-title"><?= icon_span('id','15px') ?> Uploaded Files</div>
    <?php foreach ($req['documents'] as $d): ?>
      <div class="review-row"><span class="k"><?= esc($d['label']) ?></span><span class="v">
        <?php if ($d['stored_path']): ?><a href="/<?= esc($d['stored_path']) ?>" target="_blank" rel="noopener"><?= esc($d['original_name']) ?></a><?php else: ?>&mdash;<?php endif; ?>
      </span></div>
    <?php endforeach; ?>

    <?php $showPersonal = false; require __DIR__ . '/../includes/request_view.php'; ?>

    <div class="section-title"><?= icon_span('grid','15px') ?> Staff Actions</div>
    <div class="action-row">
      <?php foreach (array_slice(STATUS_FLOW, 1) as $s): ?>
        <form method="post" action="update_status.php" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
          <input type="hidden" name="status" value="<?= esc($s) ?>">
          <button type="submit" class="action-btn <?= $s === 'Approved' ? 'primary' : ($s === 'Rejected' ? 'danger' : ($s === 'Out for Release' ? 'info' : '')) ?>" <?= $req['status'] === $s ? 'disabled' : '' ?>>Mark <?= esc($s) ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
