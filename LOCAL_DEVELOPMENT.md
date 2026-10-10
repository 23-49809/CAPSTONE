# SMART ASSESS — Local Development Guide

Every command below was tested on this machine on 2026-10-10 unless marked
**Untested**. Facts this guide is built on (also verified, not assumed):

- Homebrew MySQL: `/opt/homebrew/opt/mysql/bin/mysqld`, version 26.7.0, data
  directory `/opt/homebrew/var/mysql`. This is the instance that holds the
  real `smart_assess` database.
- A **separate, unrelated** MySQL installation exists at `/usr/local/mysql`
  (official installer package, runs as system user `_mysql`). It occupies
  port **3306**. **Do not stop, modify, or remove it** — it may belong to
  another project or a system-level service outside this one.
- Because of that conflict, our MySQL cannot use the default port 3306 and
  is run manually on **port 3307** instead.
- Homebrew's MySQL LaunchAgent (`~/Library/LaunchAgents/homebrew.mxcl.mysql.plist`)
  is currently **unloaded** — it no longer auto-starts at login/reboot. It
  was unloaded because, left enabled, it repeatedly tries to bind port 3306,
  fails (the other MySQL already holds it), and gets stuck retrying forever.
- PHP: `/opt/homebrew/bin/php`, version 8.5.10 (cli).
- Project entry point: `/Users/hamy/Documents/CAPSTONE/smart-assess-app/web/index.php`,
  served by PHP's built-in server from the `web/` directory.

## 1. Starting MySQL on port 3307 (Verified)

Homebrew's own `mysql.server`/`brew services` scripts assume the default
port and will fight with the `/usr/local/mysql` instance for 3306, so start
`mysqld` directly with an explicit port instead:

```bash
/opt/homebrew/opt/mysql/bin/mysqld \
  --datadir=/opt/homebrew/var/mysql \
  --port=3307 \
  --socket=/tmp/mysql_smartassess.sock \
  --mysqlx=OFF \
  --pid-file=/tmp/mysql_smartassess.pid \
  > /tmp/mysql_smartassess.log 2>&1 &
disown
```

`--mysqlx=OFF` avoids a separate, unrelated port clash on 33060 (the X
Plugin) — harmless to disable for local dev, not used by this app.

## 2. Stopping MySQL cleanly (Verified)

```bash
kill -TERM "$(cat /tmp/mysql_smartassess.pid)"
```

Confirms clean: the error log ends with `Shutdown complete` and
`lsof -iTCP:3307 -sTCP:LISTEN -nP` returns nothing afterward.

## 3. Confirming MySQL and `smart_assess` are available (Verified)

```bash
mysqladmin -h127.0.0.1 -P3307 --protocol=TCP -uroot -p ping
```
Expect: `mysqld is alive`.

```bash
mysql -h127.0.0.1 -P3307 --protocol=TCP -uroot -p \
  -e "SHOW DATABASES LIKE 'smart_assess';"
```
Expect one row: `smart_assess`.

(Password intentionally omitted from these examples — `mysql`/`mysqladmin`
will prompt for it interactively with a bare `-p`, which is safer than
typing it inline. See `database/schema.sql`'s own setup notes / the
project's existing `README.md` for the credential itself — this guide
deliberately doesn't repeat it.)

## 4. Starting the PHP app on port 8923 with DB_PORT=3307 (Verified)

```bash
cd /Users/hamy/Documents/CAPSTONE/smart-assess-app/web
SMART_ASSESS_DB_PASS='<the real password>' DB_PORT='3307' \
  php -S 127.0.0.1:8923 &
disown
```

Then open **http://127.0.0.1:8923/** (Verified — returns HTTP 200, confirmed
with `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8923/index.php`).

Internal portal login: **http://127.0.0.1:8923/internal/login.php**

Also start the AI requirement-checker service (separate terminal), needed
for client document/land-transfer submissions:
```bash
cd /Users/hamy/Documents/CAPSTONE/smart-assess-app/ai_checker
python3 manage.py runserver 127.0.0.1:8001
```

## 5. Checking what's listening on 3306 / 3307 / 8923 (Verified)

```bash
lsof -iTCP:3306 -sTCP:LISTEN -nP
lsof -iTCP:3307 -sTCP:LISTEN -nP
lsof -iTCP:8923 -sTCP:LISTEN -nP
```

**Important nuance, verified on this machine**: `lsof` run as your normal
user may show **nothing** for port 3306 even while the `/usr/local/mysql`
instance is genuinely listening there — it runs as the system user
`_mysql`, and without `sudo` your `lsof` can't see its socket. Don't take
an empty 3306 result as proof nothing is there. To actually confirm:

```bash
mysqladmin -h127.0.0.1 -P3306 --protocol=TCP ping
```
If something is listening (even a different MySQL you have no credentials
for), this returns an auth error (`Access denied for user ...`), not a
connection-refused error. A connection-refused error is what means the port
is genuinely free.

## 6. Troubleshooting connection errors (Verified)

**Symptom**: Loading any page throws
```
Fatal error: Uncaught PDOException: SQLSTATE[HY000] [1045]
Access denied for user 'smart_assess_app'@'localhost' (using password: YES)
in .../includes/db.php:9
```
**Cause, confirmed by reproducing it**: the PHP server was started without
`DB_PORT=3307`, so `config.php` fell back to its default port 3306 — which
is the *other*, unrelated MySQL installation, not ours. The password is
"wrong" only because it's being checked against the wrong server entirely.
**Fix**: stop the PHP server and restart it with `DB_PORT=3307` set, per
step 4.

**Symptom**: `mysqld` won't start; its error log shows
```
[ERROR] [MY-010262] [Server] Can't start server: Bind on TCP/IP port: Address already in use
[ERROR] [MY-010257] [Server] Do you already have another mysqld server running on port: 3306 ?
```
**Cause**: something (either the `/usr/local/mysql` instance, or a stray
previous Homebrew `mysqld`/`mysqld_safe` you forgot was still running) is
already on port 3306.
**Fix**: this project's `mysqld` should be started on **3307** (step 1), not
3306 — don't try to free up 3306, since that's the other installation's
port, not something to reclaim.

**Symptom**: `mysqld` won't start; log shows
`Unable to lock ./ibdata1 error: 35` even though nothing seems to be using
port 3307.
**Cause, confirmed by reproducing it**: Homebrew's MySQL LaunchAgent
(`homebrew.mxcl.mysql`) re-registered itself (e.g. after a reboot or a
fresh terminal/login session) and is stuck in a start→fail→restart loop
against port 3306, repeatedly locking and releasing the same data files
your manual `mysqld` is also trying to open.
**Fix**:
```bash
launchctl list | grep -i mysql        # check if it's back
launchctl bootout gui/$(id -u)/homebrew.mxcl.mysql
```
Then wait a few seconds and retry step 1. No `sudo` needed — it's a
per-user LaunchAgent.

## 7. Recovering after a Mac reboot (Verified reasoning, from reproducing this exact scenario mid-session)

After a reboot, expect **all three** of these to be down, since none of
them currently auto-start:
1. Check if the LaunchAgent came back and is looping (step 6, the
   `ibdata1` symptom) — if so, `launchctl bootout` it again.
2. Start MySQL manually (step 1).
3. Start the PHP server (step 4) and the AI checker (step 4).

Your actual data is untouched by any of this — it lives in
`/opt/homebrew/var/mysql`, independent of which process serves it or which
port it's reachable on.

## 8. Homebrew MySQL auto-start is currently disabled — restoring it later

Right now, MySQL will **not** start on its own after login/reboot. This was
deliberate, to stop the LaunchAgent's infinite retry loop against the port
conflict described above.

**Do not** just run `brew services start mysql` to "fix" this — on this
machine, with the `/usr/local/mysql` instance still occupying 3306, that
will immediately recreate the exact same failing loop.

Only restore auto-start once the underlying conflict is actually resolved
— for example, if `/usr/local/mysql` is ever uninstalled/stopped for good
(a decision for you to make, not something this guide performs), or if you
reconfigure Homebrew's MySQL to permanently listen on 3307 via its own
`my.cnf` instead of the default 3306. Once that's true:
```bash
brew services start mysql
```
**Untested** — not attempted during this session, since the underlying
port conflict has not been resolved and running this now would just
recreate the failure loop described in step 6.

## Backups

See `/Users/hamy/Documents/CAPSTONE/backups/` for timestamped
`smart_assess_backup_YYYYMMDD_HHMMSS.sql` dumps. To create a new one:
```bash
mysqldump -h127.0.0.1 -P3307 --protocol=tcp -uroot -p \
  --routines --triggers --events --single-transaction --set-gtid-purged=OFF \
  --databases smart_assess \
  > backups/smart_assess_backup_$(date +%Y%m%d_%H%M%S).sql
```
(Verified — this is the exact command used to produce the backup created
during this audit, run via a temporary, permission-restricted option file
instead of a bare `-p<password>` so the credential never appears in shell
history or `ps`; a plain `-p` prompt works the same way interactively.)
