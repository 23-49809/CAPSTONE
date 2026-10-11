<?php
require_once __DIR__ . '/../auth/auth.php';
$me = require_role(['admin']);

$actorFilter = in_array($_GET['actor'] ?? '', ['client', 'staff', 'admin', 'head', 'system'], true) ? $_GET['actor'] : '';
$searchQuery = trim($_GET['q'] ?? '');

$conditions = [];
$params = [];
if ($actorFilter) {
    $conditions[] = 'actor_type = ?';
    $params[] = $actorFilter;
}
if ($searchQuery !== '') {
    // Searches actor name, action, and target (the free-text fields); if the
    // query is purely numeric it also matches actor_id exactly, since that's
    // the one other identifier this table carries (e.g. "user #7" shows as
    // target text already, but searching "7" alone should still find it).
    $like = "%$searchQuery%";
    if (ctype_digit($searchQuery)) {
        $conditions[] = '(actor_name LIKE ? OR action LIKE ? OR target LIKE ? OR actor_id = ?)';
        array_push($params, $like, $like, $like, (int) $searchQuery);
    } else {
        $conditions[] = '(actor_name LIKE ? OR action LIKE ? OR target LIKE ?)';
        array_push($params, $like, $like, $like);
    }
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = db()->prepare('SELECT COUNT(*) FROM audit_log' . $where);
$countStmt->execute($params);
$pageInfo = paginate_info((int) $countStmt->fetchColumn(), 25);

$stmt = db()->prepare('SELECT * FROM audit_log' . $where . ' ORDER BY created_at DESC LIMIT ' . $pageInfo['perPage'] . ' OFFSET ' . $pageInfo['offset']);
$stmt->execute($params);
$logs = $stmt->fetchAll();

function qs(array $overrides): string
{
    $base = ['actor' => $_GET['actor'] ?? '', 'q' => $_GET['q'] ?? ''];
    return '?' . http_build_query(array_merge($base, $overrides));
}

$pageTitle = 'Audit Logs';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Audit Logs</h1><p>Logins, status changes, and account/role changes across both portals.</p></div></div>
  <div class="wrap">
    <div class="toolbar">
      <form method="get" class="search-box">
        <?= icon_span('search') ?>
        <input type="text" name="q" value="<?= esc($searchQuery) ?>" placeholder="Search actor, action, or target&hellip;" onchange="this.form.submit()">
        <input type="hidden" name="actor" value="<?= esc($actorFilter) ?>">
      </form>
      <div class="seg">
        <a class="<?= $actorFilter === '' ? 'active' : '' ?>" href="<?= qs(['actor' => '']) ?>">All</a>
        <a class="<?= $actorFilter === 'client' ? 'active' : '' ?>" href="<?= qs(['actor' => 'client']) ?>">Client</a>
        <a class="<?= $actorFilter === 'staff' ? 'active' : '' ?>" href="<?= qs(['actor' => 'staff']) ?>">Staff</a>
        <a class="<?= $actorFilter === 'admin' ? 'active' : '' ?>" href="<?= qs(['actor' => 'admin']) ?>">Admin</a>
        <a class="<?= $actorFilter === 'head' ? 'active' : '' ?>" href="<?= qs(['actor' => 'head']) ?>">Dept. Head</a>
      </div>
    </div>
    <div class="table-wrap">
      <?php if (!$logs): ?><div class="empty-state">No audit events<?= $searchQuery !== '' ? ' match &ldquo;' . esc($searchQuery) . '&rdquo;' : ' yet' ?>.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>When</th><th>Actor</th><th>Type</th><th>Action</th><th>Target</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="mono" data-label="When"><?= fmt_datetime($l['created_at']) ?></td>
            <td data-label="Actor"><?= esc($l['actor_name']) ?></td>
            <td data-label="Type"><span class="badge slate"><?= esc(ucfirst($l['actor_type'])) ?></span></td>
            <td data-label="Action"><?= esc($l['action']) ?></td>
            <td class="mono" style="font-size:12px" data-label="Target"><?= esc($l['target'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?= render_pager($pageInfo) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
