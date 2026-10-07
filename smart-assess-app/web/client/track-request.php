<?php
require_once __DIR__ . '/../includes/client_auth.php';

mark_overdue_requests(db());

$query = trim($_GET['ref'] ?? $_GET['q'] ?? '');
$searched = $query !== '';
$req = $searched ? fetch_request_by_reference(strtoupper($query)) : null;

$pageTitle = 'Track Your Request';
require __DIR__ . '/../includes/client_header.php';
?>
<div class="form-shell"><div class="form-col narrow">
  <div class="form-header">
    <h1>Track Your Request</h1>
    <p>Enter your reference number to view live status &mdash; no account needed.</p>
  </div>
  <div class="form-card">
    <div class="form-card-body" style="padding-bottom:24px">
      <form method="get" class="track-form">
        <input type="text" name="ref" placeholder="e.g. DR-2026-00001" value="<?= esc($query) ?>">
        <button type="submit" class="btn btn-primary"><?= icon_span('search') ?> Track</button>
      </form>
      <p class="field-hint">Reference numbers look like DR-2026-00001 (Document Request) or LT-2026-00001 (Land Transfer).</p>

      <?php if ($searched): ?>
        <div style="margin-top:24px;padding-top:22px;border-top:1px solid var(--line)">
          <?php if ($req): ?>
            <?php $showPersonal = true; require __DIR__ . '/../includes/request_view.php'; ?>
          <?php else: ?>
            <p style="color:var(--ink-soft);font-size:14px">No request found with reference number "<?= esc($query) ?>". Double-check the code from your confirmation screen or SMS.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
