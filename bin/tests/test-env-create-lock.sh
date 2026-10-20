#!/usr/bin/env bash
#
# test-env-create-lock.sh
#
# Self-proving spec for env_create_lock_acquire/env_create_lock_release
# (env.sh): the lock serializes a critical section across concurrent
# subshells, so a read-increment-write on a shared file never loses an
# update.
#
# NPPM-3647 found this one unlocked: next_loopback_ip() picks the lowest free
# octet by reading the existing docker-compose.env-*.yml files, but nothing
# stopped a second `n env create` from reading the same "free" answer before
# the first had written its compose file claiming it. Five parallel creates
# in a dry run got duplicate loopback addresses this way. This test proves
# the lock itself serializes a shared read-modify-write, the same shape of
# race next_loopback_ip() has; it does not run `n env create` end to end.
#
# Also covers the stale-lock recovery: a lock dir older than the staleness
# window is cleared rather than wedging every later create behind a crashed
# process that will never release it. And a static check that `create`'s own
# two call sites actually invoke the lock, in the right order -- the tests
# above exercise the lock functions directly and would stay green even if
# `create` stopped calling them.
#
# Run: bash bin/tests/test-env-create-lock.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK=$(mktemp -d -t env-create-lock-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

export NABSPATH="$WORK"
# env.sh's own dispatcher runs unconditionally when the file loads (it has no
# "only run when executed directly" guard), so it reads $1 on sourcing; under
# `set -u` with no positional params that's an unbound-variable error. Bind
# $1 to a harmless value first -- it matches no subcommand, so the dispatcher
# just prints its usage text and falls through.
set -- "test-env-create-lock-sourcing"
# shellcheck source=../env.sh
. "$SCRIPT_DIR/../env.sh" >/dev/null
# _common.sh (sourced by env.sh) resets NABSPATH to the real tree's path
# unless it finds an `n` script under the path we exported -- $WORK has none.
# Re-pin it now, after sourcing, which is when that override actually fires;
# otherwise every lock call below silently operates on the real worktree's
# .env-create.lock instead of this test's sandbox.
export NABSPATH="$WORK"

failures=0

# --- Serialization: N concurrent critical sections must not lose an update ---
COUNTER="$WORK/counter"
echo 0 > "$COUNTER"

N=20
pids=()
for _ in $(seq 1 "$N"); do
	(
		env_create_lock_acquire
		trap 'env_create_lock_release' EXIT
		val=$(cat "$COUNTER")
		sleep 0.02
		echo "$((val + 1))" > "$COUNTER"
		trap - EXIT
		env_create_lock_release
	) &
	pids+=("$!")
done

wait_failed=0
for pid in "${pids[@]}"; do
	wait "$pid" || wait_failed=1
done
[[ "$wait_failed" -eq 0 ]] || { echo "  FAIL: a locked subshell exited non-zero"; failures=$((failures + 1)); }

final=$(cat "$COUNTER")
if [[ "$final" -eq "$N" ]]; then
	echo "  ok: $N concurrent critical sections serialized (counter == $N)"
else
	echo "  FAIL: expected counter == $N after $N locked increments, got $final (lock let overlap through, the NPPM-3647 shape)"
	failures=$((failures + 1))
fi

# --- Stale-lock recovery: a lock left by a dead process is cleared, not waited out ---
LOCKDIR="$NABSPATH/.env-create.lock"
rm -rf "$LOCKDIR"
mkdir "$LOCKDIR"
backdate_stamp=$(date -v-70S +%Y%m%d%H%M.%S 2>/dev/null) || backdate_stamp=$(date -d '-70 seconds' +%Y%m%d%H%M.%S)
touch -t "$backdate_stamp" "$LOCKDIR"

start=$(date +%s)
if env_create_lock_acquire; then
	elapsed=$(( $(date +%s) - start ))
	env_create_lock_release
	if [[ "$elapsed" -lt 5 ]]; then
		echo "  ok: a lock older than the staleness window is cleared promptly (${elapsed}s), not waited out"
	else
		echo "  FAIL: a stale lock blocked acquisition for ${elapsed}s instead of being cleared"
		failures=$((failures + 1))
	fi
else
	echo "  FAIL: env_create_lock_acquire timed out instead of reclaiming the stale lock"
	failures=$((failures + 1))
fi

# --- Call-site coverage: `create` must actually call the lock, in order ---
# A static check, since the two tests above exercise the lock functions
# directly and would stay green even if `create` stopped calling them.
create_start=$(grep -n '^[[:space:]]*create)' "$SCRIPT_DIR/../env.sh" | head -1 | cut -d: -f1) || true
# The arm's own closing `;;` sits at 8-space indentation, one level in from
# `case $1 in`; a plain `;;` grep hits a nested case's closing `;;` first
# (e.g. the per-flag arg parser inside `create)`), which stops short of the
# lock calls this check exists to find.
create_end=""
[[ -n "$create_start" ]] && create_end=$(tail -n "+$create_start" "$SCRIPT_DIR/../env.sh" | grep -n '^        ;;$' | head -1 | cut -d: -f1)

if [[ -n "$create_start" && -n "$create_end" ]]; then
	create_arm=$(sed -n "${create_start},$((create_start + create_end - 1))p" "$SCRIPT_DIR/../env.sh")
else
	echo "  FAIL: couldn't locate the \`create)\` case arm in env.sh to check call-site ordering"
	failures=$((failures + 1))
	create_arm=""
fi

# Each grep may legitimately match nothing (that's the failure this check is
# looking for), and with pipefail a no-match grep makes the whole pipeline's
# exit status non-zero; guard every assignment or `set -e` kills the test
# right here instead of reaching the FAIL lines below.
acquire_line=$(grep -n 'env_create_lock_acquire' <<< "$create_arm" | head -1 | cut -d: -f1) || true
next_ip_line=$(grep -n 'next_loopback_ip' <<< "$create_arm" | head -1 | cut -d: -f1) || true
# A plain substring match also hits `trap 'env_create_lock_release' EXIT`,
# which sits well before the exec and would mask a deleted release call as
# still "present". Only count an actual call: the bare function name as its
# own statement.
release_line=$(grep -n '^[[:space:]]*env_create_lock_release[[:space:]]*$' <<< "$create_arm" | head -1 | cut -d: -f1) || true
exec_line=$(grep -n '^[[:space:]]*exec ' <<< "$create_arm" | head -1 | cut -d: -f1) || true

if [[ -n "$acquire_line" && -n "$next_ip_line" && "$acquire_line" -lt "$next_ip_line" ]]; then
	echo "  ok: create's \`create)\` arm acquires the lock before picking an IP"
else
	echo "  FAIL: create's lock-acquire call is missing, or doesn't precede next_loopback_ip"
	failures=$((failures + 1))
fi

if [[ -n "$release_line" && -n "$exec_line" && "$release_line" -lt "$exec_line" ]]; then
	echo "  ok: create's \`create)\` arm releases the lock before its first exec"
else
	echo "  FAIL: create's lock-release call is missing, or doesn't precede the first exec"
	failures=$((failures + 1))
fi

if [[ "$failures" -gt 0 ]]; then
	echo "$failures assertion(s) failed"
	exit 1
fi
echo "All assertions passed."
