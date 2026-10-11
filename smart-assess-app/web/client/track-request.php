<?php
require_once __DIR__ . '/../auth/client_auth.php';

mark_overdue_requests(db());

$query = trim($_GET['ref'] ?? $_GET['q'] ?? '');
$searched = $query !== '';
$req = $searched ? fetch_request_by_reference(strtoupper($query)) : null;

$pageTitle = 'Track Your Request';
require __DIR__ . '/../includes/client_header.php';

// The prototype's track view is deliberately minimal — status + a couple of
// identifying rows + timeline, NOT the full checklist/SMS log that
// includes/request_view.php renders for staff and for logged-in clients.
// That also means less personal detail is exposed to an anonymous visitor
// who only has a reference number, which is the safer default for a
// no-login-required page.
$missingLabels = $req ? array_map(fn($d) => $d['label'], array_filter($req['documents'], fn($d) => $d['file_status'] !== 'ok')) : [];
?>
<div class="wizard-shell"><div class="wizard-col narrow">
  <div class="wizard-header">
    <h1>Track Your Request</h1>
    <p>Enter your reference number to view live status &mdash; no account needed.</p>
  </div>
  <div class="track-card">
    <form method="get" class="track-form">
      <input type="text" name="ref" placeholder="e.g. DR-2026-00001" value="<?= esc($query) ?>">
      <button type="submit" class="btn btn-primary"><?= icon_span('search') ?> Track</button>
    </form>
    <p class="field-hint">Reference numbers look like DR-2026-00001 (Document Request) or LT-2026-00001 (Land Transfer).</p>

    <?php if ($searched): ?>
      <div class="track-result">
        <?php if ($req): ?>
          <div class="check-banner <?= $req['requirement_complete'] ? 'ok' : 'bad' ?>">
            <?= icon($req['requirement_complete'] ? 'check' : 'alert') ?>
            <span>Current status: <strong><?= esc($req['status']) ?></strong>
              <?php if (!$req['requirement_complete'] && $missingLabels): ?>
                <span class="sub">Missing/invalid: <?= esc(implode(', ', $missingLabels)) ?></span>
              <?php endif; ?>
            </span>
          </div>
          <div class="review-row"><span class="k">Applicant</span><span class="v"><?= esc(trim($req['first_name'] . ' ' . $req['last_name'])) ?></span></div>
          <div class="review-row"><span class="k">Service</span><span class="v"><?= $req['flow'] === 'docreq' ? 'Document Request' : 'Land Transfer' ?> &mdash; <?= esc($req['document_type'] ?: $req['transfer_type']) ?></span></div>
          <div class="section-title"><?= icon_span('timer','15px') ?> Status Timeline</div>
          <ul class="timeline">
            <?php foreach ($req['status_log'] as $log): ?>
              <li><span class="t-dot"></span><div><div class="t-status"><?= esc($log['status']) ?></div><div class="t-when"><?= fmt_datetime($log['created_at']) ?></div></div></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p style="color:var(--ink-soft);font-size:14px">No request found with reference number "<?= esc($query) ?>". Double-check the tracking number from your confirmation screen.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
