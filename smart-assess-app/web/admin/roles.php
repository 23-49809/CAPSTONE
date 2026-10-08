<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['admin']);

$roles = db()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$counts = ['CLIENT' => 0, 'STAFF' => 0, 'ADMIN' => 0, 'DEPARTMENT_HEAD' => 0];
$counts['CLIENT'] = (int) db()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
foreach (db()->query('SELECT role_id, COUNT(*) n FROM users GROUP BY role_id') as $row) {
    $name = match ((int) $row['role_id']) { 2 => 'STAFF', 3 => 'ADMIN', 4 => 'DEPARTMENT_HEAD', default => null };
    if ($name) $counts[$name] = (int) $row['n'];
}
// Pulled live from permissions/role_permissions — not a hardcoded string —
// so this page reflects the actual data-backed grant, not prose that could
// drift from it. See includes/auth.php's user_can() for the runtime check.
$permissionsByRole = [];
foreach (db()->query(
    "SELECT rp.role_id, p.description FROM role_permissions rp
     JOIN permissions p ON p.id = rp.permission_id
     ORDER BY rp.role_id, p.description"
) as $row) {
    $permissionsByRole[(int) $row['role_id']][] = $row['description'];
}

$pageTitle = 'Roles';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Roles</h1><p>The four fixed roles in SMART ASSESS and what each can access. Reassign an account's role from Manage Users.</p></div></div>
  <div class="wrap">
    <div class="table-wrap">
      <table>
        <thead><tr><th>ID</th><th>Role</th><th>Accounts</th><th>What this role can access</th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
          <tr>
            <td class="mono"><?= (int)$r['id'] ?></td>
            <td><strong><?= esc($r['name']) ?></strong></td>
            <td class="mono"><?= $counts[$r['name']] ?? 0 ?></td>
            <td style="font-size:13px;color:var(--ink-soft)">
              <?php if (!empty($permissionsByRole[(int) $r['id']])): ?>
                <ul style="margin:0;padding-left:16px">
                  <?php foreach ($permissionsByRole[(int) $r['id']] as $desc): ?><li><?= esc($desc) ?></li><?php endforeach; ?>
                </ul>
              <?php else: ?>
                Public/resident accounts &mdash; access to the public client portal only (document requests, land transfers, my requests, profile). No internal route access.
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="field-hint" style="margin-top:14px">Roles themselves are fixed by design (see <span class="mono">database/schema.sql</span>) — this page is a reference view. To change what role an account has, use <a href="/admin/users.php">Manage Users</a>.</p>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
