<?php
require_once __DIR__ . '/../auth/client_auth.php';
$client = current_client();

$flash = null;
$errors = [];
$values = ['name' => $client ? $client['first_name'] . ' ' . $client['last_name'] : '', 'email' => $client['email'] ?? '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors['_csrf'] = 'Your session expired. Please resubmit.';
    } else {
        foreach (['name', 'email', 'message'] as $k) $values[$k] = trim($_POST[$k] ?? '');
        if ($values['name'] === '') $errors['name'] = 'Name is required.';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if ($values['message'] === '') $errors['message'] = 'Please describe how we can help.';

        if (!$errors) {
            db()->prepare('INSERT INTO help_messages (client_id, name, email, message) VALUES (?,?,?,?)')
                ->execute([$client['id'] ?? null, $values['name'], $values['email'], $values['message']]);
            $flash = "Thanks — we've received your message and will get back to you by email or phone.";
            $values['message'] = '';
        }
    }
}

$pageTitle = 'Help & Support';
require __DIR__ . '/../includes/client_header.php';
?>
<div class="form-shell"><div class="form-col narrow">
  <div class="form-header"><h1>Help &amp; Support</h1><p>Send us a message and Assessor's Staff will follow up &mdash; or call us directly at (043) 487-0123.</p></div>
  <div class="form-card">
    <div class="form-card-body" style="padding-bottom:26px">
      <?php if ($flash): ?><div class="flash success"><?= icon_span('check') ?> <?= esc($flash) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="form-errors"><?= icon_span('alert') ?> Please fix the following:<ul><?php foreach ($errors as $m): ?><li><?= esc($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Your Name</label><input type="text" name="name" value="<?= esc($values['name']) ?>"></div>
        <div class="field"><label>Email Address</label><input type="text" name="email" value="<?= esc($values['email']) ?>"></div>
        <div class="field"><label>How can we help?</label><textarea name="message" rows="5"><?= esc($values['message']) ?></textarea></div>
        <button type="submit" class="btn btn-primary"><?= icon_span('send') ?> Send Message</button>
      </form>
      <p class="field-hint" style="margin-top:18px">This is a message form, not live chat &mdash; expect a reply within one business day during office hours (Mon&ndash;Fri, 8:00 AM&ndash;5:00 PM).</p>
    </div>
  </div>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
