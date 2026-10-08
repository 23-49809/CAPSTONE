<?php
/**
 * Shared body for the internal self-service Profile page (Admin/Staff/
 * Department Head) — built from client/profile.php's exact pattern
 * (update_profile + change_password actions, CSRF, audit() calls),
 * targeting `users` instead of `clients`. Required by admin/profile.php,
 * staff/profile.php, department-head/profile.php after each has already
 * called require_role() for its own role — $me is that authenticated row.
 *
 * Unlike clients, internal accounts have no email column and don't
 * self-edit role/position/status here — those stay admin-only, via
 * admin/users.php. This page only lets someone update their own name/
 * contact number and change their own password.
 */
$flash = null;
$errors = [];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $contact = canonical_internal_contact($_POST['contact_number'] ?? '');
        if ($name === '') $errors['name'] = 'Full name is required.';
        if ($contact === null) $errors['contact_number'] = 'Contact number must contain exactly 10 digits after +63 and start with 9.';

        if (!$errors) {
            $pdo->prepare('UPDATE users SET name = ?, contact_number = ? WHERE id = ?')
                ->execute([$name, $contact, $me['id']]);
            $_SESSION['internal_user']['name'] = $name;
            $me = current_user();
            $flash = 'Profile updated.';
            audit($me['role'], $me['id'], $me['name'], 'Updated profile');
        }
    } elseif ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $row->execute([$me['id']]);
        $hash = $row->fetchColumn();

        if (!password_verify($current, $hash)) $errors['current_password'] = 'Current password is incorrect.';
        elseif (strlen($new) < 8) $errors['new_password'] = 'New password must be at least 8 characters.';
        elseif ($new !== $confirm) $errors['new_password_confirm'] = 'Passwords do not match.';

        if (!$errors) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT), $me['id']]);
            $flash = 'Password changed.';
            audit($me['role'], $me['id'], $me['name'], 'Changed password');
        }
    }
}

$full = $pdo->prepare('SELECT username, position_title, status, created_at FROM users WHERE id = ?');
$full->execute([$me['id']]);
$full = $full->fetch();

$pageTitle = 'My Profile';
require __DIR__ . '/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>My Profile</h1><p>Update your name or contact number, or change your password.</p></div></div>
  <div class="wrap">
    <?php if ($flash): ?><div class="flash success"><?= esc($flash) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="form-errors"><?= icon_span('alert') ?> Please fix the following:<ul><?php foreach ($errors as $m): ?><li><?= esc($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="two-col">
      <div class="panel-card">
        <h3><?= icon_span('user', '16px') ?> Account Details</h3>
        <form method="post" style="margin-bottom:32px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile">
          <div class="field" style="margin-bottom:16px"><label>Full Name</label><input type="text" name="name" value="<?= esc($me['name']) ?>"></div>
          <div class="field" style="margin-bottom:16px"><label for="profile-contact-number">Contact Number</label><?= internal_contact_control($me['contact_number'] ?? null, null, 'profile-contact-number') ?></div>
          <button type="submit" class="btn btn-primary"><?= icon_span('check') ?> Save Changes</button>
        </form>

        <div class="section-divider"><?= icon_span('lock', '15px') ?><span>Change Password</span></div>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="field" style="margin-bottom:16px"><label>Current Password</label><input type="password" name="current_password"></div>
          <div class="field-grid">
            <div class="field"><label>New Password</label><input type="password" name="new_password"></div>
            <div class="field"><label>Confirm New Password</label><input type="password" name="new_password_confirm"></div>
          </div>
          <button type="submit" class="btn btn-ghost" style="margin-top:16px"><?= icon_span('key') ?> Change Password</button>
        </form>
      </div>

      <div class="panel-card">
        <h3><?= icon_span('idCard', '16px') ?> Role Information</h3>
        <div class="review-card">
          <div class="review-row"><span class="k">Username</span><span class="v mono"><?= esc($full['username']) ?></span></div>
          <div class="review-row"><span class="k">Position</span><span class="v"><?= esc($full['position_title'] ?: '—') ?></span></div>
          <div class="review-row"><span class="k">Role</span><span class="v"><?= esc(role_label($me['role'])) ?></span></div>
          <div class="review-row"><span class="k">Account Status</span><span class="v"><span class="badge <?= $full['status'] === 'Active' ? 'green' : 'slate' ?>"><?= esc($full['status']) ?></span></span></div>
          <div class="review-row"><span class="k">Date Created</span><span class="v mono"><?= fmt_date($full['created_at']) ?></span></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/internal_footer.php'; ?>
