#!/bin/bash
# SMART ASSESS - keeps the AI egress filters loaded, or stops what they protect (fail closed).
#
# NOT INSTALLED. Install (with approval) as root:root 0755 at /usr/local/sbin/smart-assess-ai-egress-guard.sh,
# with smart-assess-ai-egress-guard.service/.timer (every 30 s) and the nftables.service drop-in
# 50-smart-assess-ai-egress.conf (runs this right after nftables.service starts, reloads or stops,
# because its /etc/nftables.conf begins with "flush ruleset", which removes every table).
#
# For each filter whose rules file is installed: reload it (the files replace their own table in one
# atomic transaction, so a reload never opens a gap), then check the table is there. If it cannot be
# restored, stop the services it protects, so nothing runs unfiltered, and exit non-zero.
#   inet sa_ai_worker_egress   the local AI worker (Step 5), file /etc/smart-assess-ai-worker-runtime/worker-egress.nft
#   inet sa_ai_dispatch_egress the AI dispatcher,           file /usr/local/share/smart-assess/ai-dispatch-egress.nft
# Window: between a flush and the next run (at most about 30 s, or none when nftables.service did it).
set -uo pipefail
status=0
guard() {
    local table=$1 file=$2; shift 2
    [[ -f "$file" ]] || return 0   # that filter is not installed on this server
    if ! nft -f "$file" 2>/dev/null || ! nft list table inet "$table" >/dev/null 2>&1; then
        echo "CRITICAL: the egress filter inet $table could not be restored from $file; stopping: $*" >&2
        systemctl stop "$@" 2>/dev/null
        status=1
    fi
}
guard sa_ai_worker_egress /etc/smart-assess-ai-worker-runtime/worker-egress.nft \
    smart-assess-ai-worker.service smart-assess-ai-worker-fpm.service
guard sa_ai_dispatch_egress /usr/local/share/smart-assess/ai-dispatch-egress.nft \
    smart-assess-ai-dispatch.timer smart-assess-ai-dispatch.service
exit "$status"
