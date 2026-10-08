<?php
/**
 * Internal portal sidebar nav — per-role item lists transcribed from
 * smart-assess-internal.html's NAV config (lines 736-759 of that file),
 * adapted from SPA view keys to real PHP routes. Required by
 * includes/internal_header.php inside the already-open <aside class="sidebar">.
 *
 * "Staff Accounts" and "Staff Information" intentionally point at the same
 * admin/users.php page (the prototype treats them as one view under two
 * labels, not two separate pages). "Time Stamp Tracking" is intentionally
 * repointed at the existing overdue-request filter rather than a staff
 * attendance clock in/out feature, which this port does not build.
 */
$__navItems = match ($user['role'] ?? null) {
    'admin' => [
        ['/admin/dashboard.php', 'Dashboard', 'grid'],
        ['/admin/users.php', 'Staff Accounts', 'users'],
        ['/admin/archived-users.php', 'Archived Accounts', 'archive'],
        ['/admin/users.php#create-account', 'Create Account', 'userPlus'],
        ['/admin/users.php', 'Staff Information', 'idCard'],
        ['/admin/profile.php', 'Profile', 'user'],
    ],
    'head' => [
        ['/department-head/dashboard.php', 'Dashboard', 'grid'],
        ['/department-head/announcements.php', 'Announcements', 'megaphone'],
        ['/department-head/reports.php', 'Generated Reports', 'fileBar'],
        ['/department-head/profile.php', 'Profile', 'user'],
    ],
    'staff' => [
        ['/staff/dashboard.php', 'Dashboard', 'grid'],
        ['/staff/requests.php?status=overdue', 'Time Stamp Tracking', 'timer'],
        ['/staff/document-requests.php', 'Document Requests', 'doc'],
        ['/staff/land-transfers.php', 'Land Transfer Requests', 'swap'],
        ['/staff/ai-checker.php', 'Requirement Checking Results', 'checklist'],
        ['/staff/notifications.php', 'Notifications', 'sms'],
        ['/staff/profile.php', 'Profile', 'user'],
    ],
    default => [],
};
$__currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
?>
<nav>
<?php foreach ($__navItems as [$href, $label, $icon]):
    $hrefPath = strtok($href, '#?');
    $isActive = $hrefPath === $__currentScript;
?>
  <a class="side-link<?= $isActive ? ' active' : '' ?>" href="<?= esc($href) ?>"><?= icon_span($icon, '18px') ?><span><?= esc($label) ?></span></a>
<?php endforeach; ?>
</nav>
