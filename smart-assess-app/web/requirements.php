<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Requirements';
require __DIR__ . '/includes/client_header.php';
?>
<section class="section"><div class="wrap public-detail-page">
  <div class="section-head"><span class="eyebrow">Public Service Guide</span><h1>Service Requirements</h1><p>Review the general requirements before starting your request.</p></div>
  <div class="requirements-grid">
    <article class="panel-card" id="document-requirements"><h2>Document Request Requirements</h2><ul class="public-requirements-list"><li>Valid government-issued ID</li><li>Completed request details</li><li>Property or tax declaration information</li><li>Authorization letter and representative ID, if filing for the owner</li></ul><a class="btn btn-primary" href="/client/document-request.php">Start Document Request <?= icon_span('arrowRight') ?></a></article>
    <article class="panel-card" id="land-transfer-requirements"><h2>Land Transfer Requirements</h2><ul class="public-requirements-list"><li>Certified True Copy of Tax Declaration or Title</li><li>Notarial Deed of Sale or Donation</li><li>Vicinity Map</li><li>Certification of No Improvement</li><li>Tax Clearance</li><li>Valid government-issued ID</li></ul><a class="btn btn-primary" href="/client/land-transfer.php">Start Land Transfer <?= icon_span('arrowRight') ?></a></article>
  </div>
  <div class="detail-back"><a class="btn btn-ghost" href="/index.php"><?= icon_span('arrowLeft') ?> Back to Home</a></div>
</div></section>
<?php require __DIR__ . '/includes/site_footer.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
