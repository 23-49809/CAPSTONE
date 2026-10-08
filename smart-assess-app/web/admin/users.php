<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['admin']);

$pdo = db();
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_account') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $roleCode = in_array($_POST['role'] ?? '', ['admin', 'staff', 'head'], true) ? $_POST['role'] : 'staff';
    $contactNumber = canonical_internal_contact($_POST['contact_number'] ?? '');
    $positionTitle = $_POST['position_title'] ?? '';
    if ($name === '' || $username === '') {
      $flash = 'Full name and username are required.';
      $flashType = 'error';
    } elseif ($contactNumber === null) {
      $flash = 'Contact number must contain exactly 10 digits after +63 and start with 9.';
      $flashType = 'error';
    } elseif (!in_array($positionTitle, INTERNAL_POSITION_OPTIONS, true)) {
      $flash = 'Select a valid Assessor’s Office position.';
      $flashType = 'error';
    } else {
            try {
                $tempPassword = 'Passw0rd!';
                $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO users (role_id, name, username, contact_number, position_title, password_hash, status) VALUES (?,?,?,?,?, ?,"Active")')
          ->execute([ROLE_CODE_TO_ID[$roleCode], $name, $username, $contactNumber, $positionTitle, $hash]);
                $flash = "Account created for $name. Temporary password: $tempPassword";
                audit('admin', $me['id'], $me['name'], "Created $roleCode account", $username);
            } catch (Throwable $e) {
                $flash = 'Could not create account (username may already be taken).';
            }
        }
    } elseif ($action === 'update_account') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $roleCode = in_array($_POST['role'] ?? '', ['admin', 'staff', 'head'], true) ? $_POST['role'] : null;
        $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive'], true) ? $_POST['status'] : null;
        $contactNumber = canonical_internal_contact($_POST['contact_number'] ?? '');
        $positionTitle = $_POST['position_title'] ?? '';
        if (!$uid || !$roleCode || !$status) {
          $flash = 'Choose a valid role and account status.';
          $flashType = 'error';
        } elseif ($contactNumber === null) {
          $flash = 'Contact number must contain exactly 10 digits after +63 and start with 9.';
          $flashType = 'error';
        } elseif (!in_array($positionTitle, INTERNAL_POSITION_OPTIONS, true)) {
          $flash = 'Select a valid Assessor’s Office position.';
          $flashType = 'error';
        } else {
          $pdo->prepare('UPDATE users SET role_id = ?, status = ?, contact_number = ?, position_title = ? WHERE id = ?')
            ->execute([ROLE_CODE_TO_ID[$roleCode], $status, $contactNumber, $positionTitle, $uid]);
          audit('admin', $me['id'], $me['name'], "Set role=$roleCode status=$status position=$positionTitle", "user #$uid");
          $flash = 'Account details saved.';
        }
    } elseif ($action === 'archive_account') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid === (int) $me['id']) {
            $flash = 'You cannot archive your own account while logged in.';
            $flashType = 'error';
        } elseif ($uid) {
            $pdo->prepare("UPDATE users SET status = 'Inactive', archived_at = NOW(), archived_by = ? WHERE id = ? AND archived_at IS NULL AND deleted_at IS NULL")
                ->execute([$me['id'], $uid]);
            audit('admin', $me['id'], $me['name'], 'Archived staff account', "user #$uid");
            $flash = 'Account archived.';
        }
    }
}

$accounts = $pdo->query('SELECT * FROM users WHERE archived_at IS NULL AND deleted_at IS NULL ORDER BY created_at')->fetchAll();
$archivedCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE archived_at IS NOT NULL AND deleted_at IS NULL')->fetchColumn();

$pageTitle = 'Manage Users';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap">
    <h1>User Accounts</h1><p>Create and manage internal accounts for Assessor's Staff, Admin, and Department Head.</p>
    <div style="margin-top:12px"><a class="btn btn-ghost" href="/admin/archived-users.php"><?= icon_span('archive') ?> Archived Accounts<?= $archivedCount ? ' (' . $archivedCount . ')' : '' ?></a></div>
  </div></div>
  <div class="wrap">
    <?php if ($flash): ?><div class="flash <?= esc($flashType) ?>"><?= esc($flash) ?></div><?php endif; ?>
    <form method="post" class="inline-add">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_account">
      <div class="field"><label>Full Name</label><input type="text" name="name" placeholder="Full name" required></div>
      <div class="field"><label>Username</label><input type="text" name="username" placeholder="username" required></div>
      <div class="field"><label for="add-contact-number">Contact Number</label><?= internal_contact_control(null, null, 'add-contact-number') ?></div>
      <div class="field"><label>Position Title</label><?= internal_position_control() ?></div>
      <div class="field"><label>Role</label>
        <select name="role" required><option value="staff">Assessor's Staff</option><option value="admin">Admin</option><option value="head">Department Head</option></select>
      </div>
      <button type="submit" class="btn btn-primary"><?= icon_span('users') ?> Add Account</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Username</th><th>Contact Number</th><th>Position Title</th><th colspan="2">Role (Level of Access) &amp; Status</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): $roleCode = ROLE_ID_TO_CODE[(int)$a['role_id']] ?? 'staff'; $editFormId = 'edit-user-' . (int) $a['id']; ?>
          <tr>
            <td><?= esc($a['name']) ?></td>
            <td class="mono"><?= esc($a['username']) ?></td>
            <td><?= internal_contact_control($a['contact_number'] ?? null, $editFormId, 'contact-' . (int) $a['id']) ?></td>
            <td><?= internal_position_control($a['position_title'] ?? null, $editFormId) ?></td>
            <td colspan="2">
              <form id="<?= esc($editFormId) ?>" method="post" class="user-row-edit">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_account">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <select name="role" class="mono" required>
                  <option value="staff" <?= $roleCode === 'staff' ? 'selected' : '' ?>>Assessor's Staff</option>
                  <option value="admin" <?= $roleCode === 'admin' ? 'selected' : '' ?>>Admin</option>
                  <option value="head" <?= $roleCode === 'head' ? 'selected' : '' ?>>Department Head</option>
                </select>
                <select name="status" required>
                  <option value="Active" <?= $a['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                  <option value="Inactive" <?= $a['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit" class="icon-btn">Save</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function(){
  var message = 'Contact number must contain exactly 10 digits after +63 and start with 9.';
  document.querySelectorAll('[data-contact-number]').forEach(function(input){
    var field = input.parentElement.parentElement;
    var error = field.querySelector('[data-contact-error]');
    function update(showError){
      var digits = input.value.replace(/\D/g, '');
      if (digits.length > 10 && digits.slice(0, 2) === '63') digits = digits.slice(2);
      else if (digits.length > 10 && digits.charAt(0) === '0') digits = digits.slice(1);
      input.value = digits.slice(0, 10);
      var valid = /^9\d{9}$/.test(input.value);
      input.setCustomValidity(valid ? '' : message);
      input.setAttribute('aria-invalid', String(!valid && showError));
      if (error) error.hidden = valid || !showError;
    }
    input.addEventListener('input', function(){ update(true); });
    input.addEventListener('blur', function(){ update(true); });
    update(false);
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
