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
echo "git $*" >> "${STUB_LOG:?}"; exit 0
EOF
cat > "$STUB/gh" <<'EOF'
#!/bin/bash
echo "gh $*" >> "${STUB_LOG:?}"
case "$1" in pr) echo "https://github.com/Automattic/newspack-workspace/pull/999" ;; esac
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

# redaction blocks before push
bash "$L" init runq NPPM-2 operator-named >/dev/null
bash "$L" set runq '.branch = "br-2"'
: > "$STUB_LOG"
echo "creds: https://mc.a8c.com/secret-store/?secret_id=1" > "$BODY"
bash "$P" create runq --title t --body-file "$BODY" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "redaction finding aborts"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "nothing pushed on redaction failure"
finish
