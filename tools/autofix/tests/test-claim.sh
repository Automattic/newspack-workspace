#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
C=../bin/claim.sh; L=../bin/ledger.sh
setup() { # $1 = postclaim fixture, $2 = run id
  export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp fixtures/viewer.json fixtures/states.json fixtures/issueUpdate.json fixtures/commentCreate.json "$M/"
  cp fixtures/issue_ok.json "$M/issue.json"
  # fixtures are templated on RUNID: the mock returns one static body per
  # opname, so the run-specific claim comment is substituted in at setup time
  sed "s/RUNID/$2/" "fixtures/$1" > "$M/issue_postclaim.json"
  bash "$L" init "$2" NPPM-2993 operator-named >/dev/null
}

setup issue_postclaim_ok.json run1
bash "$C" claim NPPM-2993 run1
assert_eq 0 $? "clean claim succeeds"
assert_eq Backlog "$(bash "$L" get run1 '.linear_prior.stateName')" "prior state recorded"

setup issue_postclaim_lost.json run2
bash "$C" claim NPPM-2993 run2 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 5 "$rc" "lost race exits 5"
assert_contains "$(grep issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" | tail -1)" \
  7fad47f0 "lost race restored prior state (back-off wrote Backlog stateId)"

# same-issue guard: second run against an already-active issue refuses
setup issue_postclaim_ok.json run3
bash "$C" claim NPPM-2993 run3 >/dev/null
bash "$L" init run4 NPPM-2993 operator-named >/dev/null
bash "$C" claim NPPM-2993 run4 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 4 "$rc" "same-issue guard exits 4"

# conditional release: human took over mid-run → no restore, drift logged
setup issue_postclaim_ok.json run5
bash "$C" claim NPPM-2993 run5 >/dev/null
cp fixtures/issue_release_humanized.json "$AUTOFIX_LINEAR_MOCK_DIR/issue_release.json"
bash "$C" release NPPM-2993 run5 >/dev/null 2>&1
assert_contains "$(bash "$L" get run5 '.drift_log[0].field')" assignee "humanized assignee → drift, not overwrite"

# ownership is run-specific: assignee matches but the claim comment belongs
# to a DIFFERENT run → this run must treat the claim as lost
setup issue_postclaim_other.json run6
bash "$C" claim NPPM-2993 run6 >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 5 "$rc" "other run's claim comment fails ownership (exit 5)"

# conditional release: human moved the state mid-run (In Review) → release
# must NOT force it back; drift logged instead
setup issue_postclaim_ok.json run7
bash "$C" claim NPPM-2993 run7 >/dev/null
sed "s/RUNID/run7/" fixtures/issue_release_statemoved.json > "$AUTOFIX_LINEAR_MOCK_DIR/issue_release.json"
bash "$C" release NPPM-2993 run7 >/dev/null 2>&1
last_update="$(grep issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" | tail -1)"
assert_eq 0 "$(printf '%s' "$last_update" | grep -c stateId)" "moved state not re-forced on release"
assert_contains "$(bash "$L" get run7 '.drift_log[0].field')" stateId "moved state → drift logged"
finish
