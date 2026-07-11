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

# missing worktree dir → signal dies fail-closed (never a false pass)
bash "$L" init runw NPPM-2 operator-named >/dev/null
bash "$L" set runw '.branch = "no-such-branch"'
bash "$L" evidence runw failing-test t.php 'exit 1'
bash "$V" signal runw --expect fail >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree: signal dies (--expect fail)"
bash "$V" signal runw --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree: signal dies (--expect pass, no false pass)"

# evidence entries all with empty cmd → signal dies (nothing effective ran)
bash "$L" init rune NPPM-3 operator-named >/dev/null
bash "$L" set rune '.branch = "br-1"'
bash "$L" evidence rune note t.php ''
bash "$L" evidence rune note t2.php ''
bash "$V" signal rune --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "all-empty-cmd evidence: signal dies"

# lint smoke: worktree repo with no changed PHP files vs merge-base → exit 0
WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1"
git -C "$WT" init -q
( cd "$WT" && echo hi > readme.txt && git add readme.txt \
  && git -c user.email=t@example.com -c user.name=t commit -qm init \
  && git update-ref refs/remotes/origin/main HEAD )
bash "$V" lint runv >/dev/null 2>&1
assert_eq 0 $? "lint: no changed PHP files exits 0"

# suite smoke: n test-php from plugins/<affected_repo>; no test-js without script
STUB="$(mktemp -d)"; export PATH="$STUB:$PATH"
cat > "$STUB/n" <<'EOF'
#!/bin/bash
echo "$PWD n $*" >> "${N_LOG:?}"
exit 0
EOF
chmod +x "$STUB/n"
export N_LOG="$STUB/n.log"; : > "$N_LOG"
bash "$L" set runv '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
mkdir -p "$WT/plugins/my-plugin"
printf '{"scripts":{"test":"noop"}}\n' > "$WT/plugins/my-plugin/package.json"
bash "$V" suite runv >/dev/null 2>&1
assert_eq 0 $? "suite exits 0"
assert_contains "$(cat "$N_LOG")" "plugins/my-plugin n test-php" "n test-php runs from plugin dir"
assert_eq 0 "$(grep -c 'test-js' "$N_LOG")" "n test-js not invoked without test:js script"

# CRITICAL 1 regression: a branch containing '/' (a Linear branchName like
# "jason/nppm-1-fix") lives at the SANITIZED path on disk — `n` runs
# safe_branch=$(tr '/' '-') when it names the worktree dir — not at a naive
# WORKSPACE_ROOT/worktrees/<raw-branch> join.
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-1-fix"
bash "$L" init runslash NPPM-9 operator-named >/dev/null
bash "$L" set runslash '.branch = "jason/nppm-1-fix"'
bash "$L" evidence runslash failing-test t.php 'exit 1'
bash "$V" signal runslash --expect fail
assert_eq 0 $? "slashed branch: signal finds worktree at the sanitized (dash) path"
finish
