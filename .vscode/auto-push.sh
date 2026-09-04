#!/bin/zsh

set -u

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root" || exit 1

while true; do
    if [[ -n "$(git status --porcelain)" ]]; then
        git add -A
        if ! git diff --cached --quiet; then
            git commit -m "Auto-sync: $(date '+%Y-%m-%d %H:%M:%S')"
            if ! git push origin main; then
                print "Auto-sync paused: push failed. Resolve the remote issue, then restart this task."
                exit 1
            fi
        fi
    fi
    sleep 5
done
