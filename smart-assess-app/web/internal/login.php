<?php
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    header('Location: ' . internal_dashboard_path(current_user()['role']));
    exit;
}

$error = null;
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $usernameValue = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $user = login_user($usernameValue, $password);
        if ($user) {
            // Role is determined by the DB row that authenticated, not by
            // anything the client submitted — staff/admin/head each land
            // only on their own dashboard, automatically.
            header('Location: ' . internal_dashboard_path($user['role']));
            exit;
        }
        $error = 'Incorrect username or password, or the account is inactive.';
    }
}

$pageTitle = 'Internal Portal Log In';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="form-shell"><div class="form-col narrow">
  <div class="login-card">
    <h2>Municipal Assessor's Office</h2>
    <p class="lead">Internal Portal &mdash; for Assessor's Staff, Admin, and Department Head only.</p>
    <?php if ($error): ?><div class="flash error"><?= esc($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label>Username</label><input type="text" name="username" value="<?= esc($usernameValue) ?>"></div>
      <div class="field"><label>Password</label><input type="password" name="password"></div>
      <button type="submit" class="btn btn-primary" style="width:100%"><?= icon_span('login') ?> Log In</button>
    </form>
    <div class="login-note">Demo accounts (seeded by database/schema.sql), password for all: <strong>Passw0rd!</strong><br>
      <span class="mono">maricar.admin</span> (Admin) &middot; <span class="mono">jessica.staff</span> (Staff) &middot; <span class="mono">rodel.head</span> (Dept. Head)</div>
  </div>
</div></div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
