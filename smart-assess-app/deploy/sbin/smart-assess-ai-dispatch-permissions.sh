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
#   - read the dispatcher's own code: bin/ai-verification-dispatch.php, bin/ai-worker-compat-check.php,
#     web/bootstrap.php, app/services/*.php;
#   - read the request uploads in private_uploads/requests, including new ones (a default
#     entry on the folder). Never drafts, logs, other code, settings or credentials.
#
# Path safety (the uploads folders belong to www-data, which could try to swap a path for a
# symbolic link while this runs as root):
#   - every folder on every path, from / down, must be a real folder (no symbolic link), owned
#     by root, ubuntu or www-data, and not writable by "other"; every target file a regular file;
#   - every setfacl runs with -P (physical): it never follows a symbolic link, neither while walking (-R)
#     nor as an argument swapped in after the check (Step 13: without -P an argument link is followed);
#   - every path is checked again after the change; any difference is an error;
#   - its unit mounts the drafts, logs and every other folder it does not change read only, so even
#     a swap that won a race could not change access anywhere else.
# Needs the "acl" package (setfacl). Exits non-zero (and the dispatcher does not run) on any
# problem, so access can only be too little, never too much.
set -euo pipefail
APP=/var/www/smart-assess
GROUP=sa-ai-read

command -v setfacl >/dev/null || { echo "setfacl is not installed (package acl)" >&2; exit 1; }
getent group "$GROUP" >/dev/null || { echo "group $GROUP does not exist" >&2; exit 1; }

# A real folder or file all the way down: no symbolic link in any component, safe owners, not world-writable.
safe_path() {
    local path=$1 kind=$2 part="" component
    IFS=/ read -ra parts <<<"${path#/}"
    for component in "${parts[@]}"; do
        part="$part/$component"
        [[ -e "$part" && ! -L "$part" ]] || { echo "missing or a symbolic link: $part" >&2; return 1; }
        case "$(stat -c %U "$part")" in root|ubuntu|www-data) ;; *) echo "unexpected owner: $part" >&2; return 1 ;; esac
        [[ $(( 0$(stat -c %a "$part") & 02 )) -eq 0 ]] || { echo "writable by everyone: $part" >&2; return 1; }
    done
    if [[ "$kind" == file ]]; then [[ -f "$path" ]]; else [[ -d "$path" ]]; fi || { echo "not a $kind: $path" >&2; return 1; }
}
FILES=("$APP/bin/ai-verification-dispatch.php" "$APP/bin/ai-worker-compat-check.php" "$APP/web/bootstrap.php")
DIRS=("$APP" "$APP/bin" "$APP/web" "$APP/app" "$APP/app/services" "$APP/private_uploads" "$APP/private_uploads/requests")
check_all() {
    local item
    for item in "${FILES[@]}"; do safe_path "$item" file || return 1; done
    for item in "${DIRS[@]}"; do safe_path "$item" dir || return 1; done
}
check_all

# Pass-through only: folders on the way to what is read.
for dir in "$APP" "$APP/bin" "$APP/web" "$APP/app" "$APP/private_uploads"; do
    setfacl -P -m "g:$GROUP:x" -- "$dir"
done
# The dispatcher's code (read only).
setfacl -P -m "g:$GROUP:r" -- "${FILES[@]}"
setfacl -R -P -m "g:$GROUP:rX" -- "$APP/app/services"
# The request uploads (read only), and the same for files uploaded later. -R -P: symbolic links
# inside are skipped, never followed; X gives the folder pass-through but no file execute.
setfacl -R -P -m "g:$GROUP:rX" -- "$APP/private_uploads/requests"
setfacl -P -m "d:g:$GROUP:r" -- "$APP/private_uploads/requests"

# Nothing moved while this ran.
check_all
