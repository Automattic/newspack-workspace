#!/bin/bash
set -euo pipefail
BIN="$(dirname "${BASH_SOURCE[0]}")"
. "$BIN/lib/common.sh"
require jq
require git
LEDGER="$BIN/ledger.sh"

cmd="${1:?usage: env.sh create <run_id> <repo> [-- <setup flags>] | destroy <run_id> [--waive-push-check]}"
run_id="${2:?run id}"; shift 2

hex4() { printf '%s' "$run_id" | awk -F- '{print $NF}'; }

case "$cmd" in
  create)
    repo="${1:?repo}"; shift
    [ "${1:-}" = "--" ] && shift
    issue="$("$LEDGER" get "$run_id" .issue | tr '[:upper:]' '[:lower:]')"
    stem="$("$LEDGER" get "$run_id" '.decisions[] | select(.key=="branch_stem") | .value')"
    [ -n "$stem" ] || die "no branch_stem decision in ledger (Stage 1 must set it from Linear branchName)"
    attempts="$("$LEDGER" get "$run_id" '.attempts.provisioning')"
    if [ "$attempts" -ge "$AUTOFIX_MAX_ATTEMPTS" ]; then
      "$LEDGER" set "$run_id" '.terminal = "escalated"'
      die "provisioning attempts exhausted ($attempts) — escalating"
    fi
    "$LEDGER" set "$run_id" '.attempts.provisioning += 1'
    name="autofix-$issue-$(hex4)"
    branch="$stem-$(hex4)"
    n env create "$name" --worktree "$repo:$branch" --up || die "n env create failed (attempt $((attempts+1)))"
    n setup --env "$name" --yes "$@" || die "n setup failed (attempt $((attempts+1)))"
    "$LEDGER" set "$run_id" '.env = {name:$n, worktrees:[$w]} | .branch = $b' \
      --arg n "$name" --arg w "$repo:$branch" --arg b "$branch"
    "$LEDGER" history "$run_id" reproduce env-created "$name"
    echo "$name" ;;
  destroy)
    waive="${1:-}"
    name="$("$LEDGER" get "$run_id" '.env.name // empty')"
    [ -n "$name" ] || { log "no env recorded for $run_id"; exit 0; }
    branch="$("$LEDGER" get "$run_id" '.branch // empty')"
    wt="$WORKSPACE_ROOT/worktrees/$branch"
    if [ -n "$branch" ]; then
      if [ -d "$wt" ]; then
        if ! git -C "$wt" tag -a "autofix-anchor-$run_id" -m "pre-destroy anchor for $run_id" 2>/dev/null; then
          # A retried destroy is fine if the anchor already exists; anything else fails closed.
          git -C "$wt" rev-parse -q --verify "refs/tags/autofix-anchor-$run_id" >/dev/null 2>&1 \
            || die "failed to create anchor tag autofix-anchor-$run_id in $wt"
        fi
        if [ "$waive" != "--waive-push-check" ] && \
           [ -z "$(git -C "$wt" ls-remote origin "refs/heads/$branch" 2>/dev/null)" ]; then
          die "branch $branch not pushed; push it or pass --waive-push-check (n env destroy deletes the branch)"
        fi
      elif [ "$waive" != "--waive-push-check" ]; then
        die "worktree $wt missing for recorded branch $branch; verify the branch is pushed to origin, then re-run with --waive-push-check (n env destroy deletes the bound branch)"
      else
        log "worktree $wt missing; proceeding without anchor tag (--waive-push-check)"
      fi
    fi
    n env destroy "$name" --yes
    "$LEDGER" history "$run_id" cleanup env-destroyed "$name" ;;
  *) die "unknown subcommand: $cmd" ;;
esac
