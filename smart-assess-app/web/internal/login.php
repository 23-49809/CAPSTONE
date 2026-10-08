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
<div class="login-shell"><div class="login-card fade-in">
  <div class="login-brand">
    <img src="/assets/seal.png" alt="Bayan ng Mabini seal">
    <div><div class="lb-name">Smart Assess</div><div class="lb-tag">Municipal Assessor&rsquo;s Office &mdash; Internal Portal</div></div>
  </div>
  <h2>Staff &amp; Management Login</h2>
  <p class="sub">For Assessor Admin, Assessor Head, and Assessor Staff accounts only.</p>
  <?php if ($error): ?><div class="login-error"><?= icon_span('alert', '16px') ?><span><?= esc($error) ?></span></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label for="li-user">Username</label><input id="li-user" type="text" name="username" placeholder="e.g. jessica.staff" autocomplete="username" value="<?= esc($usernameValue) ?>" required></div>
    <div class="field"><label for="li-pass">Password</label><div class="password-input-wrap"><input id="li-pass" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button class="password-toggle" type="button" id="loginPasswordToggle" aria-label="Show password" aria-pressed="false"><?= icon_span('eye', '19px') ?></button></div></div>
    <button type="submit" class="btn btn-primary btn-block" style="width:100%"><?= icon_span('check', '18px') ?> Sign In</button>
  </form>
</div></div>
<script>
(function(){
  var btn = document.getElementById('loginPasswordToggle');
  var input = document.getElementById('li-pass');
  if (!btn || !input) return;
  btn.addEventListener('click', function(){
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.setAttribute('aria-pressed', String(!showing));
  });
})();
</script>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
