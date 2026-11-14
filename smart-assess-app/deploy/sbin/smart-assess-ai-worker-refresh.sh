#!/bin/bash
# SMART ASSESS - refresh the local AI worker's code copy, ENFORCING the release order.
#
# NOT INSTALLED. Install (with approval) as root:root 0755 at /usr/local/sbin/smart-assess-ai-worker-refresh.sh
# Run as root, only after the application release has been deployed:
#   sudo smart-assess-ai-worker-refresh.sh --check-only   # stage and check, change nothing
#   sudo smart-assess-ai-worker-refresh.sh                # stage, check, install, restart, health-check
#
# 1. Stages worker/index.php and app/services/*.php from the DEPLOYED application
#    (/var/www/smart-assess) into a new root-owned, read-only copy.
# 2. Runs bin/ai-worker-compat-check.php against the deployed application. Anything but
#    "compatible" (exit 0) stops here with nothing changed: deploy the application first.
# 3. Swaps the copy in (/opt/smart-assess-ai-worker; the previous one is kept as
#    /opt/smart-assess-ai-worker.previous), restarts the worker's PHP-FPM pool and checks
#    /v1/health. If the worker does not answer {"ok":true} within 60 s, the previous copy is
#    put back and the pool restarted again (rollback), and the script exits non-zero.
# The dispatcher also runs the same check before every run (its unit's ExecStartPre).
set -euo pipefail
APP=/var/www/smart-assess
LIVE=/opt/smart-assess-ai-worker
STAGE=$LIVE.new
PREV=$LIVE.previous
CHECK_ONLY=0
[[ "${1:-}" == "--check-only" ]] && CHECK_ONLY=1
[[ $EUID -eq 0 ]] || { echo "run as root" >&2; exit 2; }
for f in "$APP/worker/index.php" "$APP/bin/ai-worker-compat-check.php" "$APP/app/services/AiDocumentWorker.php"; do
    [[ -f "$f" && ! -L "$f" ]] || { echo "missing in the deployed application: $f" >&2; exit 2; }
done

rm -rf -- "$STAGE"
install -d -o root -g root -m 0755 "$STAGE" "$STAGE/worker" "$STAGE/app" "$STAGE/app/services"
install -o root -g root -m 0644 "$APP/worker/index.php" "$STAGE/worker/index.php"
for f in "$APP"/app/services/*.php; do
    [[ -f "$f" && ! -L "$f" ]] || { echo "not a regular file: $f" >&2; rm -rf -- "$STAGE"; exit 2; }
    install -o root -g root -m 0644 "$f" "$STAGE/app/services/"
done
(cd "$STAGE" && find worker app -type f -name '*.php' -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS && chmod 0644 SHA256SUMS)

# The release order, enforced: the deployed application must accept what this worker answers.
if ! /usr/bin/php -n "$APP/bin/ai-worker-compat-check.php" --app="$APP" --worker="$STAGE"; then
    rm -rf -- "$STAGE"
    echo "REFUSED: this worker copy is not compatible with the deployed application. Nothing was changed." >&2
    exit 1
fi
if [[ $CHECK_ONLY -eq 1 ]]; then
    rm -rf -- "$STAGE"
    echo "check only: compatible; nothing was changed."
    exit 0
fi

healthy() {
    local i
    for i in $(seq 1 60); do
        [[ "$(curl -s -m 5 http://127.0.0.1:8095/v1/health 2>/dev/null)" == '{"ok":true}' ]] && return 0
        sleep 1
    done
    return 1
}
rm -rf -- "$PREV"
[[ -d "$LIVE" ]] && mv -- "$LIVE" "$PREV"
mv -- "$STAGE" "$LIVE"
systemctl restart smart-assess-ai-worker-fpm.service
if healthy; then
    echo "worker refreshed: $(sha256sum "$LIVE/SHA256SUMS" | cut -c1-16) (previous copy kept at $PREV)"
    exit 0
fi
echo "the refreshed worker is not healthy: rolling back to the previous copy" >&2
rm -rf -- "$LIVE.failed"
mv -- "$LIVE" "$LIVE.failed"
[[ -d "$PREV" ]] && mv -- "$PREV" "$LIVE"
systemctl restart smart-assess-ai-worker-fpm.service
healthy && echo "rolled back; the previous worker is healthy (failed copy kept at $LIVE.failed)" >&2
exit 1
