#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
A=../bin/autofix; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
cp fixtures/viewer.json fixtures/states.json fixtures/issueUpdate.json fixtures/commentCreate.json "$M/"
cp fixtures/issue_ok.json "$M/issue.json"
cp fixtures/issue_postclaim_ok.json "$M/issue_postclaim.json"
# issue_postclaim_ok.json's claim comment body is the literal string "RUNID" —
# it will never match the run id the dispatcher actually mints (autofix-<issue>-<date>-<hex>),
# so `run` deterministically loses the claim race here. That's an acceptable path
# for THIS test: ledger init + branch_stem recording happen before the claim call,
# so they're on disk regardless of whether the claim held. The happy claim path
# (comment body matching) is already covered by test-claim.sh — not duplicated here.
# `run`'s claim call is unguarded (propagates exit 4/5 via `set -e`), so on a lost
# race the script dies before printing RUN_ID= — recover the minted id from the
# runs/ directory listing instead of from stdout.

out="$(bash "$A" run NPPM-2993 2>&1)" && rc=0 || rc=$?
assert_eq 5 "$rc" "run propagates claim's lost-race exit code"

rid="$(ls "$AUTOFIX_ROOT/runs")"
assert_contains "$rid" autofix-nppm-2993- "run id minted with expected prefix"

assert_eq NPPM-2993 "$(bash "$L" get "$rid" .issue)" "ledger initialized"
assert_eq nppm-2993-bug-jetpack-overrides-brand-front-page-in-certain-conditions \
  "$(bash "$L" get "$rid" '.decisions[] | select(.key=="branch_stem") | .value')" \
  "branch_stem decision recorded from intake summary's branchName"

assert_contains "$(cat "$M/requests.log")" issueUpdate "claim attempted issueUpdate"
assert_contains "$(cat "$M/requests.log")" commentCreate "claim attempted commentCreate"
assert_eq bailed-lost-claim-race "$(bash "$L" get "$rid" '.terminal // "none"')" \
  "lost race recorded as terminal on the run's own ledger"

# --- cleanup sweep classification ---
STUB="$(mktemp -d)"; export PATH="$STUB:$PATH"
cat > "$STUB/n" <<'EOF'
#!/bin/bash
echo "n $*" >> "${N_LOG:?}"
exit 0
EOF
cat > "$STUB/gh" <<'EOF'
#!/bin/bash
echo "gh $*" >> "${STUB_LOG:?}"
if [ "$1" = "pr" ] && [ "$2" = "view" ]; then
  printf '%s\n' "${GH_STATE:-OPEN}"
fi
exit 0
EOF
chmod +x "$STUB/n" "$STUB/gh"
export N_LOG="$STUB/n.log"; : > "$N_LOG"
export STUB_LOG="$STUB/gh.log"; : > "$STUB_LOG"
export GH_STATE=OPEN

# bailed-* → destroy attempted
bash "$L" init done1 NPPM-1 operator-named >/dev/null
bash "$L" set done1 '.terminal="bailed-no-repro"'
bash "$L" set done1 '.env={name:"autofix-env-done1"}'

# delivered + PR still open → no destroy
bash "$L" init deliveredrun NPPM-2 operator-named >/dev/null
bash "$L" set deliveredrun '.terminal="delivered"'
bash "$L" set deliveredrun '.env={name:"autofix-env-delivered"}'
bash "$L" set deliveredrun '.pr={number:555}'

# escalated, younger than TTL → no operator prompt
bash "$L" init escyoung NPPM-3 operator-named >/dev/null
bash "$L" set escyoung '.terminal="escalated"'
bash "$L" set escyoung '.env={name:"autofix-env-escyoung"}'
now_ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
bash "$L" set escyoung '.stage_history=[{stage:"reproduce", outcome:"ok", at:$t}]' --arg t "$now_ts"

# escalated, older than TTL → operator prompt line
bash "$L" init escold NPPM-4 operator-named >/dev/null
bash "$L" set escold '.terminal="escalated"'
bash "$L" set escold '.env={name:"autofix-env-escold"}'
old_ts="$(date -u -v-30d +%Y-%m-%dT%H:%M:%SZ)"
bash "$L" set escold '.stage_history=[{stage:"reproduce", outcome:"ok", at:$t}]' --arg t "$old_ts"

out="$(bash "$A" cleanup 2>&1)"

assert_contains "$out" done1 "sweep visits bailed run"
assert_contains "$out" "destroying env of bailed run done1" "bailed run env destroy attempted"
assert_contains "$(cat "$N_LOG")" "env destroy autofix-env-done1 --yes" "bailed env destroy invoked via n"

assert_eq "" "$(printf '%s' "$out" | grep 'destroying env of.*deliveredrun' || true)" \
  "delivered run with PR still open: no destroy attempted"
assert_eq "" "$(grep 'env destroy autofix-env-delivered' "$N_LOG" || true)" \
  "delivered run with PR still open: n env destroy not invoked"

assert_eq "" "$(printf '%s' "$out" | grep escyoung || true)" \
  "escalated run younger than TTL: no operator prompt"

assert_contains "$out" "ESCALATED run escold" "escalated run older than TTL prints operator prompt"
assert_contains "$out" "operator decision needed" "escalated prompt names the decision"
finish
