#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
L=../bin/ledger.sh

bash "$L" init run1 NPPM-9999 operator-named >/dev/null
assert_eq NPPM-9999 "$(bash "$L" get run1 .issue)" "init stores issue"
assert_eq intake "$(bash "$L" get run1 .stage)" "init stage=intake"

bash "$L" set run1 '.stage="triage"'
assert_eq triage "$(bash "$L" get run1 .stage)" "set mutates stage"

bash "$L" history run1 intake claimed "ok"
assert_eq claimed "$(bash "$L" get run1 '.stage_history[0].outcome')" "history appends"

bash "$L" drift run1 assigneeId me someone-else
assert_eq assigneeId "$(bash "$L" get run1 '.drift_log[0].field')" "drift persists"

bash "$L" evidence run1 failing-test tests/x.php "n test-php --filter t"
assert_eq failing-test "$(bash "$L" get run1 '.evidence[0].kind')" "evidence appends"

# stale-lock reclaim: fabricate a dead-owner lock, then mutate (should reclaim implicitly? no — explicit)
mkdir -p "$AUTOFIX_ROOT/runs/run1/.lock"
printf '999999 %s %s\n' "$(hostname)" "2026-01-01T00:00:00Z" > "$AUTOFIX_ROOT/runs/run1/.lock/owner"
out=$(bash "$L" set run1 '.stage="fix"' 2>&1) && rc=0 || rc=$?
assert_eq 1 "$rc" "live-lock semantics: set refuses without reclaim"
bash "$L" reclaim run1 >/dev/null 2>&1
bash "$L" set run1 '.stage="fix"'
assert_eq fix "$(bash "$L" get run1 .stage)" "set works after reclaim"
finish
