<?php
/**
 * Renders the shared "request detail" blocks: AI checklist, timeline, and
 * simulated SMS log. Expects $req (from fetch_request_by_id/_reference)
 * and optional $showPersonal (bool, default true).
 */
$showPersonal = $showPersonal ?? true;
$missingLabels = array_map(fn($d) => $d['label'], array_filter($req['documents'], fn($d) => $d['file_status'] !== 'ok'));
?>
<div class="check-banner <?= $req['requirement_complete'] ? 'ok' : 'bad' ?>">
  <?= icon($req['requirement_complete'] ? 'check' : 'alert') ?>
  <span>
    AI Rule-Based Requirement Checker: <?= $req['requirement_complete'] ? 'All requirements met' : count($missingLabels) . ' item(s) need attention' ?>
    <?php if (!$req['requirement_complete'] && $missingLabels): ?>
      <span class="sub">Missing/invalid: <?= esc(implode(', ', $missingLabels)) ?></span>
    <?php endif; ?>
  </span>
</div>

<?php if ($showPersonal): ?>
<div class="review-card" style="margin-bottom:16px">
  <div class="review-row"><span class="k">Applicant</span><span class="v"><?= esc(trim($req['first_name'] . ' ' . ($req['middle_name'] ?? '') . ' ' . $req['last_name'])) ?></span></div>
  <div class="review-row"><span class="k">Service</span><span class="v"><?= $req['flow'] === 'docreq' ? 'Document Request' : 'Land Transfer' ?> &mdash; <?= esc($req['document_type'] ?: $req['transfer_type']) ?></span></div>
  <div class="review-row"><span class="k">Purpose</span><span class="v"><?= esc($req['purpose']) ?></span></div>
  <div class="review-row"><span class="k">Barangay</span><span class="v"><?= esc($req['barangay']) ?></span></div>
</div>
<?php endif; ?>

<?php if ($req['advisory']): ?>
  <div class="advisory"><?= icon_span('alert') ?><span><?= esc($req['advisory']) ?></span></div>
<?php endif; ?>

<div class="section-title"><?= icon_span('checklist','15px') ?> Requirement Checklist</div>
<ul class="checklist">
  <?php foreach ($req['documents'] as $d): ?>
    <?php $cls = $d['file_status'] === 'ok' ? 'yes' : ($d['file_status'] === 'flagged' ? 'flag' : 'no'); ?>
    <li><span class="dot <?= $cls ?>"><?= $d['file_status'] === 'ok' ? '✓' : '!' ?></span><?= esc($d['label']) ?><?= $d['file_status'] === 'ok' ? '' : ' — ' . ($d['file_status'] === 'flagged' ? 'invalid format' : 'missing') ?></li>
  <?php endforeach; ?>
</ul>

<div class="section-title"><?= icon_span('timer','15px') ?> Status Timeline</div>
<ul class="timeline">
  <?php foreach ($req['status_log'] as $log): $actor = $log['actor'] ?? 'staff'; ?>
    <li><span class="t-dot"></span><div><div class="t-status"><?= esc($log['status']) ?><?php if ($actor !== 'staff'): ?> <span class="badge slate" style="font-size:10px;padding:2px 7px"><?= $actor === 'system' ? 'auto-detected' : 'by client' ?></span><?php endif; ?></div><div class="t-when"><?= fmt_datetime($log['created_at']) ?></div></div></li>
  <?php endforeach; ?>
</ul>

<div class="section-title"><?= icon_span('sms','15px') ?> SMS Notifications (simulated)</div>
<?php if (empty($req['status_log'])): ?>
  <p style="font-size:12.5px;color:var(--ink-faint)">No messages sent yet.</p>
<?php else: ?>
  <?php foreach (array_reverse($req['status_log']) as $log): ?>
    <div class="sms-item"><?= esc($log['sms_body']) ?><div class="sms-when"><?= fmt_datetime($log['created_at']) ?></div></div>
  <?php endforeach; ?>
<?php endif; ?>
