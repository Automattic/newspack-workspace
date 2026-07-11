#!/bin/bash
set -euo pipefail
BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$BIN/lib/common.sh"
require jq
LEDGER="$BIN/ledger.sh"

[ "${1:-}" = "create" ] || die "usage: pr.sh create <run_id> --title <t> --body-file <f>"
run_id="${2:?}"; shift 2
title=""; body_file=""
while [ $# -gt 0 ]; do case "$1" in
  --title) title="$2"; shift 2 ;;
  --body-file) body_file="$2"; shift 2 ;;
  *) die "unknown flag: $1" ;;
esac; done
[ -n "$title" ] && [ -n "$body_file" ] || die "--title and --body-file required"

# 1. redaction gate BEFORE any disclosure
bash "$BIN/redact.sh" scan "$body_file" || die "redaction findings in PR body — fix and retry"

# 2. attempt cap
attempts="$("$LEDGER" get "$run_id" '.attempts.pr')"
if [ "$attempts" -ge "$AUTOFIX_MAX_ATTEMPTS" ]; then
  "$LEDGER" set "$run_id" '.terminal = "escalated"'
  die "PR attempts exhausted ($attempts) — escalating"
fi
"$LEDGER" set "$run_id" '.attempts.pr += 1'

branch="$("$LEDGER" get "$run_id" .branch)"
wt="$WORKSPACE_ROOT/worktrees/$branch"
cd "$wt" 2>/dev/null || cd "$WORKSPACE_ROOT"

# 3–4. push + draft PR
git push -u origin "$branch"
url="$(gh pr create --draft --title "$title" --body-file "$body_file" --base main --head "$branch")"
num="$(printf '%s' "$url" | awk -F/ '{print $NF}')"

# 5. Copilot request (advisory; REST because gh pr view misses the bot)
gh api "repos/{owner}/{repo}/pulls/$num/requested_reviewers" \
  -f 'reviewers[]=copilot-pull-request-reviewer[bot]' >/dev/null 2>&1 \
  || log "Copilot review request failed (advisory — continuing)"

# 6. record
"$LEDGER" set "$run_id" '.pr = {url:$u, number:($n|tonumber)} | .terminal = "delivered"' \
  --arg u "$url" --arg n "$num"
"$LEDGER" history "$run_id" pr delivered "$url"
echo "$url"
