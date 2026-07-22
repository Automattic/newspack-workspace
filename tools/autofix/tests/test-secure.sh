#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
I=../bin/intake.sh; C=../bin/claim.sh; L=../bin/ledger.sh; P=../bin/pr.sh; A=../bin/autofix
digest() { shasum -a 256 "$1" | awk '{print $1}'; }
EMPTY_COMMENTS='{ "data": { "issue": { "id": "abc-123", "comments": { "nodes": [] } } } }'

# ---------------------------------------------------------------------------
# ledger: mode=secure sets .secure=true; validate agrees; base stays false
# ---------------------------------------------------------------------------
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
bash "$L" init secone NPPM-1 secure >/dev/null
assert_eq true "$(bash "$L" get secone .secure)" "init secure → .secure=true"
assert_eq secure "$(bash "$L" get secone .mode)" "init secure → .mode=secure"
assert_eq ok "$(bash "$L" validate secone)" "validate passes on a consistent secure ledger"
bash "$L" init baseone NPPM-2 operator-named >/dev/null
assert_eq false "$(bash "$L" get baseone .secure)" "init operator-named → .secure=false"

# ---------------------------------------------------------------------------
# is_secure fails CLOSED (spec magi #1). Source common.sh and probe directly.
# ---------------------------------------------------------------------------
probe() { ( . ../bin/lib/common.sh; is_secure "$1" && echo secure || echo not-secure ) 2>/dev/null; }
assert_eq not-secure "$(probe baseone)" "is_secure: clean false → not secure"
assert_eq secure "$(probe no-such-run)" "is_secure: missing ledger → SECURE (fail-closed)"
mkdir -p "$AUTOFIX_ROOT/runs/corruptrun"; printf 'not json' > "$AUTOFIX_ROOT/runs/corruptrun/ledger.json"
assert_eq secure "$(probe corruptrun)" "is_secure: corrupt ledger → SECURE (fail-closed)"

# ---------------------------------------------------------------------------
# intake: check-secure allows a Security label; check still exits 2
# ---------------------------------------------------------------------------
newmock() { M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"; cp "fixtures/$1" "$M/issue.json"; }
newmock issue_security.json
out="$(bash "$I" check-secure NPPM-2993)"; rc=$?
assert_eq 0 "$rc" "check-secure: security label is eligible"
assert_contains "$out" nppm-2993 "check-secure returns the summary (branchName)"
out="$(bash "$I" check NPPM-2993 2>&1)" && rc=0 || rc=$?
assert_eq 2 "$rc" "check: security label STILL exits 2 (base guarantee intact)"

# check-secure existing-PR strictness (spec magi #7): needs env + flag
newmock issue_pr.json
bash "$I" check-secure NPPM-2993 --allow-existing-pr >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 3 "$rc" "check-secure: --allow-existing-pr alone does NOT override (exit 3)"
AUTOFIX_SECURE_ALLOW_EXISTING_PR=1 bash "$I" check-secure NPPM-2993 --allow-existing-pr >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 0 "$rc" "check-secure: env + flag overrides existing-PR guard"

# ---------------------------------------------------------------------------
# autofix run-secure entry ack: refuses without AUTOFIX_SECURE=1
# ---------------------------------------------------------------------------
out="$(unset AUTOFIX_SECURE; bash "$A" run-secure NPPM-2993 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "run-secure without AUTOFIX_SECURE=1 dies"
assert_contains "$out" "entry ack" "run-secure explains the entry-ack requirement"

# ---------------------------------------------------------------------------
# secure claim: comment DEFERRED (no commentCreate), assignee-only verify, drift
# ---------------------------------------------------------------------------
csetup() { # run_id
  export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp fixtures/viewer.json fixtures/states.json fixtures/issueUpdate.json fixtures/commentCreate.json "$M/"
  cp fixtures/issue_security.json "$M/issue.json"
  sed "s/RUNID/$1/" fixtures/issue_postclaim_ok.json > "$M/issue_postclaim.json"
  printf '%s' "$EMPTY_COMMENTS" > "$M/issue_comments.json"
  bash "$L" init "$1" NPPM-2993 secure >/dev/null
}
csetup secl1
bash "$C" claim NPPM-2993 secl1 >/dev/null 2>&1
assert_eq 0 $? "secure claim succeeds"
assert_eq 0 "$(grep -c commentCreate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" || true)" \
  "secure claim posts NO comment (deferred)"
assert_eq deferred "$(bash "$L" get secl1 '.decisions[] | select(.key=="claim_comment") | .value')" \
  "secure claim records claim_comment=deferred"
assert_contains "$(bash "$L" get secl1 '.drift_log[].field')" claim_marker "secure claim records claim_marker drift"

# ---------------------------------------------------------------------------
# claim.sh comment: gated preview→confirm→post, digest binding + idempotency
# ---------------------------------------------------------------------------
csetup secl2
bash "$C" claim NPPM-2993 secl2 >/dev/null 2>&1
CBODY="$(mktemp)"; printf 'Validated the token audience.\n' > "$CBODY"
: > "$AUTOFIX_LINEAR_MOCK_DIR/requests.log"
out="$(bash "$C" comment NPPM-2993 secl2 --body-file "$CBODY" 2>&1)" && rc=0 || rc=$?
assert_eq 7 "$rc" "gated comment without confirmation exits 7"
assert_contains "$out" "GATED:" "gated comment prints GATED marker"
assert_eq 0 "$(grep -c commentCreate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" || true)" "gated comment posts nothing"
DG="$(digest "$CBODY")"
# stale digest (body changed since preview) → die
printf 'Different body now.\n' > "$CBODY"
bash "$C" comment NPPM-2993 secl2 --body-file "$CBODY" --confirmed="$DG" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "confirmed comment with a STALE digest is refused"
# restore original body, confirm with matching digest → posts exactly once
printf 'Validated the token audience.\n' > "$CBODY"; DG="$(digest "$CBODY")"
: > "$AUTOFIX_LINEAR_MOCK_DIR/requests.log"
bash "$C" comment NPPM-2993 secl2 --body-file "$CBODY" --confirmed="$DG" >/dev/null 2>&1
assert_eq 1 "$(grep -c commentCreate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" || true)" \
  "confirmed matching digest posts exactly once"
# idempotency: mock now reflects the posted (run,digest) marker → second skips
printf '{ "data": { "issue": { "id": "abc-123", "comments": { "nodes": [ { "body": "x <!-- autofix:secl2:%s --> y" } ] } } } }' \
  "$DG" > "$AUTOFIX_LINEAR_MOCK_DIR/issue_comments.json"
: > "$AUTOFIX_LINEAR_MOCK_DIR/requests.log"
bash "$C" comment NPPM-2993 secl2 --body-file "$CBODY" --confirmed="$DG" >/dev/null 2>&1
assert_eq 0 "$(grep -c commentCreate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" || true)" \
  "idempotent: identical confirmed comment does not double-post"
rm -f "$CBODY"

# ---------------------------------------------------------------------------
# secure release: conditional restore proceeds; label/comment gated (exit 7)
# ---------------------------------------------------------------------------
csetup secl3
bash "$C" claim NPPM-2993 secl3 >/dev/null 2>&1
sed "s/RUNID/secl3/" fixtures/issue_postclaim_ok.json > "$AUTOFIX_LINEAR_MOCK_DIR/issue_release.json"
: > "$AUTOFIX_LINEAR_MOCK_DIR/requests.log"
bash "$C" release NPPM-2993 secl3 --fail-label --comment "cannot reproduce in an isolated env" >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 7 "$rc" "secure release without confirmation exits 7 (label/comment gated)"
assert_eq 0 "$(grep -c commentCreate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" || true)" \
  "secure release posts no comment when gated"
assert_contains "$(grep issueUpdate "$AUTOFIX_LINEAR_MOCK_DIR/requests.log" | tail -1)" issueUpdate \
  "secure release still performs the conditional state restore (ungated)"

# ---------------------------------------------------------------------------
# secure pr.sh: gated preview (real diff+body), no push without confirmation
# ---------------------------------------------------------------------------
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/br-sec"
STUB="$(mktemp -d)"; export PATH="$STUB:$PATH"; export STUB_LOG="$STUB/log"; : > "$STUB_LOG"
cat > "$STUB/git" <<'EOF'
#!/bin/bash
echo "git $*" >> "${STUB_LOG:?}"; exit 0
EOF
cat > "$STUB/gh" <<'EOF'
#!/bin/bash
echo "gh $*" >> "${STUB_LOG:?}"
case "$1 $2" in "pr create") echo "https://github.com/x/y/pull/42";; esac
exit 0
EOF
chmod +x "$STUB/git" "$STUB/gh"
bash "$L" init secpr NPPM-9 secure >/dev/null
bash "$L" set secpr '.branch = "br-sec"'
BODY="$(mktemp)"; printf 'Validate audience.\n' > "$BODY"
out="$(bash "$P" create secpr --title "fix(x): y (NPPM-9)" --body-file "$BODY" 2>&1)" && rc=0 || rc=$?
assert_eq 7 "$rc" "secure pr create without confirmation exits 7"
assert_contains "$out" "GATED:" "secure pr create prints GATED marker"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "secure pr create pushes NOTHING when gated"
assert_eq 0 "$(bash "$L" get secpr '.attempts.pr')" "gated preview does NOT consume a pr attempt"
PVDG="$(printf '%s\n' "$out" | sed -n 's/^GATED: \([0-9a-f]*\) .*/\1/p')"
: > "$STUB_LOG"
bash "$P" create secpr --title "fix(x): y (NPPM-9)" --body-file "$BODY" --confirmed="$PVDG" --no-copilot >/dev/null 2>&1
assert_contains "$(cat "$STUB_LOG")" "push -u origin br-sec" "confirmed secure pr create pushes"
assert_eq "" "$(grep copilot "$STUB_LOG" || true)" "--no-copilot skips the Copilot request"
assert_eq delivered "$(bash "$L" get secpr .terminal)" "confirmed secure pr create reaches delivered"

# ---------------------------------------------------------------------------
# resumed secure run (fresh shell, no env var) still gates a subsequent write
# ---------------------------------------------------------------------------
unset AUTOFIX_SECURE
bash "$L" init secre NPPM-10 secure >/dev/null
bash "$L" set secre '.branch = "br-sec"'
: > "$STUB_LOG"
out="$(bash "$P" create secre --title t --body-file "$BODY" 2>&1)" && rc=0 || rc=$?
assert_eq 7 "$rc" "secure gate engages from the ledger with no AUTOFIX_SECURE in the environment"
assert_eq "" "$(grep push "$STUB_LOG" || true)" "resumed secure run pushes nothing when gated"
rm -f "$BODY"

finish
