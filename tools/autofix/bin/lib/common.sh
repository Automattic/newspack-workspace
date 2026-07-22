#!/bin/bash
# common.sh — shared config + helpers for autofix scripts. Source, don't execute.
# shellcheck disable=SC2034
set -o pipefail

AUTOFIX_ROOT="${AUTOFIX_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
RUNS_DIR="$AUTOFIX_ROOT/runs"
# Directory of the autofix scripts themselves (bin/), resolved from THIS file's
# location — not from AUTOFIX_ROOT, which is overridable (tests point it at a
# bare temp dir). Helpers that shell out to a sibling script use this.
AUTOFIX_BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
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

# ---- secure mode (autofix-secure) --------------------------------------------
# See _tooling/specs/2026-07-22-autofix-secure-bin-tooling-spec.md.

# is_secure <run_id> — is this a secure run? FAIL CLOSED: any ambiguous or failed
# ledger read is treated as secure, so a transient error can never silently
# disable a gate. Only a clean, explicit `false` is "not secure". An older
# ledger with no `.secure` field reads `false` (via `// false`) and behaves as a
# normal run; a missing/corrupt ledger makes `get` exit non-zero → secure.
is_secure() { # run_id
  local v
  v="$(bash "$AUTOFIX_BIN_DIR/ledger.sh" get "$1" '.secure // false' 2>/dev/null)" \
    || { log "is_secure: ledger read failed for $1 — treating as SECURE (fail-closed)"; return 0; }
  case "$v" in
    false) return 1 ;;
    true)  return 0 ;;
    *)     log "is_secure: unexpected .secure='$v' for $1 — treating as SECURE (fail-closed)"; return 0 ;;
  esac
}

# secure_digest <file> — sha256 over a file's exact bytes (the canonical artifact
# an operator approves). Portable across shasum (macOS) and sha256sum (Linux).
secure_digest() { # file
  if command -v shasum >/dev/null 2>&1; then shasum -a 256 "$1" | awk '{print $1}'
  elif command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
  else die "no sha256 tool (shasum/sha256sum) for secure digest"; fi
}

# secure_gate <run_id> <stage> <artifact_file> <confirmed_digest_or_empty>
# The disclosing-write gate. Non-secure run: returns 0 (proceed) — base autofix
# is unchanged. Secure run:
#   - with a confirmed digest that MATCHES the current artifact: logs a
#     `confirmed` decision and returns 0 (proceed to the real write).
#   - with a stale/mismatched confirmed digest: dies (artifact changed since
#     preview — the operator approved different bytes; re-preview).
#   - with no confirmation: writes the full artifact to a run-dir preview file,
#     prints a redacted `GATED:` summary to stdout (digest + preview path, never
#     the payload), logs a `preview` decision, and exits 7.
secure_gate() { # run_id stage artifact_file confirmed
  local rid="$1" stage="$2" art="$3" confirmed="${4:-}"
  is_secure "$rid" || return 0
  local digest; digest="$(secure_digest "$art")"
  local ledger="$AUTOFIX_BIN_DIR/ledger.sh"
  if [ -n "$confirmed" ]; then
    [ "$confirmed" = "$digest" ] \
      || die "confirmation digest mismatch for '$stage' (artifact changed since preview: got $confirmed, want $digest) — re-preview and re-confirm"
    bash "$ledger" history "$rid" "$stage" confirmed "digest=$digest" >/dev/null
    return 0
  fi
  local pvdir="$RUNS_DIR/$rid/previews"; mkdir -p "$pvdir"
  local pv="$pvdir/$stage-$digest.txt"
  cp "$art" "$pv"
  local bytes; bytes="$(wc -c < "$art" | tr -d ' ')"
  bash "$ledger" history "$rid" "$stage" preview "digest=$digest bytes=$bytes file=$pv" >/dev/null
  printf 'GATED: %s %s\n' "$digest" "$pv"
  log "gated ($stage): awaiting operator confirmation — full artifact at $pv (digest $digest, ${bytes}B)"
  exit 7
}
