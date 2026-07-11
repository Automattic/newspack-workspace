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
require() { command -v "$1" >/dev/null 2>&1 || die "missing dependency: $1"; }
json_escape() { printf '%s' "$1" | jq -Rs .; }
