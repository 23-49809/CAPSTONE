<?php
require_once __DIR__ . '/includes/functions.php';
$pdo = db();
$today = date('Y-m-d');
$announcementQuery = $pdo->prepare("SELECT * FROM announcements WHERE status = 'Published' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) ORDER BY start_date DESC, created_at DESC");
$announcementQuery->execute([$today, $today]);
$announcements = $announcementQuery->fetchAll();
$pageTitle = 'Home';
require __DIR__ . '/includes/client_header.php';
?>
<section class="hero hero-slider" data-hero-slider aria-label="Assessor Office updates">
  <div class="hero-slides">
    <article class="hero-slide is-active"><div class="wrap"><div class="hero-inner hero-slide-copy">
      <span class="eyebrow">Public Service Guide</span><h1>Document Request</h1>
      <p>Prepare the required documents before submitting your request.</p>
      <p class="hero-requirements">Valid government-issued ID, completed request details, and property or authorization documents when applicable.</p>
      <button class="btn btn-hero" type="button" data-requirements="document">View Requirements <?= icon_span('arrowRight') ?></button>
    </div></div></article>
    <article class="hero-slide"><div class="wrap"><div class="hero-inner hero-slide-copy">
      <span class="eyebrow">Property Transaction Guide</span><h1>Land Transfer</h1>
      <p>Make sure you have all required documents before processing your land transfer.</p>
      <p class="hero-requirements">Certified title or tax declaration, notarized deed, vicinity map, certifications, tax clearance, and valid ID.</p>
      <button class="btn btn-hero" type="button" data-requirements="land">View Requirements <?= icon_span('arrowRight') ?></button>
    </div></div></article>
    <article class="hero-slide<?= !$announcements ? ' no-announcement' : '' ?>"><div class="wrap"><div class="hero-inner hero-slide-copy">
      <span class="eyebrow">Important Notice</span><h1>Office Announcement</h1>
      <?php if ($announcements): foreach ($announcements as $announcement): ?>
        <div class="hero-announcement"><h2><?= esc($announcement['title']) ?></h2><p><?= esc($announcement['body']) ?></p><time datetime="<?= esc($announcement['start_date']) ?>">Posted <?= esc(fmt_date($announcement['start_date'])) ?></time></div>
      <?php endforeach; ?><a class="btn btn-hero" href="/announcement.php">View Announcement <?= icon_span('arrowRight') ?></a><?php else: ?><p>No current announcements.</p><?php endif; ?>
    </div></div></article>
  </div>
  <button class="hero-slider-control prev" type="button" data-slider-prev aria-label="Previous slide"><?= icon_span('arrowLeft') ?></button>
  <button class="hero-slider-control next" type="button" data-slider-next aria-label="Next slide"><?= icon_span('arrowRight') ?></button>
  <div class="hero-slider-dots" role="tablist" aria-label="Hero slides"><button class="is-active" type="button" data-slide-to="0" aria-label="Document request slide"></button><button type="button" data-slide-to="1" aria-label="Land transfer slide"></button><button type="button" data-slide-to="2" aria-label="Announcement slide"></button></div>
</section>

<section class="requirements-strip" aria-label="General requirements">
  <div class="wrap requirements-grid">
    <details id="document-requirements"><summary>Document Request Requirements</summary><p>Prepare a valid government-issued ID, the accomplished request form, and any supporting property or authorization documents requested for your selected document.</p><a class="btn-link" href="/client/document-request.php">Start Document Request <?= icon_span('arrowRight','15px') ?></a></details>
    <details id="land-transfer-requirements"><summary>Land Transfer Requirements</summary><p>Prepare the certified title or tax declaration, notarized deed, vicinity map, certification documents, and tax clearance before submitting.</p><a class="btn-link" href="/client/land-transfer.php">Start Land Transfer <?= icon_span('arrowRight','15px') ?></a></details>
  </div>
</section>
<section class="section" id="services"><div class="wrap">
  <div class="section-head"><h2>How Can We Help You?</h2>
    <p>Choose the service that best matches your needs. We offer two main pathways to access municipal property assessment services:</p></div>
  <div class="service-grid">
    <div class="service-card"><div class="chip-icon"><?= icon('doc') ?></div>
      <h3>Start Document Request</h3>
      <p>Request for Certified True Copies of Tax Declarations, Certifications of Land Holdings, and more.</p>
      <a class="btn-link" href="/client/document-request.php">Begin Application <?= icon_span('arrowRight','15px') ?></a></div>
    <div class="service-card"><div class="chip-icon"><?= icon('swap') ?></div>
      <h3>Land Transfer</h3>
      <p>Submit requirements for property transfer, consolidation, or subdivision of land records.</p>
      <a class="btn-link" href="/client/land-transfer.php">Land Transfer <?= icon_span('arrowRight','15px') ?></a></div>
  </div>
</div></section>

<section class="section section-alt"><div class="wrap">
  <div class="section-head"><h2>Why Choose SMART ASSESS?</h2>
    <p>Experience the future of municipal services with our digital platform designed for efficiency, transparency, and accessibility.</p></div>
  <div class="feature-row">
    <div><div class="chip-icon"><?= icon('shield') ?></div><h4>Secure &amp; Reliable</h4><p>Your data is protected with enterprise-grade security. All transactions are encrypted and monitored.</p></div>
    <div><div class="chip-icon"><?= icon('clock') ?></div><h4>Fast Processing</h4><p>Streamlined workflows ensure your requests are processed quickly with real-time status updates.</p></div>
    <div><div class="chip-icon"><?= icon('headset') ?></div><h4>24/7 Support</h4><p>Access our services anytime. Our support team is here to help with any questions or concerns.</p></div>
  </div>
</div></section>

<dialog class="requirements-dialog" aria-labelledby="requirements-dialog-title">
  <div class="requirements-dialog-content">
    <div class="requirements-dialog-head">
      <div><span class="eyebrow">Before You Apply</span><h2 id="requirements-dialog-title"></h2></div>
      <button class="requirements-dialog-close" type="button" data-requirements-close aria-label="Close requirements">Close</button>
    </div>
    <p class="requirements-dialog-intro">Have these items ready before starting your request.</p>
    <ul class="requirements-dialog-list"></ul>
    <div class="requirements-dialog-actions">
      <button class="btn btn-ghost" type="button" data-requirements-close>Close</button>
      <a class="btn btn-primary" data-requirements-start href="/client/document-request.php">Start Request <?= icon_span('arrowRight') ?></a>
    </div>
  </div>
</dialog>

<?php require __DIR__ . '/includes/site_footer.php'; ?>
<script>
(function(){
  const requirementsDialog = document.querySelector('.requirements-dialog');
  const requirementSets = {
    document: {
      title: 'Document Request Requirements',
      items: ['Valid government-issued ID', 'Completed request details', 'Property or tax declaration information', 'Authorization letter and representative ID, if filing for the owner'],
      href: '/client/document-request.php',
      action: 'Start Document Request'
    },
    land: {
      title: 'Land Transfer Requirements',
      items: ['Certified True Copy of Tax Declaration or Title', 'Notarial Deed of Sale or Donation', 'Vicinity Map', 'Certification of No Improvement', 'Tax Clearance', 'Valid government-issued ID'],
      href: '/client/land-transfer.php',
      action: 'Start Land Transfer'
    }
  };
  const requirementTitle = requirementsDialog.querySelector('#requirements-dialog-title');
  const requirementList = requirementsDialog.querySelector('.requirements-dialog-list');
  const requirementStart = requirementsDialog.querySelector('[data-requirements-start]');
  document.querySelectorAll('[data-requirements]').forEach(button => {
    button.addEventListener('click', () => {
      const requirements = requirementSets[button.dataset.requirements];
      if (!requirements) return;
      requirementTitle.textContent = requirements.title;
      requirementList.replaceChildren(...requirements.items.map(item => {
        const listItem = document.createElement('li');
        listItem.textContent = item;
        return listItem;
      }));
      requirementStart.href = requirements.href;
      requirementStart.innerHTML = requirements.action + ' <?= icon_span('arrowRight') ?>';
      requirementsDialog.showModal();
    });
  });
  requirementsDialog.querySelectorAll('[data-requirements-close]').forEach(button => {
    button.addEventListener('click', () => requirementsDialog.close());
  });
  requirementsDialog.addEventListener('click', event => {
    if (event.target === requirementsDialog) requirementsDialog.close();
  });

  const slider = document.querySelector('[data-hero-slider]');
  if (!slider) return;
  const slides = Array.from(slider.querySelectorAll('.hero-slide'));
  const dots = Array.from(slider.querySelectorAll('[data-slide-to]'));
  let current = 0;
  let timer;
  let touchStartX = 0;
  function show(index){
    current = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => slide.classList.toggle('is-active', i === current));
    dots.forEach((dot, i) => dot.classList.toggle('is-active', i === current));
  }
  function restart(){ clearInterval(timer); timer = setInterval(() => show(current + 1), 5500); }
  slider.querySelector('[data-slider-prev]').addEventListener('click', () => { show(current - 1); restart(); });
  slider.querySelector('[data-slider-next]').addEventListener('click', () => { show(current + 1); restart(); });
  dots.forEach(dot => dot.addEventListener('click', () => { show(Number(dot.dataset.slideTo)); restart(); }));
  slider.addEventListener('mouseenter', () => clearInterval(timer));
  slider.addEventListener('mouseleave', restart);
  slider.addEventListener('touchstart', event => { touchStartX = event.changedTouches[0].clientX; clearInterval(timer); }, {passive:true});
  slider.addEventListener('touchend', event => {
    const distance = event.changedTouches[0].clientX - touchStartX;
    if (Math.abs(distance) > 45) show(distance < 0 ? current + 1 : current - 1);
    restart();
  }, {passive:true});
  restart();
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
