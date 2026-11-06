<?php
declare(strict_types=1);

/*
 * Shared page frame for the public legal pages. They load no database, session
 * or login code, so they stay available to anyone without signing in.
 */

function sa_legal_header(string $title, string $description, string $updated): void
{
    header('Content-Type: text/html; charset=UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeDescription = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $safeUpdated = htmlspecialchars($updated, ENT_QUOTES, 'UTF-8');
    $isPrivacy = $title === 'Privacy Policy';
    $privacyCurrent = $isPrivacy ? ' is-current' : '';
    $privacyAria = $isPrivacy ? ' aria-current="page"' : '';
    $termsCurrent = $isPrivacy ? '' : ' is-current';
    $termsAria = $isPrivacy ? '' : ' aria-current="page"';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle} | SMART ASSESS</title>
<meta name="description" content="{$safeDescription}">
<link rel="stylesheet" href="/assets/legal.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="wrap">
    <a class="brand" href="/">
      <img class="brand-mark" src="/assets/brand-mark.png" alt="" width="42" height="42">
      <span class="brand-name">Smart Assess</span>
    </a>
    <nav class="header-actions" aria-label="Main">
      <a class="btn btn-ghost" href="/">Home</a>
      <a class="header-link{$privacyCurrent}" href="/privacy-policy.php"{$privacyAria}>Privacy Policy</a>
      <a class="header-link{$termsCurrent}" href="/terms.php"{$termsAria}>Terms of Service</a>
    </nav>
  </div>
</header>
<main id="main" class="section legal-page">
<div class="wrap">
<div class="section-head">
  <span class="eyebrow">Municipal Assessor's Office of Mabini, Batangas</span>
  <h1>{$safeTitle}</h1>
  <p class="legal-updated">Last updated: {$safeUpdated}</p>
</div>
<div class="legal-layout">

HTML;
}

function sa_legal_footer(): void
{
    echo <<<'HTML'

</article>
</div>
</div>
</main>
<footer class="site-footer">
  <div class="wrap">
    <div class="footer-grid">
      <div><h5>Smart Assess</h5><p>Municipal Hall, Poblacion, Mabini, Batangas, Philippines</p></div>
      <div><h5>Contact</h5><p>Phone: (043) 487-0123<br>Email: mao.smartassess@gmail.com</p></div>
      <div><h5>Office Hours</h5><p>Monday to Friday<br>8:00 AM &ndash; 5:00 PM</p></div>
      <nav class="footer-links" aria-label="About SMART ASSESS"><h5>Learn More</h5><a href="/">Home</a><a href="/privacy-policy.php">Privacy Policy</a><a href="/terms.php">Terms of Service</a></nav>
    </div>
    <div class="footer-bottom"><span>&copy; 2026 Smart Assess. All rights reserved.</span><span>Smart Assess</span></div>
  </div>
</footer>
</body>
</html>

HTML;
}
