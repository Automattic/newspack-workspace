#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
E=../bin/env.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
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

# CRITICAL 1 regression: a slashed branch's worktree lives at the SANITIZED
# path (n's safe_branch=$(tr '/' '-')) — destroy must find it there and run
# the anchor-tag + push-check safeguard (RAW branch ref for ls-remote), not
# take the missing-worktree death path.
ORIGIN="$(mktemp -d)"; git init --bare -q "$ORIGIN"
SLASH_WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-1-fix"
mkdir -p "$SLASH_WT"
( cd "$SLASH_WT" && git init -q \
    && git -c user.email=t@example.com -c user.name=t commit --allow-empty -qm init \
    && git remote add origin "$ORIGIN" \
    && git push -q origin HEAD:refs/heads/jason/nppm-1-fix )

bash "$L" init run-slash NPPM-9 operator-named >/dev/null
bash "$L" set run-slash '.env = {name:"autofix-env-slash"}'
bash "$L" set run-slash '.branch = "jason/nppm-1-fix"'
log_before="$(cat "$N_LOG")"
bash "$E" destroy run-slash >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 0 "$rc" "slashed branch: destroy succeeds (worktree found at sanitized path)"
assert_contains "$(git -C "$SLASH_WT" tag -l)" "autofix-anchor-run-slash" \
  "slashed branch: anchor tag created in the sanitized worktree dir"
assert_contains "$(cat "$N_LOG")" "env destroy autofix-env-slash --yes" \
  "slashed branch: n env destroy invoked (push-check passed via RAW branch ls-remote)"
finish
