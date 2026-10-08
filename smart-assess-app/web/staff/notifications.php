<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

const NOTIFICATION_CATEGORIES = [
    'General Update', 'Missing Requirement Reminder', 'Appointment / Pickup Schedule',
    'Payment Reminder', 'Courtesy Follow-up', 'Other',
];

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $refNo = trim($_POST['reference_no'] ?? '');
    $category = in_array($_POST['category'] ?? '', NOTIFICATION_CATEGORIES, true) ? $_POST['category'] : 'General Update';
    $message = trim($_POST['message'] ?? '');
    $req = $refNo !== '' ? fetch_request_by_reference($refNo) : null;

    if (!$req) {
        $flash = 'Select a valid request reference.';
        $flashType = 'error';
    } elseif ($message === '') {
        $flash = 'Enter a message to send.';
        $flashType = 'error';
    } else {
        // Manual note, not a status change — mirrors the second half of
        // push_status() without calling it, so this never touches
        // requests.status. Shows up on this same log (unfiltered by actor)
        // and, for registered clients, in their notifications inbox too.
        $body = '[' . $category . '] ' . $message;
        db()->prepare('INSERT INTO request_status_log (request_id, status, actor, sms_body) VALUES (?,?,?,?)')
            ->execute([$req['id'], $req['status'], 'staff', $body]);
        if ($req['client_id']) {
            db()->prepare('INSERT INTO notifications (client_id, request_id, type, message) VALUES (?,?,?,?)')
                ->execute([(int) $req['client_id'], $req['id'], 'manual', $body]);
        }
        audit('staff', $me['id'], $me['name'], "Sent manual notification ($category)", $req['reference_no']);
        $flash = 'Notification sent for ' . $req['reference_no'] . '.';
    }
}

$recentRequests = db()->query(
    'SELECT id, reference_no, first_name, last_name FROM requests ORDER BY created_at DESC LIMIT 200'
)->fetchAll();

$notes = db()->query(
    "SELECT l.status, l.sms_body, l.created_at, r.reference_no, r.first_name, r.last_name, r.contact_number
     FROM request_status_log l JOIN requests r ON r.id = l.request_id
     ORDER BY l.created_at DESC LIMIT 100"
)->fetchAll();

$pageTitle = 'Notifications Sent';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Notifications</h1><p>Send a manual update to a client, and review every SMS status notification the system has sent (most recent 100).</p></div></div>
  <div class="wrap">
    <?php if ($flash): ?><div class="flash <?= esc($flashType) ?>"><?= esc($flash) ?></div><?php endif; ?>
    <div class="panel-card">
      <h3><?= icon_span('send', '16px') ?> Send a Notification</h3>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field-grid">
          <div class="field"><label>Request Reference</label>
            <select name="reference_no" required>
              <option value="">Select a request&hellip;</option>
              <?php foreach ($recentRequests as $r): ?>
                <option value="<?= esc($r['reference_no']) ?>"><?= esc($r['reference_no']) ?> &middot; <?= esc(trim($r['first_name'] . ' ' . $r['last_name'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Category</label>
            <select name="category">
              <?php foreach (NOTIFICATION_CATEGORIES as $c): ?><option value="<?= esc($c) ?>"><?= esc($c) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field" style="margin-bottom:16px"><label>Message</label><textarea name="message" rows="3" placeholder="Type the message the client will see&hellip;" required></textarea></div>
        <button type="submit" class="btn btn-primary"><?= icon_span('send') ?> Send Notification</button>
      </form>
    </div>
    <div class="panel-card">
      <h3><?= icon_span('sms', '16px') ?> Sent Notifications</h3>
      <?php if (!$notes): ?><p style="font-size:13px;color:var(--ink-faint)">No notifications sent yet.</p>
      <?php else: foreach ($notes as $n): ?>
        <div class="sms-item">
          <strong class="mono"><?= esc($n['reference_no']) ?></strong> &middot; <?= esc(trim($n['first_name'] . ' ' . $n['last_name'])) ?> (<?= esc($n['contact_number']) ?>)<br>
          <?= esc($n['sms_body']) ?>
          <div class="sms-when"><?= fmt_datetime($n['created_at']) ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
