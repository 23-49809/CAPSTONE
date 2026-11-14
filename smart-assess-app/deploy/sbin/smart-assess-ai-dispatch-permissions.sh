#!/bin/bash
# SMART ASSESS - read access for the AI dispatcher, through its own group sa-ai-read.
#
# NOT INSTALLED. Install (with approval) as root:root 0755 at
#   /usr/local/sbin/smart-assess-ai-dispatch-permissions.sh
# smart-assess-ai-dispatch-permissions.service runs it as root before every dispatcher run
# (the dispatcher Requires= that unit), so the access below is restored after each deployment:
# the deploy script copies application files through a temporary folder and renames them
# into place with ubuntu:www-data 0640, which drops any extra access entry on a file.
#
# What sa-ai-read gets (POSIX ACLs; owners, groups and modes are never changed, so Apache
# and the deploy script are unaffected):
#   - pass through (x) the folders on the way, nothing else in them;
#   - read the dispatcher's own code: bin/ai-verification-dispatch.php, web/bootstrap.php,
#     app/services/*.php;
#   - read the request uploads in private_uploads/requests, including new ones (a default
#     entry on the folder). Never drafts, logs, other code, settings or credentials.
# Needs the "acl" package (setfacl). Exits non-zero (and the dispatcher does not run) if
# anything is missing, so access can only be too little, never too much.
set -euo pipefail
APP=${SMART_ASSESS_APP_ROOT:-/var/www/smart-assess}
GROUP=sa-ai-read

command -v setfacl >/dev/null || { echo "setfacl is not installed (package acl)" >&2; exit 1; }
getent group "$GROUP" >/dev/null || { echo "group $GROUP does not exist" >&2; exit 1; }
for path in "$APP" "$APP/bin/ai-verification-dispatch.php" "$APP/web/bootstrap.php" "$APP/app/services" "$APP/private_uploads/requests"; do
    [[ -e "$path" && ! -L "$path" ]] || { echo "missing or a symbolic link: $path" >&2; exit 1; }
done

# Pass-through only: folders on the way to what is read.
for dir in "$APP" "$APP/bin" "$APP/web" "$APP/app" "$APP/private_uploads"; do
    setfacl -m "g:$GROUP:x" "$dir"
done
# The dispatcher's code (read only).
setfacl -m "g:$GROUP:rx" "$APP/app/services"
setfacl -m "g:$GROUP:r" "$APP/bin/ai-verification-dispatch.php" "$APP/web/bootstrap.php"
find "$APP/app/services" -maxdepth 1 -type f -name '*.php' -exec setfacl -m "g:$GROUP:r" {} +
# The request uploads (read only), and the same for files uploaded later.
setfacl -m "g:$GROUP:rx" -m "d:g:$GROUP:r" "$APP/private_uploads/requests"
find "$APP/private_uploads/requests" -mindepth 1 -maxdepth 1 -type f -exec setfacl -m "g:$GROUP:r" {} +
