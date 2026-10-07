<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['admin']);

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    foreach (['office_name', 'office_phone', 'office_email', 'office_hours'] as $key) {
        set_setting($key, trim($_POST[$key] ?? ''));
    }
    foreach (['docreq_processing_days', 'landtransfer_processing_days'] as $key) {
        $days = max(1, (int) ($_POST[$key] ?? 0));
        set_setting($key, (string) $days);
    }
    audit('admin', $me['id'], $me['name'], 'Updated system settings');
    $flash = 'Settings saved.';
}

$pageTitle = 'System Settings';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>System Settings</h1><p>Office information shown on the public site and used in SMS notification text.</p></div></div>
  <div class="wrap" style="max-width:640px">
    <?php if ($flash): ?><div class="flash success"><?= esc($flash) ?></div><?php endif; ?>
    <form method="post" class="panel-card">
      <?= csrf_field() ?>
      <div class="field" style="margin-bottom:16px"><label>Office Name</label><input type="text" name="office_name" value="<?= esc(get_setting('office_name', OFFICE_NAME)) ?>"></div>
      <div class="field" style="margin-bottom:16px"><label>Phone</label><input type="text" name="office_phone" value="<?= esc(get_setting('office_phone', OFFICE_PHONE)) ?>"></div>
      <div class="field" style="margin-bottom:16px"><label>Email</label><input type="text" name="office_email" value="<?= esc(get_setting('office_email', OFFICE_EMAIL)) ?>"></div>
      <div class="field" style="margin-bottom:20px"><label>Office Hours</label><input type="text" name="office_hours" value="<?= esc(get_setting('office_hours', 'Monday to Friday, 8:00 AM - 5:00 PM')) ?>"></div>
      <div class="field" style="margin-bottom:16px"><label>Document Request &mdash; Expected Processing (days)</label><input type="number" min="1" name="docreq_processing_days" value="<?= esc(get_setting('docreq_processing_days', '5')) ?>"></div>
      <div class="field" style="margin-bottom:20px"><label>Land Transfer &mdash; Expected Processing (days)</label><input type="number" min="1" name="landtransfer_processing_days" value="<?= esc(get_setting('landtransfer_processing_days', '10')) ?>"></div>
      <p class="field-hint" style="margin-bottom:20px">A request still open past this many days (and not yet Approved, Rejected, or Out for Release) is automatically flagged <span class="mono">Timed Out</span> on the Staff and Department Head views.</p>
      <button type="submit" class="btn btn-primary"><?= icon_span('check') ?> Save Settings</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
