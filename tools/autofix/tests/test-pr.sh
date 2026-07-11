#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
P=../bin/pr.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/br-2"
STUB="$(mktemp -d)"; export PATH="$STUB:$PATH"
cat > "$STUB/git" <<'EOF'
#!/bin/bash
echo "git $* (pwd=$PWD)" >> "${STUB_LOG:?}"; exit 0
EOF
cat > "$STUB/gh" <<'EOF'
#!/bin/bash
echo "gh $*" >> "${STUB_LOG:?}"
case "$1 $2" in
  "pr list") printf '%s\n' "${GH_PR_LIST_OUT:-}" ;;
  "pr create") echo "https://github.com/Automattic/newspack-workspace/pull/999" ;;
esac
exit 0
EOF
chmod +x "$STUB/git" "$STUB/gh"
export STUB_LOG="$STUB/log"; : > "$STUB_LOG"

bash "$L" init runp NPPM-1 operator-named >/dev/null
bash "$L" set runp '.branch = "br-2"'
BODY="$(mktemp)"; echo "Fixes the bug. Evidence attached." > "$BODY"

bash "$P" create runp --title "fix(x): y (NPPM-1)" --body-file "$BODY"
assert_contains "$(cat "$STUB_LOG")" "push -u origin br-2" "branch pushed"
assert_contains "$(cat "$STUB_LOG")" "pr create --draft" "draft PR created"
assert_contains "$(cat "$STUB_LOG")" copilot-pull-request-reviewer "Copilot requested via REST"
assert_eq delivered "$(bash "$L" get runp .terminal)" "terminal=delivered"
assert_contains "$(bash "$L" get runp .pr.url)" /pull/999 "PR url recorded"
assert_eq 999 "$(bash "$L" get runp .pr.number)" "PR number derived from URL"

# redaction blocks before push
bash "$L" init runq NPPM-2 operator-named >/dev/null
bash "$L" set runq '.branch = "br-2"'
: > "$STUB_LOG"
echo "creds: https://mc.a8c.com/secret-store/?secret_id=1" > "$BODY"
bash "$P" create runq --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "redaction finding aborts"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "nothing pushed on redaction failure"

# adoption: an existing open PR for the branch is adopted, not re-created
bash "$L" init runr NPPM-3 operator-named >/dev/null
bash "$L" set runr '.branch = "br-2"'
: > "$STUB_LOG"
echo "Fixes the bug. Evidence attached." > "$BODY"
export GH_PR_LIST_OUT='{"url":"https://github.com/Automattic/newspack-workspace/pull/321","number":321,"isDraft":true}'
bash "$P" create runr --title t --body-file "$BODY" >/dev/null
unset GH_PR_LIST_OUT
assert_contains "$(cat "$STUB_LOG")" "push -u origin br-2" "adoption still pushes first"
assert_eq "" "$(grep 'pr create' "$STUB_LOG" || true)" "existing PR not re-created"
assert_eq 321 "$(bash "$L" get runr .pr.number)" "existing PR number adopted"
assert_eq delivered "$(bash "$L" get runr .terminal)" "adoption reaches delivered"
assert_contains "$(bash "$L" get runr '.stage_history[-1].notes')" "adopted existing PR" "adoption noted in history"

# attempt cap: at 3 attempts, die before any push/create, terminal=escalated
bash "$L" init runs NPPM-4 operator-named >/dev/null
bash "$L" set runs '.branch = "br-2"'
bash "$L" set runs '.attempts.pr = 3'
: > "$STUB_LOG"
bash "$P" create runs --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "at-cap create dies"
assert_eq "" "$(cat "$STUB_LOG")" "at-cap create runs no git/gh command"
assert_eq escalated "$(bash "$L" get runs .terminal)" "at-cap sets terminal=escalated"

# missing worktree: fail fast, nothing pushed
bash "$L" init runt NPPM-5 operator-named >/dev/null
bash "$L" set runt '.branch = "br-gone"'
: > "$STUB_LOG"
bash "$P" create runt --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree dies"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "missing worktree: nothing pushed"

# CRITICAL 1 regression: a slashed branch's worktree lives at the SANITIZED
# path on disk (n's safe_branch=$(tr '/' '-')), but the push/PR ops must
# still carry the RAW branch ref
SLASH_WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-1-fix"
mkdir -p "$SLASH_WT"
bash "$L" init runslash NPPM-6 operator-named >/dev/null
bash "$L" set runslash '.branch = "jason/nppm-1-fix"'
: > "$STUB_LOG"
echo "Fixes the bug. Evidence attached." > "$BODY"
bash "$P" create runslash --title t --body-file "$BODY" >/dev/null
assert_contains "$(cat "$STUB_LOG")" "push -u origin jason/nppm-1-fix" \
  "slashed branch: push carries the RAW branch ref, not the sanitized dir name"
assert_contains "$(cat "$STUB_LOG")" "(pwd=$SLASH_WT)" \
  "slashed branch: cd landed in the sanitized worktree dir"
assert_eq delivered "$(bash "$L" get runslash .terminal)" "slashed branch: create reaches delivered"
finish
