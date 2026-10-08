<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

mark_overdue_requests(db());

// Every request together with what the AI Rule-Based Requirement Checker
// flagged for it, so staff can triage without opening each one individually.
$requests = db()->query(
    "SELECT r.*,
        GROUP_CONCAT(CASE WHEN d.file_status <> 'ok' THEN d.label END SEPARATOR ', ') AS flagged_items
     FROM requests r
     LEFT JOIN request_documents d ON d.request_id = r.id
     GROUP BY r.id
     ORDER BY r.requirement_complete ASC, r.created_at DESC"
)->fetchAll();

$pageTitle = 'AI Checker Results';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>AI Rule-Based Requirement Checker Results</h1><p>Automated pass/fail verdict for every request's uploaded documents and IDs, worst first.</p></div></div>
  <div class="wrap">
    <div class="table-wrap">
      <?php if (!$requests): ?>
        <div class="empty-state">No requests yet.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Reference No.</th><th>Applicant</th><th>Result</th><th>Flagged / Missing Items</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td class="mono"><?= esc($r['reference_no']) ?></td>
            <td><?= esc(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
            <td><?php if ($r['requirement_complete']): ?><span class="badge green"><?= icon_span('check','12px') ?> Pass &mdash; Complete</span><?php else: ?><span class="badge amber"><?= icon_span('alert','12px') ?> Fail &mdash; Needs Review</span><?php endif; ?></td>
            <td style="font-size:12.5px;color:var(--ink-soft)"><?= $r['flagged_items'] ? esc($r['flagged_items']) : '&mdash;' ?></td>
            <td><a class="icon-btn" href="/staff/detail.php?id=<?= (int)$r['id'] ?>"><?= icon_span('eye','14px') ?> Review</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
