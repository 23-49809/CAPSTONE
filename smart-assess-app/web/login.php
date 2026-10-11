<?php
require_once __DIR__ . '/includes/client_auth.php';

// Client login NEVER redirects anywhere but the client dashboard — there is
// no role selector here, and this file never touches the `users` table.
if (current_client()) {
    header('Location: /client/dashboard.php');
    exit;
}

$error = null;
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $emailValue = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $client = login_client($emailValue, $password);
        if ($client) {
            header('Location: /client/dashboard.php');
            exit;
        }
        $error = 'Incorrect email or password, or the account is inactive.';
    }
}

$pageTitle = 'Log In';
require __DIR__ . '/includes/client_header.php';
?>
<div class="form-shell"><div class="form-col narrow">
  <div class="login-card">
    <h2>Log In</h2>
    <p class="lead">Sign in to view your requests, upload requirements, and get notified on status updates.</p>
    <?php if ($error): ?><div class="flash error"><?= esc($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label>Email Address</label><input type="text" name="email" value="<?= esc($emailValue) ?>"></div>
      <div class="field"><label>Password</label><input type="password" name="password"></div>
      <button type="submit" class="btn btn-primary" style="width:100%"><?= icon_span('login') ?> Log In</button>
    </form>
    <a class="login-back" href="/register.php">Don't have an account? Create one</a>
    <a class="login-back" href="/client/track-request.php">Or track a request without logging in &rarr;</a>
  </div>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
