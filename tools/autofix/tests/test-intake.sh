#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
I=../bin/intake.sh
newmock() { M="$(mktemp -d)"; cp "fixtures/$1" "$M/issue.json" 2>/dev/null || true; cp fixtures/queue.json "$M/queue.json" 2>/dev/null || true; export AUTOFIX_LINEAR_MOCK_DIR="$M"; }

newmock issue_ok.json
out="$(bash "$I" check NPPM-2993)"; rc=$?
assert_eq 0 "$rc" "clean issue passes"
assert_contains "$out" nppm-2993-bug "summary carries branchName"

newmock issue_security.json
out="$(bash "$I" check NPPM-2993 2>&1)" && rc=0 || rc=$?
assert_eq 2 "$rc" "security label blocks in every mode"
assert_contains "$out" SECURITY-LABELED "security reason printed"

newmock issue_pr.json
out="$(bash "$I" check NPPM-2993 2>&1)" && rc=0 || rc=$?
assert_eq 3 "$rc" "existing PR blocks"
bash "$I" check NPPM-2993 --allow-existing-pr >/dev/null 2>&1
assert_eq 0 $? "--allow-existing-pr overrides"

newmock issue_ok.json
out="$(bash "$I" queue --dry-run)"
first="$(printf '%s\n' "$out" | head -1)"
assert_contains "$first" NPPM-150 "urgent+oldest sorts first"
assert_contains "$out" "3 candidate(s)" "candidate count"
finish
