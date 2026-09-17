#!/bin/zsh

set -u

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root" || exit 1

branch="$(git branch --show-current)"
if [[ -z "$branch" ]]; then
    print "Auto-sync paused: repository is not on a branch."
    exit 1
fi

remote="$(git config "branch.$branch.remote" 2>/dev/null || true)"
merge_ref="$(git config "branch.$branch.merge" 2>/dev/null || true)"
if [[ -z "$remote" || -z "$merge_ref" ]]; then
    print "Auto-sync paused: branch '$branch' has no configured upstream."
    exit 1
fi

# Extra remotes to mirror pushes to, in addition to the tracked upstream remote above.
extra_remotes=(mine)

while true; do
    if [[ -n "$(git status --porcelain)" ]]; then
        git add -A
        if ! git diff --cached --quiet; then
            git commit -m "Auto-sync: $(date '+%Y-%m-%d %H:%M:%S')"
            if ! git push "$remote" "$branch"; then
                print "Auto-sync paused: push to '$remote' failed. Resolve the remote issue, then restart this task."
                exit 1
            fi
            for extra in "${extra_remotes[@]}"; do
                [[ "$extra" == "$remote" ]] && continue
                if git remote get-url "$extra" >/dev/null 2>&1; then
                    git push "$extra" "$branch" || print "Auto-sync warning: push to '$extra' failed."
                fi
            done
        fi
    fi
    sleep 5
done
