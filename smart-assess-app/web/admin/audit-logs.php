<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['admin']);

$actorFilter = in_array($_GET['actor'] ?? '', ['client', 'staff', 'admin', 'head', 'system'], true) ? $_GET['actor'] : '';
$where = $actorFilter ? ' WHERE actor_type = ?' : '';
$params = $actorFilter ? [$actorFilter] : [];

$countStmt = db()->prepare('SELECT COUNT(*) FROM audit_log' . $where);
$countStmt->execute($params);
$pageInfo = paginate_info((int) $countStmt->fetchColumn(), 25);

$stmt = db()->prepare('SELECT * FROM audit_log' . $where . ' ORDER BY created_at DESC LIMIT ' . $pageInfo['perPage'] . ' OFFSET ' . $pageInfo['offset']);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Logs';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Audit Logs</h1><p>Logins, status changes, and account/role changes across both portals (most recent 300).</p></div></div>
  <div class="wrap">
    <div class="toolbar">
      <div class="seg">
        <a class="<?= $actorFilter === '' ? 'active' : '' ?>" href="?actor=">All</a>
        <a class="<?= $actorFilter === 'client' ? 'active' : '' ?>" href="?actor=client">Client</a>
        <a class="<?= $actorFilter === 'staff' ? 'active' : '' ?>" href="?actor=staff">Staff</a>
        <a class="<?= $actorFilter === 'admin' ? 'active' : '' ?>" href="?actor=admin">Admin</a>
        <a class="<?= $actorFilter === 'head' ? 'active' : '' ?>" href="?actor=head">Dept. Head</a>
      </div>
    </div>
    <div class="table-wrap">
      <?php if (!$logs): ?><div class="empty-state">No audit events yet.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>When</th><th>Actor</th><th>Type</th><th>Action</th><th>Target</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="mono"><?= fmt_datetime($l['created_at']) ?></td>
            <td><?= esc($l['actor_name']) ?></td>
            <td><span class="badge slate"><?= esc(ucfirst($l['actor_type'])) ?></span></td>
            <td><?= esc($l['action']) ?></td>
            <td class="mono" style="font-size:12px"><?= esc($l['target'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
