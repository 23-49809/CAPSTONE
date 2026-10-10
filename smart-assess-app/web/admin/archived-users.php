<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['admin']);

$pdo = db();
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';
    $uid = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'restore_account' && $uid) {
        $pdo->prepare("UPDATE users SET status = 'Active', archived_at = NULL, archived_by = NULL, restored_at = NOW(), restored_by = ?
                        WHERE id = ? AND archived_at IS NOT NULL AND deleted_at IS NULL")
            ->execute([$me['id'], $uid]);
        audit('admin', $me['id'], $me['name'], 'Restored staff account', "user #$uid");
        $flash = 'Account restored to Active.';
    } elseif ($action === 'delete_account' && $uid) {
        // Soft marker, not a real row removal — see the archiving migration
        // comment for why: a hard DELETE would cascade-destroy this
        // person's activity_logs history via its foreign key. The row (and
        // the audit trail that references it) stays; the account is gone
        // from every list in the UI and can never log in or be restored.
        $pdo->prepare("UPDATE users SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND archived_at IS NOT NULL AND deleted_at IS NULL")
            ->execute([$me['id'], $uid]);
        audit('admin', $me['id'], $me['name'], 'Permanently deleted staff account', "user #$uid");
        $flash = 'Account permanently deleted.';
    }
}

$searchQuery = trim($_GET['q'] ?? '');
$where = 'WHERE u.archived_at IS NOT NULL AND u.deleted_at IS NULL';
$params = [];
if ($searchQuery !== '') {
    $where .= ' AND (u.name LIKE ? OR u.username LIKE ?)';
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$countStmt->execute($params);
$pageInfo = paginate_info((int) $countStmt->fetchColumn(), 10);

$stmt = $pdo->prepare(
    "SELECT u.*, a.name AS archived_by_name
     FROM users u LEFT JOIN users a ON a.id = u.archived_by
     $where
     ORDER BY u.archived_at DESC
     LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}"
);
$stmt->execute($params);
$archived = $stmt->fetchAll();

$pageTitle = 'Archived Accounts';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap">
    <h1>Archived Accounts</h1><p>Accounts archived from Staff Accounts. Restore them to Active, or permanently delete them.</p>
    <div style="margin-top:12px"><a class="btn btn-ghost" href="/admin/users.php"><?= icon_span('arrowRight') ?> Back to Staff Accounts</a></div>
  </div></div>
  <div class="wrap">
    <?php if ($flash): ?><div class="flash <?= esc($flashType) ?>"><?= esc($flash) ?></div><?php endif; ?>
    <div class="toolbar">
      <form method="get" class="search-box">
        <?= icon_span('search') ?>
        <input type="text" name="q" value="<?= esc($searchQuery) ?>" placeholder="Search by name or username&hellip;" onchange="this.form.submit()">
      </form>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Archived</th><th>Archived By</th><th></th></tr></thead>
        <tbody>
        <?php if (!$archived): ?>
          <tr><td colspan="6"><div class="empty-state">No archived accounts<?= $searchQuery !== '' ? ' match &ldquo;' . esc($searchQuery) . '&rdquo;' : '' ?>.</div></td></tr>
        <?php else: foreach ($archived as $a): $roleCode = ROLE_ID_TO_CODE[(int) $a['role_id']] ?? 'staff'; ?>
          <tr>
            <td data-label="Name"><?= esc($a['name']) ?></td>
            <td class="mono" data-label="Username"><?= esc($a['username']) ?></td>
            <td data-label="Role"><?= esc(role_label($roleCode)) ?></td>
            <td class="mono" data-label="Archived"><?= fmt_datetime($a['archived_at']) ?></td>
            <td data-label="Archived By"><?= esc($a['archived_by_name'] ?? '—') ?></td>
            <td data-label="Actions">
              <div class="table-actions">
                <form method="post" onsubmit="return confirm('Restore <?= esc(addslashes($a['name'])) ?> to Active?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="restore_account">
                  <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="action-btn"><?= icon_span('restore', '13px') ?> Restore</button>
                </form>
                <form method="post" onsubmit="return confirm('Permanently delete <?= esc(addslashes($a['name'])) ?>? This cannot be undone — the account will never be able to log in or appear in any list again.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_account">
                  <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="action-btn danger"><?= icon_span('trash', '13px') ?> Permanently Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?= render_pager($pageInfo) ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
