#!/usr/bin/env bash
set -Eeuo pipefail

APP=/var/www/capstone/smart-assess-app
INTERNAL=/var/www/capstone/smart-assess-internal.html
BRIDGE="$APP/web/assets/php-bridge.js"
API="$APP/web/api.php"

if grep -Eq 'SA_REJECTION_CATEGORIES|sa_rejection_reason|rejection_category|rejection_note|Reason for rejection|Choose a reason|Missing requirements|Incorrect information|Invalid or unclear documents|Other issue|Details for the client' \
    "$API" "$BRIDGE" "$INTERNAL"; then
    echo "Obsolete rejection reason handling remains on production."
    exit 1
fi
grep -Fq "window.location.assign('/internal.php?view=notifications')" "$BRIDGE"
grep -Fq "category: 'Request Status Update'" "$INTERNAL"
php -l "$API"

HTTP_STATUS=$(curl --silent --show-error --max-time 10 --output /dev/null \
    --write-out '%{http_code}' http://127.0.0.1/internal.php)
case "$HTTP_STATUS" in
    200|301|302|303) ;;
    *) echo "Internal portal health check returned HTTP $HTTP_STATUS."; exit 1 ;;
esac
printf 'Production rejection workflow verified; internal portal returned HTTP %s.\n' "$HTTP_STATUS"
