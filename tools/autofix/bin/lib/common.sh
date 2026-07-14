#!/bin/bash
# common.sh — shared config + helpers for autofix scripts. Source, don't execute.
# shellcheck disable=SC2034
set -o pipefail

AUTOFIX_ROOT="${AUTOFIX_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
RUNS_DIR="$AUTOFIX_ROOT/runs"
WORKSPACE_ROOT="${AUTOFIX_WORKSPACE_ROOT:-$(cd "$AUTOFIX_ROOT/../.." && pwd)}"

: "${AUTOFIX_TEAM:=Product Maintenance}"
: "${AUTOFIX_ELIGIBLE_STATUSES:=Backlog}"
: "${AUTOFIX_READY_LABEL:=np-agent-ready}"
: "${AUTOFIX_READY_LABEL_ID:=f0c48c5e-9a4c-4228-b325-5fe6b8c17442}"
: "${AUTOFIX_FAILED_LABEL:=np-agent-failed}"
: "${AUTOFIX_FAILED_LABEL_ID:=5de9635c-ac7a-4b00-ab5b-e7680f162cf8}"
: "${AUTOFIX_ESCALATED_ENV_TTL_DAYS:=14}"
: "${AUTOFIX_MAX_ATTEMPTS:=3}"

log() { printf '[autofix] %s\n' "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }
now_utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# GNU and BSD date disagree on how to parse and offset dates, and they disagree
# *unsafely*: on BSD, `-d` is a daylight-saving flag, not a parse flag. So detect
# the implementation rather than trying one and falling back on error.
date_is_gnu() { date --version >/dev/null 2>&1; }

# iso8601_to_epoch <ts> — parse "%Y-%m-%dT%H:%M:%SZ" (UTC) to epoch seconds.
# Returns non-zero and prints nothing if the timestamp is empty or unparseable;
# callers must NOT paper over that with a default, or a run's age silently
# becomes 0 and TTL sweeps stop reaping.
iso8601_to_epoch() {
  local ts="${1:-}"
  [ -n "$ts" ] || return 1
  if date_is_gnu; then
    date -u -d "$ts" +%s 2>/dev/null
  else
    date -u -j -f '%Y-%m-%dT%H:%M:%SZ' "$ts" +%s 2>/dev/null
  fi
}

# iso8601_days_ago <days> — an ISO-8601 UTC timestamp N days in the past.
iso8601_days_ago() {
  local days="${1:?days required}"
  if date_is_gnu; then
    date -u -d "-${days} days" +%Y-%m-%dT%H:%M:%SZ
  else
    date -u -v-"${days}"d +%Y-%m-%dT%H:%M:%SZ
  fi
}
require() { command -v "$1" >/dev/null 2>&1 || die "missing dependency: $1"; }
json_escape() { printf '%s' "$1" | jq -Rs .; }
# wt_dir <branch> — derive the on-disk worktree dir for a branch. `n`
# sanitizes slashes to dashes when it names the worktree dir (safe_branch=
# $(tr '/' '-')), so any raw branch containing '/' (e.g. a Linear branchName
# like "jason/nppm-1-fix") lives at a different path than a naive
# WORKSPACE_ROOT/worktrees/<branch> join. Callers must still use the RAW
# branch for git-ref operations (push, --head, ls-remote, tag) — only the
# on-disk path needs sanitizing.
wt_dir() { printf '%s/worktrees/%s' "$WORKSPACE_ROOT" "$(printf '%s' "$1" | tr '/' '-')"; }
