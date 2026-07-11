#!/bin/bash
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
require jq

usage() { die "usage: ledger.sh init|path|get|set|history|drift|evidence|reclaim <run_id> ..."; }
[ $# -ge 2 ] || usage
cmd="$1"; run_id="$2"; shift 2
dir="$RUNS_DIR/$run_id"; file="$dir/ledger.json"; lockdir="$dir/.lock"

write_owner() { printf '%s %s %s\n' "$$" "$(hostname)" "$(now_utc)" > "$lockdir/owner"; }

take_lock() {
  mkdir -p "$dir"
  if mkdir "$lockdir" 2>/dev/null; then write_owner; return 0; fi
  local pid host
  read -r pid host _ < "$lockdir/owner" 2>/dev/null || { pid="?"; host="?"; }
  die "run $run_id locked (pid=$pid host=$host); use 'ledger.sh reclaim $run_id' if that process is dead"
}
drop_lock() { rm -f "$lockdir/owner"; rmdir "$lockdir" 2>/dev/null || true; }

mutate() { # jq-program [extra jq args...]
  local prog="$1"; shift
  take_lock; trap drop_lock EXIT
  local tmp; tmp="$(mktemp)"
  jq "$@" "$prog" "$file" > "$tmp"
  mv "$tmp" "$file"
  drop_lock; trap - EXIT
}

case "$cmd" in
  init)
    issue="${1:?issue}"; mode="${2:?mode}"
    mkdir -p "$dir"
    [ -f "$file" ] && die "ledger already exists: $file"
    jq -n --arg run_id "$run_id" --arg issue "$issue" --arg mode "$mode" '{
      run_id:$run_id, issue:$issue, mode:$mode, stage:"intake",
      stage_history:[], decisions:[], linear_prior:null, evidence:[],
      env:null, branch:null, pr:null,
      loop_counts:{fix_iterations:0, repro_hypotheses:0}, loop_started_at:null,
      attempts:{provisioning:0, pr:0}, drift_log:[], terminal:null
    }' > "$file"
    echo "$file" ;;
  path) echo "$file" ;;
  get) jq -r "${1:?jq filter}" "$file" ;;
  set) mutate "$@" ;;
  history)
    stage="${1:?}"; outcome="${2:?}"; notes="${3:-}"
    mutate '.stage_history += [{stage:$s, outcome:$o, at:$t, notes:$n}]' \
      --arg s "$stage" --arg o "$outcome" --arg t "$(now_utc)" --arg n "$notes" ;;
  drift)
    field="${1:?}"; expected="${2:?}"; actual="${3:?}"
    mutate '.drift_log += [{field:$f, expected:$e, actual:$a, at:$t}]' \
      --arg f "$field" --arg e "$expected" --arg a "$actual" --arg t "$(now_utc)" ;;
  evidence)
    kind="${1:?}"; path="${2:?}"; ecmd="${3:-}"
    mutate '.evidence += [{kind:$k, path:$p, cmd:$c, captured_at:$t}]' \
      --arg k "$kind" --arg p "$path" --arg c "$ecmd" --arg t "$(now_utc)" ;;
  reclaim)
    [ -d "$lockdir" ] || { log "no lock on $run_id"; exit 0; }
    pid=""; host=""
    read -r pid host _ < "$lockdir/owner" 2>/dev/null || true
    if [ "${1:-}" = "--force" ] || { [ "$host" = "$(hostname)" ] && [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; }; then
      log "reclaiming lock on $run_id (was pid=$pid host=$host)"
      drop_lock
    else
      die "lock owner pid=$pid host=$host may be alive; use --force to override"
    fi ;;
  *) usage ;;
esac
