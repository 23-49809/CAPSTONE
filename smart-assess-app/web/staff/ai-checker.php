<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

mark_overdue_requests(db());

// Every request together with what the AI Rule-Based Requirement Checker
// flagged for it, so staff can triage without opening each one individually.
$searchQuery = trim($_GET['q'] ?? '');
$searchWhere = '';
$searchParams = [];
if ($searchQuery !== '') {
    $searchWhere = ' WHERE (r.reference_no LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?)';
    $like = "%$searchQuery%";
    array_push($searchParams, $like, $like, $like);
}
$countStmt = db()->prepare("SELECT COUNT(*) FROM requests r$searchWhere");
$countStmt->execute($searchParams);
$pageInfo = paginate_info((int) $countStmt->fetchColumn(), 10);

$stmt = db()->prepare(
    "SELECT r.*,
        GROUP_CONCAT(CASE WHEN d.file_status <> 'ok' THEN d.label END SEPARATOR ', ') AS flagged_items
     FROM requests r
     LEFT JOIN request_documents d ON d.request_id = r.id
     $searchWhere
     GROUP BY r.id
     ORDER BY r.requirement_complete ASC, r.created_at DESC
     LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}"
);
$stmt->execute($searchParams);
$requests = $stmt->fetchAll();

$pageTitle = 'AI Checker Results';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>AI Rule-Based Requirement Checker Results</h1><p>Automated pass/fail verdict for every request's uploaded documents and IDs, worst first.</p></div></div>
  <div class="wrap">
    <div class="toolbar">
      <form method="get" class="search-box">
        <?= icon_span('search') ?>
        <input type="text" name="q" value="<?= esc($searchQuery) ?>" placeholder="Search reference or applicant&hellip;" onchange="this.form.submit()">
      </form>
    </div>
    <div class="table-wrap">
      <?php if (!$requests): ?>
        <div class="empty-state">No requests match &ldquo;<?= esc($searchQuery) ?>&rdquo;.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Reference No.</th><th>Applicant</th><th>Result</th><th>Flagged / Missing Items</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td class="mono" data-label="Reference No."><?= esc($r['reference_no']) ?></td>
            <td data-label="Applicant"><?= esc(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
            <td data-label="Result"><?php if ($r['requirement_complete']): ?><span class="badge green"><?= icon_span('check','12px') ?> Pass &mdash; Complete</span><?php else: ?><span class="badge amber"><?= icon_span('alert','12px') ?> Fail &mdash; Needs Review</span><?php endif; ?></td>
            <td data-label="Flagged / Missing" style="font-size:12.5px;color:var(--ink-soft)"><?= $r['flagged_items'] ? esc($r['flagged_items']) : '&mdash;' ?></td>
            <td data-label="Actions"><a class="icon-btn" href="/staff/detail.php?id=<?= (int)$r['id'] ?>"><?= icon_span('eye','14px') ?> Review</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
