#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3307}"
: "${DB_NAME:=smart_assess}"
: "${DB_USER:=root}"

if [[ -z "${SMART_ASSESS_DB_PASS:-}" ]]; then
  read -r -s -p "MySQL password for ${DB_USER}: " SMART_ASSESS_DB_PASS
  printf '\n'
fi
if [[ -z "$SMART_ASSESS_DB_PASS" ]]; then
  printf 'A non-empty SMART_ASSESS_DB_PASS is required.\n' >&2
  exit 1
fi

export DB_HOST DB_PORT DB_NAME DB_USER SMART_ASSESS_DB_PASS
php -r '
$dsn = sprintf(
    "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
    getenv("DB_HOST"),
    (int) getenv("DB_PORT"),
    getenv("DB_NAME")
);
try {
    new PDO($dsn, getenv("DB_USER"), getenv("SMART_ASSESS_DB_PASS"), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, "MySQL connection failed: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
'

cd "$(dirname "$0")/web"
exec php -d upload_max_filesize=5M -d post_max_size=50M \
  -S "${SMART_ASSESS_BIND:-127.0.0.1:8923}"
