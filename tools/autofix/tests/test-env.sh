#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
E=../bin/env.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
STUB="$(mktemp -d)"; export PATH="$STUB:$PATH"
cat > "$STUB/n" <<'EOF'
#!/bin/bash
echo "n $*" >> "${N_LOG:?}"
exit "${N_EXIT:-0}"
EOF
chmod +x "$STUB/n"
export N_LOG="$STUB/n.log"; : > "$N_LOG"

bash "$L" init run-a NPPM-2993 operator-named >/dev/null
bash "$L" set run-a '.decisions += [{key:"branch_stem", value:"nppm-2993-bug-jetpack"}]'

bash "$E" create run-a newspack-multibranded-site -- --block-theme
assert_contains "$(cat "$N_LOG")" "env create autofix-nppm-2993-" "n env create called with derived name"
assert_contains "$(cat "$N_LOG")" "--worktree newspack-multibranded-site:nppm-2993-bug-jetpack-" "worktree branch carries 4hex suffix"
assert_contains "$(cat "$N_LOG")" "setup --env autofix-nppm-2993-" "n setup called"
assert_contains "$(bash "$L" get run-a '.env.name')" autofix-nppm-2993 "ledger records env"
assert_eq 1 "$(bash "$L" get run-a '.attempts.provisioning')" "attempt counted"

# failure path: N_EXIT=1 twice more → third create attempt dies at cap
export N_EXIT=1
bash "$E" create run-a newspack-multibranded-site >/dev/null 2>&1 || true
bash "$E" create run-a newspack-multibranded-site >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "provisioning cap enforced"
assert_eq 3 "$(bash "$L" get run-a '.attempts.provisioning')" "attempts capped at 3"

# regression: at cap, create dies BEFORE running any n command (even if n would succeed)
unset N_EXIT
bash "$L" init run-b NPPM-2993 operator-named >/dev/null
bash "$L" set run-b '.decisions += [{key:"branch_stem", value:"nppm-2993-bug-jetpack"}]'
bash "$L" set run-b '.attempts.provisioning = 3'
lines_before="$(wc -l < "$N_LOG")"
bash "$E" create run-b newspack-multibranded-site >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "at-cap create dies even when n would succeed"
assert_eq "$lines_before" "$(wc -l < "$N_LOG")" "at-cap create runs no n command"

# regression: destroy with recorded branch but missing worktree dir dies, no n env destroy
log_before="$(cat "$N_LOG")"
bash "$E" destroy run-a >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "destroy dies when worktree dir missing for recorded branch"
assert_eq "$log_before" "$(cat "$N_LOG")" "missing-worktree destroy runs no n command"
finish
