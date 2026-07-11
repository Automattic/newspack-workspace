#!/bin/bash
set -euo pipefail
BIN="$(dirname "${BASH_SOURCE[0]}")"
. "$BIN/lib/common.sh"
require jq
LEDGER="$BIN/ledger.sh"

cmd="${1:?usage: verify.sh signal|lint|suite <run_id> [flags]}"; run_id="${2:?}"; shift 2
branch="$("$LEDGER" get "$run_id" '.branch // empty')"
wt="$WORKSPACE_ROOT/worktrees/$branch"

case "$cmd" in
  signal)
    [ "${1:-}" = "--expect" ] || die "signal requires --expect pass|fail"
    expect="${2:?pass|fail}"
    count="$("$LEDGER" get "$run_id" '.evidence | length')"
    [ "$count" -gt 0 ] || die "no evidence entries to run"
    i=0; bad=0
    while [ "$i" -lt "$count" ]; do
      ecmd="$("$LEDGER" get "$run_id" ".evidence[$i].cmd")"
      if [ -n "$ecmd" ] && [ "$ecmd" != "null" ]; then
        if (cd "$wt" 2>/dev/null || cd /tmp; bash -c "$ecmd") >/dev/null 2>&1; then st=pass; else st=fail; fi
        log "evidence[$i] '$ecmd' → $st"
        [ "$st" = "$expect" ] || bad=1
      fi
      i=$((i+1))
    done
    [ "$bad" = 0 ] || { log "signal check failed (expected all to $expect)"; exit 1; }
    log "all signals $expect as expected" ;;
  lint)
    [ -d "$wt" ] || die "worktree missing: $wt"
    base="$(git -C "$wt" merge-base origin/main HEAD)"
    changed="$(git -C "$wt" diff --name-only "$base"...HEAD -- '*.php')"
    [ -n "$changed" ] || { log "no changed PHP files"; exit 0; }
    (cd "$wt" && "$WORKSPACE_ROOT/vendor/bin/phpcs" --standard="$WORKSPACE_ROOT/phpcs.xml" $changed) ;;
  suite)
    plugin_dir="$wt/$("$LEDGER" get "$run_id" '.decisions[] | select(.key=="affected_repo") | .value' \
      | sed 's|^|plugins/|')"
    [ -d "$plugin_dir" ] || plugin_dir="$wt"
    (cd "$plugin_dir" && n test-php)
    if jq -e '.scripts["test:js"]' "$plugin_dir/package.json" >/dev/null 2>&1; then
      (cd "$plugin_dir" && n test-js)
    fi ;;
  *) die "unknown subcommand: $cmd" ;;
esac
