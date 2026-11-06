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
<header class="legal-header">
  <div class="legal-wrap legal-header-row">
    <a class="legal-brand" href="/">
      <img src="/assets/LOGO.png" alt="" width="44" height="44">
      <span><strong>SMART ASSESS</strong><small>Municipal Assessor's Office of Mabini, Batangas</small></span>
    </a>
    <nav aria-label="Legal pages">
      <a href="/">Home</a>
      <a href="/privacy-policy.php">Privacy Policy</a>
      <a href="/terms.php">Terms of Service</a>
    </nav>
  </div>
</header>
<main id="main" class="legal-wrap legal-main">
<h1>{$safeTitle}</h1>
<p class="legal-updated">Last updated: {$safeUpdated}</p>

HTML;
}

function sa_legal_footer(): void
{
    echo <<<'HTML'

</main>
<footer class="legal-footer">
  <div class="legal-wrap">
    <p><strong>Municipal Assessor's Office</strong>, Municipal Hall, Poblacion, Mabini, Batangas, Philippines</p>
    <p>Phone: (043) 487-0123 &middot; Email: <a href="mailto:mao.smartassess@gmail.com">mao.smartassess@gmail.com</a> &middot; Monday to Friday, 8:00 AM to 5:00 PM</p>
    <nav aria-label="Footer">
      <a href="/">Home</a>
      <a href="/privacy-policy.php">Privacy Policy</a>
      <a href="/terms.php">Terms of Service</a>
    </nav>
  </div>
</footer>
</body>
</html>

HTML;
}
