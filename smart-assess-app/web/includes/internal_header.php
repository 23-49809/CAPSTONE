<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';

/**
 * Header for the INTERNAL MUNICIPAL OFFICE PORTAL only — used by
 * /internal/login.php and everything under /staff/*, /admin/*,
 * /department-head/*. Nothing in the public client portal links here;
 * this is reached only via its own direct URL. See includes/auth.php for
 * the server-side enforcement — this header is presentation only.
 *
 * Shell markup (header + sidebar + .main-content open tag) is ported
 * verbatim from smart-assess-internal.html's shellChrome()/renderShell().
 * It only renders once a $user is authenticated — the login page itself
 * (no $user yet) gets no header/sidebar at all, matching the prototype's
 * renderLogin(), which is a bare full-bleed screen with no chrome.
 * includes/internal_footer.php closes whatever this file opens.
 */
$pageTitle = $pageTitle ?? 'SMART ASSESS';
$user = current_user();
$dashHref = internal_dashboard_path($user['role'] ?? null);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($pageTitle) ?> &middot; SMART ASSESS Internal Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Public+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php if ($user): ?>
<header class="app-header"><div class="hd-inner">
  <div style="display:flex;align-items:center;gap:10px;">
    <button type="button" class="hd-menu-btn" id="sidebarToggle" aria-label="Open menu"><?= icon_span('menu', '19px') ?></button>
    <a class="hd-brand" href="<?= esc($dashHref ?? '/internal/login.php') ?>">
      <img src="/assets/seal.png" alt="Bayan ng Mabini seal">
      <div><div class="hb-name">Smart Assess</div><div class="hb-tag">Assessor&rsquo;s Office System</div></div>
    </a>
  </div>
  <div class="hd-right">
    <span class="role-tag"><?= icon_span('id', '14px') ?> <?= esc(role_label($user['role'])) ?></span>
    <a class="hd-avatar" href="<?= esc(internal_profile_path($user['role'])) ?>">
      <span class="avatar-circle"><?= esc(internal_initials($user['name'])) ?></span>
      <span><span class="hd-name"><?= esc($user['name']) ?></span></span>
    </a>
    <a class="hd-logout" href="/internal/logout.php" title="Log Out" aria-label="Log Out"><?= icon_span('logout', '17px') ?></a>
  </div>
</div></header>
<div class="app-body">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="appSidebar">
    <div class="sidebar-label">Menu</div>
    <?php require __DIR__ . '/internal_sidebar.php'; ?>
    <div class="sidebar-foot"><a class="side-link" href="/internal/logout.php"><?= icon_span('logout', '18px') ?><span>Logout</span></a></div>
  </aside>
  <main class="main-content">
<?php endif; ?>
