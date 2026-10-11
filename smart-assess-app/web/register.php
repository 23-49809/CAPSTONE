<?php
require_once __DIR__ . '/auth/client_auth.php';

if (current_client()) {
    header('Location: /client/dashboard.php');
    exit;
}

$errors = [];
$values = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors['_csrf'] = 'Your session expired. Please try again.';
    }
    foreach ($values as $k => $v) $values[$k] = trim($_POST[$k] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($values['first_name'] === '') $errors['first_name'] = 'First name is required.';
    if ($values['last_name'] === '') $errors['last_name'] = 'Last name is required.';
    if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($values['contact_number'] !== '' && !preg_match('/^09\d{2}-\d{3}-\d{4}$/', $values['contact_number'])) $errors['contact_number'] = 'Use format 09XX-XXX-XXXX.';
    if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors['password_confirm'] = 'Passwords do not match.';

    if (empty($errors)) {
        $result = register_client($values['first_name'], $values['last_name'], $values['email'], $values['contact_number'], $password);
        if ($result['ok']) {
            header('Location: /client/dashboard.php');
            exit;
        }
        $errors['_email'] = $result['error'];
    }
}

$pageTitle = 'Create Account';
require __DIR__ . '/includes/client_header.php';
?>
<div class="form-shell"><div class="form-col narrow">
  <div class="login-card">
    <h2>Create Your Account</h2>
    <p class="lead">Register to track all your requests in one place. You can still submit and track requests without an account, too.</p>
    <?php if ($errors): ?><div class="flash error"><?= icon_span('alert') ?> Please fix the following:<ul style="margin:8px 0 0 20px"><?php foreach ($errors as $m): ?><li><?= esc($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="field-grid">
        <div class="field"><label>First Name</label><input type="text" name="first_name" value="<?= esc($values['first_name']) ?>"></div>
        <div class="field"><label>Last Name</label><input type="text" name="last_name" value="<?= esc($values['last_name']) ?>"></div>
      </div>
      <div class="field"><label>Email Address</label><input type="text" name="email" value="<?= esc($values['email']) ?>"></div>
      <div class="field"><label>Contact Number (optional)</label><input type="text" name="contact_number" placeholder="09XX-XXX-XXXX" value="<?= esc($values['contact_number']) ?>"></div>
      <div class="field-grid">
        <div class="field"><label>Password</label><input type="password" name="password"></div>
        <div class="field"><label>Confirm Password</label><input type="password" name="password_confirm"></div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%"><?= icon_span('check') ?> Create Account</button>
    </form>
    <a class="login-back" href="/login.php">Already have an account? Log in</a>
  </div>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
