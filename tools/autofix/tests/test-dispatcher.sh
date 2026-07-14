#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
A=../bin/autofix; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
. ../bin/lib/common.sh  # iso8601_days_ago (sourced after AUTOFIX_ROOT so paths resolve to the temp root)
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

# regression: a corrupt (truncated-JSON) ledger must not abort the pre-flight
# sweep or the same-issue guard — `run` must get past both to the claim attempt
mkdir -p "$AUTOFIX_ROOT/runs/aaa-corrupt"
printf '{"run_id":"aaa-corrupt","issue":"NPPM-9"' > "$AUTOFIX_ROOT/runs/aaa-corrupt/ledger.json"

out="$(bash "$A" run NPPM-2993 2>&1)" && rc=0 || rc=$?
assert_eq 5 "$rc" "run propagates claim's lost-race exit code (corrupt ledger present)"
assert_contains "$out" "skipping unparsable ledger" "run's pre-flight sweep skips corrupt ledger"

rid="$(ls "$AUTOFIX_ROOT/runs" | grep '^autofix-nppm-2993-')"
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

# env.sh's destroy safeguards resolve worktrees under WORKSPACE_ROOT — point it
# at an empty temp dir so a recorded branch's worktree is always "missing"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"

# bailed-*, no branch recorded → waived destroy attempted
bash "$L" init done1 NPPM-1 operator-named >/dev/null
bash "$L" set done1 '.terminal="bailed-no-repro"'
bash "$L" set done1 '.env={name:"autofix-env-done1"}'

# bailed-* WITH a recorded branch (unpushed work may exist) → no waiver;
# env.sh's fail-closed push-check refuses (worktree missing) and sweep continues
bash "$L" init done2 NPPM-6 operator-named >/dev/null
bash "$L" set done2 '.terminal="bailed-no-repro"'
bash "$L" set done2 '.env={name:"autofix-env-done2"}'
bash "$L" set done2 '.branch="nppm-6-fix-abcd"'

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
old_ts="$(iso8601_days_ago 30)"
bash "$L" set escold '.stage_history=[{stage:"reproduce", outcome:"ok", at:$t}]' --arg t "$old_ts"

out="$(bash "$A" cleanup 2>&1)" && rc=0 || rc=$?

assert_eq 0 "$rc" "cleanup exits 0 despite corrupt ledger and refused destroy"
assert_contains "$out" "skipping unparsable ledger" "sweep skips corrupt ledger and continues"
assert_contains "$out" done1 "sweep visits bailed run"
assert_contains "$out" "destroying env of bailed run done1" "bailed run env destroy attempted"
assert_contains "$(cat "$N_LOG")" "env destroy autofix-env-done1 --yes" "bailed env destroy invoked via n"

# bailed-with-branch: destroy attempted WITHOUT waiver, refused fail-closed, sweep continues
assert_contains "$out" "destroying env of bailed run done2 (branch nppm-6-fix-abcd — push check governs)" \
  "bailed-with-branch destroy attempted without waiver"
assert_contains "$out" "destroy refused for bailed run done2" "push-check refusal logged, sweep continues"
assert_eq "" "$(grep 'env destroy autofix-env-done2' "$N_LOG" || true)" \
  "bailed-with-branch env NOT destroyed (env.sh fail-closed governs)"

assert_eq "" "$(printf '%s' "$out" | grep 'destroying env of.*deliveredrun' || true)" \
  "delivered run with PR still open: no destroy attempted"
assert_eq "" "$(grep 'env destroy autofix-env-delivered' "$N_LOG" || true)" \
  "delivered run with PR still open: n env destroy not invoked"

assert_eq "" "$(printf '%s' "$out" | grep escyoung || true)" \
  "escalated run younger than TTL: no operator prompt"

assert_contains "$out" "ESCALATED run escold" "escalated run older than TTL prints operator prompt"
assert_contains "$out" "operator decision needed" "escalated prompt names the decision"
finish
