#!/bin/bash
set -euo pipefail
BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$BIN/lib/common.sh"
require jq
require git
require gh
LEDGER="$BIN/ledger.sh"

[ "${1:-}" = "create" ] || die "usage: pr.sh create <run_id> --title <t> --body-file <f> [--confirmed=<digest>] [--no-copilot]"
run_id="${2:?}"; shift 2
title=""; body_file=""; confirmed=""; no_copilot=""
while [ $# -gt 0 ]; do case "$1" in
  --title) title="$2"; shift 2 ;;
  --body-file) body_file="$2"; shift 2 ;;
  --confirmed) confirmed="$2"; shift 2 ;;
  --confirmed=*) confirmed="${1#*=}"; shift ;;
  --no-copilot) no_copilot=1; shift ;;
  *) die "unknown flag: $1" ;;
esac; done
[ -n "$title" ] && [ -n "$body_file" ] || die "--title and --body-file required"

# 1. redaction gate BEFORE any disclosure
bash "$BIN/redact.sh" scan "$body_file" || die "redaction findings in PR body — fix and retry"

branch="$("$LEDGER" get "$run_id" '.branch // empty')"
[ -n "$branch" ] || die "no branch recorded in ledger for $run_id"
wt="$(wt_dir "$branch")"
[ -d "$wt" ] || die "worktree missing: $wt"
cd "$wt"

# 2. disclosing-write gate (secure runs only). The artifact the operator approves
# is the RESOLVED PR body PLUS the commit diff that would be pushed to a
# public-capable surface — for a Security fix, that diff IS the disclosure. A
# preview writes it to the run dir; without a matching --confirmed=<digest> this
# exits 7 with no push, no PR, no Copilot. Non-secure runs skip straight past.
if is_secure "$run_id"; then
  art="$(mktemp)"
  {
    printf '### PR title\n%s\n\n### PR body\n' "$title"
    cat "$body_file"
    printf '\n\n### Commit diff (git diff main...HEAD) — pushed to github.com\n'
    git diff main...HEAD
  } > "$art"
  secure_gate "$run_id" pr "$art" "$confirmed"   # preview+exit7, or verify digest & return
fi

# 3. attempt cap — counted only for a REAL create attempt (a gated preview above
# exits before here, so it never consumes an attempt).
attempts="$("$LEDGER" get "$run_id" '.attempts.pr')"
if [ "$attempts" -ge "$AUTOFIX_MAX_ATTEMPTS" ]; then
  "$LEDGER" set "$run_id" '.terminal = "escalated"'
  die "PR attempts exhausted ($attempts) — escalating"
fi
"$LEDGER" set "$run_id" '.attempts.pr += 1'

# 4. push (idempotent — branch may carry new commits even when a PR exists)
git push -u origin "$branch"

# 5. adopt an existing open PR for this branch, or create a draft PR
existing="$(gh pr list --head "$branch" --state open --json url,number,isDraft --jq '.[0]' 2>/dev/null || true)"
if [ -n "$existing" ] && [ "$existing" != "null" ]; then
  url="$(printf '%s' "$existing" | jq -r '.url // empty')"
  num="$(printf '%s' "$existing" | jq -r '.number // empty')"
  [ -n "$url" ] && [ -n "$num" ] || die "could not parse existing PR from: $existing"
  log "adopting existing open PR #$num for $branch"
  history_note="adopted existing PR $url"
else
  create_out="$(gh pr create --draft --title "$title" --body-file "$body_file" --base main --head "$branch")"
  url="$(printf '%s\n' "$create_out" | grep -Eo 'https://[^[:space:]]+/pull/[0-9]+' | tail -1)"
  [ -n "$url" ] || die "could not parse PR URL from gh pr create output: $create_out"
  num="${url##*/}"
  history_note="$url"
fi

# 6. Copilot request (advisory; REST because gh pr view misses the bot).
# --no-copilot (spec Override 2) declines it; the decision is logged either way.
if [ -n "$no_copilot" ]; then
  "$LEDGER" history "$run_id" pr no-copilot "operator declined Copilot review request"
  log "Copilot review request skipped (--no-copilot)"
else
  gh api "repos/{owner}/{repo}/pulls/$num/requested_reviewers" \
    -f 'reviewers[]=copilot-pull-request-reviewer[bot]' >/dev/null 2>&1 \
    || log "Copilot review request failed (advisory — continuing)"
fi

# 7. record
"$LEDGER" set "$run_id" '.pr = {url:$u, number:($n|tonumber)} | .terminal = "delivered"' \
  --arg u "$url" --arg n "$num"
"$LEDGER" history "$run_id" pr delivered "$history_note"
echo "$url"
