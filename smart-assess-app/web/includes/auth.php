<?php
/**
 * INTERNAL authentication (Assessor's Staff / Admin / Department Head)
 * only. Authenticates exclusively against the `users` table — this file
 * has no knowledge of `clients` at all, and includes/client_auth.php has
 * no knowledge of `users`. That separation is deliberate: it's what makes
 * "a client can never become staff/admin/head" a property of the code
 * (two different tables, two different session keys), not just a role
 * check that a future edit could accidentally weaken.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/client_auth.php'; // only for the redirect-target check in require_role() below

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ROLE_ID_TO_CODE = [2 => 'staff', 3 => 'admin', 4 => 'head'];
const ROLE_CODE_TO_ID = ['staff' => 2, 'admin' => 3, 'head' => 4];

function current_user(): ?array
{
    return $_SESSION['internal_user'] ?? null;
}

function login_user(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // status!=='Active' already covers archived/deleted accounts (both are
    // forced to Inactive), but check deleted_at explicitly too — a
    // permanently-deleted account must never authenticate even if some
    // future code path left status alone.
    if (!$user || $user['status'] !== 'Active' || $user['deleted_at'] !== null) {
        return null;
    }
    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }

    $_SESSION['internal_user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'username' => $user['username'],
        'role' => ROLE_ID_TO_CODE[(int) $user['role_id']] ?? null,
    ];
    audit(
        $_SESSION['internal_user']['role'] ?? 'staff', $user['id'], $user['name'],
        'Logged in to internal portal'
    );
    db()->prepare('INSERT INTO user_sessions (user_type, user_id, ip_address, user_agent) VALUES (?,?,?,?)')
        ->execute([
            $_SESSION['internal_user']['role'], $user['id'],
            $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null,
        ]);
    $_SESSION['internal_session_id'] = (int) db()->lastInsertId();
    return $_SESSION['internal_user'];
}

function logout_user(): void
{
    $user = current_user();
    if ($user) {
        audit($user['role'], $user['id'], $user['name'], 'Logged out of internal portal');
        if (!empty($_SESSION['internal_session_id'])) {
            db()->prepare('UPDATE user_sessions SET logout_at = NOW() WHERE id = ? AND logout_at IS NULL')
                ->execute([$_SESSION['internal_session_id']]);
        }
    }
    unset($_SESSION['internal_user'], $_SESSION['internal_session_id']);
    session_regenerate_id(true);
}

function internal_dashboard_path(?string $role): ?string
{
    return match ($role) {
        'admin' => '/admin/dashboard.php',
        'staff' => '/staff/dashboard.php',
        'head' => '/department-head/dashboard.php',
        default => null,
    };
}

function internal_profile_path(?string $role): string
{
    return match ($role) {
        'admin' => '/admin/profile.php',
        'head' => '/department-head/profile.php',
        default => '/staff/profile.php',
    };
}

/** Avatar-circle initials, same rule as the prototype's initials(). */
function internal_initials(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));
    if (!$parts || $parts[0] === '') return '??';
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/**
 * Enforces access on every protected internal page. Two distinct denial
 * paths, per spec:
 *   - not authenticated at all  -> send to the internal login page
 *   - authenticated, wrong role -> DENY and send to THEIR OWN dashboard
 *     (never render the page they weren't allowed to see)
 */
function require_role(array $roles): array
{
    $user = current_user();
    if (!$user) {
        // A CLIENT has no path into the internal portal at all — if they're
        // signed in as a client, send them back to THEIR dashboard rather
        // than dangling them on a staff login screen they have no account for.
        if (current_client()) {
            header('Location: /client/dashboard.php');
            exit;
        }
        header('Location: /internal/login.php');
        exit;
    }
    if (!in_array($user['role'], $roles, true)) {
        header('Location: ' . (internal_dashboard_path($user['role']) ?? '/internal/login.php'));
        exit;
    }
    return $user;
}

/**
 * Data-backed permission check against role_permissions, for new code to
 * adopt incrementally. require_role() above remains the primary
 * enforcement on every existing page — this doesn't replace it.
 */
function user_can(string $permissionKey, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user || !isset(ROLE_CODE_TO_ID[$user['role']])) {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT 1 FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.`key` = ? LIMIT 1'
    );
    $stmt->execute([ROLE_CODE_TO_ID[$user['role']], $permissionKey]);
    return (bool) $stmt->fetchColumn();
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'staff' => "Assessor's Staff",
        'head' => 'Department Head',
        default => ucfirst($role),
    };
}
