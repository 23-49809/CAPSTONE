<?php
require_once __DIR__ . '/includes/functions.php';
$today = date('Y-m-d');
$query = db()->prepare("SELECT * FROM announcements WHERE status = 'Published' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) ORDER BY start_date DESC, created_at DESC");
$query->execute([$today, $today]);
$announcements = $query->fetchAll();
$pageTitle = 'Office Announcement';
require __DIR__ . '/includes/client_header.php';
?>
<section class="section"><div class="wrap public-detail-page">
  <div class="section-head"><span class="eyebrow">Important Notice</span><h1>Office Announcements</h1><p>Official updates from the Department Head.</p></div>
  <?php if (!$announcements): ?><div class="panel-card"><h2>No current announcements</h2><p>There are no active office announcements at this time.</p></div>
  <?php else: foreach ($announcements as $announcement): ?>
    <article class="panel-card announcement-detail"><div class="announcement-detail-meta">Announcement date: <?= esc(fmt_date($announcement['start_date'])) ?><?= $announcement['end_date'] ? ' &middot; Event window ends ' . esc(fmt_date($announcement['end_date'])) : '' ?></div><h2><?= esc($announcement['title']) ?></h2><p><?= nl2br(esc($announcement['body'])) ?></p><div class="announcement-detail-note"><strong>Important information for clients</strong><p>Please plan your visit around the dates above and check your request requirements before submitting or claiming documents. Follow any additional instructions provided by the Assessor's Office.</p></div></article>
  <?php endforeach; endif; ?>
  <div class="detail-back"><a class="btn btn-ghost" href="/index.php"><?= icon_span('arrowLeft') ?> Back to Home</a></div>
</div></section>
<?php require __DIR__ . '/includes/site_footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
