<?php
require_once __DIR__ . '/../auth/client_auth.php';
$client = require_client();

$flash = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        if ($firstName === '') $errors['first_name'] = 'First name is required.';
        if ($lastName === '') $errors['last_name'] = 'Last name is required.';
        if ($contact !== '' && !preg_match('/^09\d{2}-\d{3}-\d{4}$/', $contact)) $errors['contact_number'] = 'Use format 09XX-XXX-XXXX.';

        if (!$errors) {
            $pdo->prepare('UPDATE clients SET first_name = ?, last_name = ?, contact_number = ? WHERE id = ?')
                ->execute([$firstName, $lastName, $contact ?: null, $client['id']]);
            $_SESSION['client']['first_name'] = $firstName;
            $_SESSION['client']['last_name'] = $lastName;
            $_SESSION['client']['contact_number'] = $contact;
            $client = current_client();
            $flash = 'Profile updated.';
            audit('client', $client['id'], $firstName . ' ' . $lastName, 'Updated profile');
        }
    } elseif ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $row = $pdo->prepare('SELECT password_hash FROM clients WHERE id = ?');
        $row->execute([$client['id']]);
        $hash = $row->fetchColumn();

        if (!password_verify($current, $hash)) $errors['current_password'] = 'Current password is incorrect.';
        elseif (strlen($new) < 8) $errors['new_password'] = 'New password must be at least 8 characters.';
        elseif ($new !== $confirm) $errors['new_password_confirm'] = 'Passwords do not match.';

        if (!$errors) {
            $pdo->prepare('UPDATE clients SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT), $client['id']]);
            $flash = 'Password changed.';
            audit('client', $client['id'], $client['first_name'] . ' ' . $client['last_name'], 'Changed password');
        }
    }
}

$pageTitle = 'My Profile';
require __DIR__ . '/../includes/client_header.php';
?>
<div class="form-shell"><div class="form-col narrow">
  <div class="form-header"><h1>My Profile</h1><p>Update your contact details or change your password.</p></div>
  <div class="form-card">
    <div class="form-card-body" style="padding-bottom:26px">
      <?php if ($flash): ?><div class="flash success"><?= esc($flash) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="form-errors"><?= icon_span('alert') ?> Please fix the following:<ul><?php foreach ($errors as $m): ?><li><?= esc($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

      <form method="post" style="margin-bottom:32px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="field-grid">
          <div class="field"><label>First Name</label><input type="text" name="first_name" value="<?= esc($client['first_name']) ?>"></div>
          <div class="field"><label>Last Name</label><input type="text" name="last_name" value="<?= esc($client['last_name']) ?>"></div>
        </div>
        <div class="field"><label>Email Address</label><input type="text" value="<?= esc($client['email']) ?>" disabled></div>
        <div class="field"><label>Contact Number</label><input type="text" name="contact_number" placeholder="09XX-XXX-XXXX" value="<?= esc($client['contact_number'] ?? '') ?>"></div>
        <button type="submit" class="btn btn-primary"><?= icon_span('check') ?> Save Profile</button>
      </form>

      <div class="section-divider"><?= icon_span('id','15px') ?><span>Change Password</span></div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="field"><label>Current Password</label><input type="password" name="current_password"></div>
        <div class="field-grid">
          <div class="field"><label>New Password</label><input type="password" name="new_password"></div>
          <div class="field"><label>Confirm New Password</label><input type="password" name="new_password_confirm"></div>
        </div>
        <button type="submit" class="btn btn-ghost"><?= icon_span('key') ?> Change Password</button>
      </form>
    </div>
  </div>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
