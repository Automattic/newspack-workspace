#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
V=../bin/verify.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1"

bash "$L" init runv NPPM-1 operator-named >/dev/null
bash "$L" set runv '.branch = "br-1"'
bash "$L" evidence runv failing-test t.php 'exit 1'

bash "$V" signal runv --expect fail
assert_eq 0 $? "--expect fail passes while signal fails"
bash "$V" signal runv --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "--expect pass fails while signal fails"

bash "$L" evidence runv fixed t2.php 'exit 0'
bash "$V" signal runv --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "mixed signals: any failing cmd fails --expect pass"
finish
