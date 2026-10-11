<?php
require_once __DIR__ . '/../auth/client_auth.php';
require_once __DIR__ . '/icons.php';

/**
 * Header for the PUBLIC CLIENT PORTAL only. Used by index.php, register.php,
 * login.php (client login), and everything under /client/*. There is no
 * link, button, or route reference to the internal portal anywhere in this
 * file — that portal lives at /internal/login.php, which nothing here
 * points to.
 */
$pageTitle = $pageTitle ?? 'SMART ASSESS';
$client = current_client();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($pageTitle) ?> · SMART ASSESS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header"><div class="wrap">
  <a class="brand" href="/index.php"><img class="brand-mark" src="/assets/seal.png" alt="Bayan ng Mabini seal"><span class="brand-name">Smart Assess</span></a>
  <div class="header-actions">
    <a class="btn btn-ghost" href="/client/track-request.php"><?= icon_span('search') ?> Track Request</a>
    <?php if ($client): ?>
      <span class="role-tag"><?= icon_span('user', '14px') ?> Hi, <?= esc($client['first_name']) ?></span>
      <a class="btn btn-ghost" href="/client/dashboard.php"><?= icon_span('grid') ?> My Dashboard</a>
      <a class="btn btn-ghost" href="/logout.php"><?= icon_span('logout') ?> Log Out</a>
    <?php else: ?>
      <a class="btn btn-primary" href="/login.php"><?= icon_span('login') ?> Log In</a>
    <?php endif; ?>
  </div>
</div></header>
