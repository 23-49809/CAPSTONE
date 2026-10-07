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
    }
}

$accounts = $pdo->query('SELECT * FROM users ORDER BY created_at')->fetchAll();

$pageTitle = 'Manage Users';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>User Accounts</h1><p>Create and manage internal accounts for Assessor's Staff, Admin, and Department Head.</p></div></div>
  <div class="wrap">
    <?php if ($flash): ?><div class="flash <?= esc($flashType) ?>"><?= esc($flash) ?></div><?php endif; ?>
    <form method="post" class="inline-add">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_account">
      <div class="field"><label>Full Name</label><input type="text" name="name" placeholder="Full name" required></div>
      <div class="field"><label>Username</label><input type="text" name="username" placeholder="username" required></div>
      <div class="field"><label>Contact Number</label><?= internal_contact_control() ?></div>
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
        <?php foreach ($accounts as $a): $roleCode = ROLE_ID_TO_CODE[(int)$a['role_id']] ?? 'staff'; ?>
          <tr>
            <td><?= esc($a['name']) ?></td>
            <td class="mono"><?= esc($a['username']) ?></td>
            <td><?= esc(format_internal_contact($a['contact_number'] ?? null)) ?></td>
            <td><?= esc($a['position_title'] ?? '—') ?></td>
            <td colspan="2">
              <?php $editFormId = 'edit-user-' . (int) $a['id']; ?>
              <form id="<?= esc($editFormId) ?>" method="post" class="user-row-edit">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_account">
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <select name="role" class="mono">
                  <option value="staff" <?= $roleCode === 'staff' ? 'selected' : '' ?>>Assessor's Staff</option>
                  <option value="admin" <?= $roleCode === 'admin' ? 'selected' : '' ?>>Admin</option>
                  <option value="head" <?= $roleCode === 'head' ? 'selected' : '' ?>>Department Head</option>
                </select>
                <select name="status">
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
