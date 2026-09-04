# SMART ASSESS — two separate interfaces, real server-side RBAC

Two genuinely separate portals, each with its own login, its own session
type, and its own routes — sharing one MySQL database and one Django AI
checker service.

```
PUBLIC CLIENT PORTAL              INTERNAL MUNICIPAL OFFICE PORTAL
        ↓                                      ↓
   role: CLIENT                    roles: STAFF / ADMIN / DEPARTMENT_HEAD
   /, /login.php, /register.php    /internal/login.php
   /client/*                       /staff/*, /admin/*, /department-head/*
```

No page in either portal links to the other's login. Access is enforced
**server-side on every request** — not by hiding a button — via
`require_client()` / `require_role()` in `includes/`.

## Everything is already running

- Public site: **http://127.0.0.1:8000/index.php**
- Internal portal: **http://127.0.0.1:8000/internal/login.php** (not linked from the public site — this is the only way in)
- AI checker: http://127.0.0.1:8001/api/health/

**Demo client login** (`/login.php`): `r.villareal@example.com` / `Passw0rd!`
**Demo internal logins** (`/internal/login.php`), password `Passw0rd!` for all:

| Username | Role |
|---|---|
| `maricar.admin` | Admin |
| `jessica.staff` | Assessor's Staff |
| `rodel.head` | Department Head |

**`config.php` now reads the DB password from the `SMART_ASSESS_DB_PASS`
environment variable** (falling back to the placeholder `change-me` if it's
unset — the app will fail every DB query until you export the real one).
Set it once per terminal session before starting the PHP server:

```bash
export SMART_ASSESS_DB_PASS='SmartAssess_2026!'   # matches database/schema.sql
```

Restart commands, otherwise unchanged from before:
```bash
brew services start mariadb
cd ai_checker && python3 manage.py runserver 127.0.0.1:8001   # separate terminal
cd web && SMART_ASSESS_DB_PASS='SmartAssess_2026!' php -S 127.0.0.1:8000 -t .
```

Reset + reseed:
```bash
mysql -u "$(whoami)" < database/schema.sql   # drops & recreates smart_assess
php database/seed_demo_requests.php          # 5 sample requests (needs the env var set too)
```

## Role model

Four roles, one `roles` table (`1=CLIENT, 2=STAFF, 3=ADMIN, 4=DEPARTMENT_HEAD`).
CLIENT accounts live in **`clients`**; STAFF/ADMIN/DEPARTMENT_HEAD accounts
live in **`users`** with a `role_id` FK. Two tables, not one shared table
with a role flag — see "Why two tables" below.

## Route structure

**Public client** (no login needed to submit/track; login unlocks the rest):
```
/                              landing
/register.php                  create a client account
/login.php                     client login (never redirects to an internal dashboard)
/logout.php                    client logout
/confirmation.php?ref=...      shown right after any submission
/client/dashboard.php          [requires client login]
/client/document-request.php   works with or without login
/client/land-transfer.php      works with or without login
/client/my-requests.php        [requires client login]
/client/track-request.php      works without login (reference number only)
/client/notifications.php      [requires client login]
/client/profile.php            [requires client login]
/client/help.php               Help/Support message form (Live Chat stand-in — see note below)
```

**Internal portal** (every route below requires internal login + the exact role shown):
```
/internal/login.php            the ONLY entry point to this whole portal
/internal/logout.php

/staff/dashboard.php            [STAFF]
/staff/requests.php             [STAFF]  all requests, filterable
/staff/document-requests.php    [STAFF]
/staff/land-transfers.php       [STAFF]
/staff/ai-checker.php           [STAFF]  AI verdict per request
/staff/notifications.php        [STAFF]  every SMS sent, log view
/staff/detail.php?id=..         [STAFF]  full record + status actions
/staff/update_status.php        [STAFF]  POST handler

/admin/dashboard.php            [ADMIN]
/admin/users.php                [ADMIN]  create accounts, change role/status
/admin/roles.php                [ADMIN]  read-only: the 4 roles + who has them
/admin/settings.php             [ADMIN]  office name/phone/email/hours
/admin/audit-logs.php           [ADMIN]  every login/status-change/account-change

/department-head/dashboard.php       [DEPARTMENT_HEAD]
/department-head/reports.php         [DEPARTMENT_HEAD]  status + barangay breakdown
/department-head/announcements.php   [DEPARTMENT_HEAD]  compose + history
```

## Access-control logic (`includes/auth.php`, `includes/client_auth.php`)

```php
function require_role(array $roles): array {
    $user = current_user();               // reads $_SESSION['internal_user']
    if (!$user) {
        if (current_client()) { redirect('/client/dashboard.php'); exit; }
        redirect('/internal/login.php'); exit;
    }
    if (!in_array($user['role'], $roles, true)) {
        redirect(internal_dashboard_path($user['role'])); exit;  // OWN dashboard, never login
    }
    return $user;
}
```

Every protected file's first executable line is `require_role(['staff'])`
(or `['admin']`, `['head']`) or `require_client()` — the page's HTML is
never built, let alone sent, for a denied request; the `header('Location:
...'); exit;` fires before anything else runs.

**Client vs. internal auth — how they differ:**

| | Client (`client_auth.php`) | Internal (`auth.php`) |
|---|---|---|
| Table | `clients` | `users` |
| Session key | `$_SESSION['client']` | `$_SESSION['internal_user']` |
| Login field | email | username |
| Roles possible | always CLIENT | staff / admin / head |
| On wrong-portal access | sent to `/client/dashboard.php` | sent to their own `/staff\|admin\|department-head/dashboard.php` |

They don't import each other's login/session functions (only `client_auth.php`
is referenced by `auth.php`, and only to read `current_client()` for the
one redirect-target nicety above) — there's no code path where one
session type could be mistaken for the other.

## Why two tables instead of one shared `accounts` table + role column

A single table with a `role` column is one `if` statement away from a bug
that grants cross-portal access. Splitting `clients` and `users` into
separate tables makes "a client can never become staff" a structural fact
— the client login (`login_client()`) executes `SELECT ... FROM clients`
and nothing else exists that could authenticate a client into `users`.
The `roles` table still gives every account exactly one canonical role ID,
satisfying the schema requirement, while the physical split is the actual
security boundary.

## How to test each role

```bash
# Client: register, get denied from staff, get let into client pages
curl -c c.txt http://127.0.0.1:8000/register.php   # grab csrf_token, POST it
curl -b c.txt -L http://127.0.0.1:8000/client/dashboard.php   # 200
curl -b c.txt -D - http://127.0.0.1:8000/staff/dashboard.php  # 302 -> /client/dashboard.php

# Staff: log in at /internal/login.php with jessica.staff / Passw0rd!
curl -b s.txt -D - http://127.0.0.1:8000/admin/users.php      # 302 -> /staff/dashboard.php (denied)
curl -b s.txt http://127.0.0.1:8000/staff/requests.php        # 200 (allowed)

# Same pattern for maricar.admin -> /admin/*, and rodel.head -> /department-head/*
```
Or just click through it in a browser — logging in at `/internal/login.php`
with each demo account and trying to type another portal's URL directly
into the address bar reproduces the same denials.

## What's simplified for local/defense scope

- **Live Chat**: `/client/help.php` is a real stored message form (staff
  can be given visibility later), not a real-time chat widget — that needs
  websocket infrastructure out of scope here.
- **Roles page** (`/admin/roles.php`) is read-only; the four roles are
  fixed by design, not dynamically creatable — reassigning an account's
  role happens on `/admin/users.php`.
- CSRF is a simple per-session token; the Django AI-checker API has no
  auth key (trusted-localhost-only) — both fine for a defense demo, both
  worth hardening before any real deployment.
- Audit log captures logins, registrations, profile/password changes, and
  status/account/setting changes — not literally every read/view.
