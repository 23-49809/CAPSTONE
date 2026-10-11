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
 *
 * 4th tuple element = eligible for "active" highlighting. Without this,
 * "Staff Accounts" / "Create Account" (an anchor into the same page) /
 * "Staff Information" all resolve to the same script path and would all
 * light up simultaneously — only one item should ever look active.
 */
$__navItems = match ($user['role'] ?? null) {
    'admin' => [
        ['/admin/dashboard.php', 'Dashboard', 'grid', true],
        ['/admin/users.php', 'Staff Accounts', 'users', true],
        ['/admin/archived-users.php', 'Archived Accounts', 'archive', true],
        ['/admin/users.php#create-account', 'Create Account', 'userPlus', false],
        ['/admin/users.php', 'Staff Information', 'idCard', false],
        ['/admin/profile.php', 'Profile', 'user', true],
    ],
    'head' => [
        ['/department-head/dashboard.php', 'Dashboard', 'grid', true],
        ['/department-head/announcements.php', 'Announcements', 'megaphone', true],
        ['/department-head/reports.php', 'Generated Reports', 'fileBar', true],
        ['/department-head/profile.php', 'Profile', 'user', true],
    ],
    'staff' => [
        ['/staff/dashboard.php', 'Dashboard', 'grid', true],
        ['/staff/requests.php?status=overdue', 'Time Stamp Tracking', 'timer', true],
        ['/staff/document-requests.php', 'Document Requests', 'doc', true],
        ['/staff/land-transfers.php', 'Land Transfer Requests', 'swap', true],
        ['/staff/ai-checker.php', 'Requirement Checking Results', 'checklist', true],
        ['/staff/notifications.php', 'Notifications', 'sms', true],
        ['/staff/profile.php', 'Profile', 'user', true],
    ],
    default => [],
};
$__currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
?>
<nav>
<?php foreach ($__navItems as [$href, $label, $icon, $trackActive]):
    $hrefPath = strtok($href, '#?');
    $isActive = $trackActive && $hrefPath === $__currentScript;
?>
  <a class="side-link<?= $isActive ? ' active' : '' ?>" href="<?= esc($href) ?>"><?= icon_span($icon, '18px') ?><span><?= esc($label) ?></span></a>
<?php endforeach; ?>
</nav>
