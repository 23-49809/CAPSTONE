#!/usr/bin/env bash
set -Eeuo pipefail

APP_DEST=/var/www/capstone/smart-assess-app
WEB_PARENT=/var/www/capstone
ENV_FILE=/etc/apache2/conf-enabled/smart-assess-env.conf
BACKUP_ROOT=/var/backups/smart-assess

umask 077
test -d "$APP_DEST/private_uploads"
test -f "$WEB_PARENT/smart-assess.html"
test -f "$WEB_PARENT/smart-assess-internal.html"
test -f "$ENV_FILE"
command -v mysqldump >/dev/null

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR="$BACKUP_ROOT/$STAMP"
install -d -o root -g root -m 700 "$BACKUP_DIR"
MYSQL_CONFIG="$BACKUP_DIR/.mysql.cnf"
FILES_BACKUP="$BACKUP_DIR/application-files.tar.gz"
DATABASE_BACKUP="$BACKUP_DIR/database.sql.gz"
APP_DUMP_ERROR="$BACKUP_DIR/.application-dump.err"
SYSTEM_DUMP_ERROR="$BACKUP_DIR/.system-dump.err"
trap 'rm -f -- "$MYSQL_CONFIG"' EXIT

DB_NAME=$(python3 - "$ENV_FILE" "$MYSQL_CONFIG" <<'PY'
import os
import pathlib
import re
import shlex
import sys

config_path, mysql_config_path = sys.argv[1:]
allowed = {"DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "SMART_ASSESS_DB_PASS"}
values = {}
for line in pathlib.Path(config_path).read_text().splitlines():
    try:
        parts = shlex.split(line, comments=True)
    except ValueError as error:
        raise SystemExit("Unable to parse the application environment configuration.") from error
    if len(parts) == 3 and parts[0] == "SetEnv" and parts[1] in allowed:
        values[parts[1]] = parts[2]

required = {"DB_NAME", "DB_USER", "SMART_ASSESS_DB_PASS"}
if not required.issubset(values) or any(not values[key] for key in required):
    raise SystemExit("Required database settings are missing from the application environment.")

host = values.get("DB_HOST", "127.0.0.1")
port = values.get("DB_PORT", "3307")
name = values["DB_NAME"]
if (not re.fullmatch(r"[A-Za-z0-9.:-]+", host)
        or not re.fullmatch(r"[A-Za-z0-9_]+", name)
        or not re.fullmatch(r"[0-9]{1,5}", port)
        or not 1 <= int(port) <= 65535
        or any(char in values["DB_USER"] + values["SMART_ASSESS_DB_PASS"] for char in "\r\n\0")):
    raise SystemExit("Database settings contain unsupported values.")

def quote_option(value):
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n").replace("\r", "\\r") + '"'

contents = "\n".join((
    "[client]",
    "protocol=tcp",
    "host=" + quote_option(host),
    "port=" + port,
    "user=" + quote_option(values["DB_USER"]),
    "password=" + quote_option(values["SMART_ASSESS_DB_PASS"]),
    "",
))
fd = os.open(mysql_config_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, "w") as mysql_config:
    mysql_config.write(contents)
print(name)
PY
)

printf 'Creating protected production backup: %s\n' "$BACKUP_DIR"
tar -czpf "$FILES_BACKUP" -C "$WEB_PARENT" \
    smart-assess-app smart-assess.html smart-assess-internal.html
DUMP_ARGS=(--single-transaction --routines --triggers --events --no-tablespaces --databases "$DB_NAME")
if mysqldump --defaults-extra-file="$MYSQL_CONFIG" "${DUMP_ARGS[@]}" \
    > "$BACKUP_DIR/database.sql" 2> "$APP_DUMP_ERROR"; then
    :
else
    if [[ ! -r /etc/mysql/debian.cnf ]]; then
        cat "$APP_DUMP_ERROR" >&2
        echo "Application database credentials cannot create a complete dump, and local MySQL maintenance credentials are unavailable." >&2
        exit 1
    fi
    echo "Application database credentials lack full-schema privileges; retrying with local MySQL maintenance credentials."
    if ! mysqldump --defaults-extra-file=/etc/mysql/debian.cnf "${DUMP_ARGS[@]}" \
        > "$BACKUP_DIR/database.sql" 2> "$SYSTEM_DUMP_ERROR"; then
        cat "$SYSTEM_DUMP_ERROR" >&2
        echo "Local MySQL maintenance credentials could not create a complete database dump." >&2
        exit 1
    fi
fi
gzip -c "$BACKUP_DIR/database.sql" > "$DATABASE_BACKUP"
rm -f -- "$BACKUP_DIR/database.sql" "$APP_DUMP_ERROR" "$SYSTEM_DUMP_ERROR"

test -s "$FILES_BACKUP"
test -s "$DATABASE_BACKUP"
tar -tzf "$FILES_BACKUP" >/dev/null
gzip -t "$DATABASE_BACKUP"
gzip -cd "$DATABASE_BACKUP" |
    awk -v database="$DB_NAME" 'index($0, database) { found = 1 } END { exit !found }'
chmod 600 "$FILES_BACKUP" "$DATABASE_BACKUP"

printf 'Verified production backup for database %s.\n' "$DB_NAME"
sha256sum "$FILES_BACKUP" "$DATABASE_BACKUP"
du -h "$FILES_BACKUP" "$DATABASE_BACKUP"
