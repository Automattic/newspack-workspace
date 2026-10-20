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

# --- Concurrent stale-lock recovery: reclaiming must never let two waiters in at once ---
# A solitary stale lock with nobody else contending (the test above) can't
# tell this design apart from either of two earlier, broken ones this branch
# went through: a bare rmdir-on-stale-mtime, and a since-reverted mv-based
# reclaim that moved the lock dir aside to inspect it. All three clear a lone
# stale lock promptly. What distinguishes them is concurrent reclaim -- each
# of those designs was seen letting more than one waiter into the counted
# section at once under this same shape (N waiters, a pre-backdated lock)
# during development. The race window is narrow and timing-dependent, so
# treat a clean pass as inconclusive rather than as proof nothing is wrong;
# a failure, on the other hand, is real evidence of exactly the shape of bug
# this lock exists to close.
CONCURRENT_LOCKDIR="$NABSPATH/.env-create.lock"
CONCURRENT_COUNTER="$WORK/concurrent-counter"
rm -rf "$CONCURRENT_LOCKDIR"
echo 0 > "$CONCURRENT_COUNTER"
mkdir "$CONCURRENT_LOCKDIR"
backdate_stamp=$(date -v-70S +%Y%m%d%H%M.%S 2>/dev/null) || backdate_stamp=$(date -d '-70 seconds' +%Y%m%d%H%M.%S)
touch -t "$backdate_stamp" "$CONCURRENT_LOCKDIR"

N=10
pids=()
for _ in $(seq 1 "$N"); do
	(
		if env_create_lock_acquire; then
			val=$(cat "$CONCURRENT_COUNTER")
			sleep 0.03
			echo "$((val + 1))" > "$CONCURRENT_COUNTER"
			env_create_lock_release
		fi
	) &
	pids+=("$!")
done
for pid in "${pids[@]}"; do
	wait "$pid"
done

final=$(cat "$CONCURRENT_COUNTER")
if [[ "$final" -eq "$N" ]]; then
	echo "  ok: $N waiters reclaiming one stale lock serialized cleanly (counter == $N)"
else
	echo "  FAIL: expected counter == $N reclaiming a contended stale lock, got $final (two waiters' critical sections overlapped)"
	failures=$((failures + 1))
fi

# --- Compound crash: a stale lock AND a stale recovery mutex, both left behind ---
# This is the precondition that actually exercised the ".recovering" reclaim
# bug (a crash between its own mkdir and rmdir leaves it behind too, not just
# the main lock) -- the test above backdates only the main lock, which never
# reaches that secondary path. Like the test above, this one's race window is
# narrow and timing-dependent (a clean pass doesn't prove the bug is absent),
# but it's the only assertion that exercises this specific path at all, and
# it costs nothing when it doesn't fire.
#
# Unlike the single-stale-lock case above, a stuck ".recovering" has no
# automatic reclaim by design (see the comment in env_create_lock_acquire):
# every waiter legitimately times out rather than ever entering the section,
# which is correct but takes the production ~90s timeout to play out. Don't
# wait for that; watch for a fixed window instead and kill off whatever's
# still polling.
COMPOUND_LOCKDIR="$NABSPATH/.env-create.lock"
COMPOUND_COUNTER="$WORK/compound-counter"
rm -rf "$COMPOUND_LOCKDIR" "$COMPOUND_LOCKDIR.recovering"
echo 0 > "$COMPOUND_COUNTER"
mkdir "$COMPOUND_LOCKDIR" "$COMPOUND_LOCKDIR.recovering"
backdate_stamp=$(date -v-70S +%Y%m%d%H%M.%S 2>/dev/null) || backdate_stamp=$(date -d '-70 seconds' +%Y%m%d%H%M.%S)
touch -t "$backdate_stamp" "$COMPOUND_LOCKDIR" "$COMPOUND_LOCKDIR.recovering"

N=10
pids=()
for _ in $(seq 1 "$N"); do
	(
		if env_create_lock_acquire; then
			val=$(cat "$COMPOUND_COUNTER")
			sleep 0.03
			echo "$((val + 1))" > "$COMPOUND_COUNTER"
			env_create_lock_release
		fi
	) &
	pids+=("$!")
done

observed_max=0
for _ in $(seq 1 40); do
	val=$(cat "$COMPOUND_COUNTER" 2>/dev/null || echo 0)
	[[ "$val" -gt "$observed_max" ]] && observed_max="$val"
	sleep 0.1
done
for pid in "${pids[@]}"; do
	kill -9 "$pid" 2>/dev/null || true
done
for pid in "${pids[@]}"; do
	wait "$pid" 2>/dev/null || true
done
# A killed waiter can still be mid-write to $COMPOUND_COUNTER; take the max
# seen during polling, not whatever the file holds after the kill.
final=$(cat "$COMPOUND_COUNTER" 2>/dev/null || echo 0)
[[ "$final" -gt "$observed_max" ]] && observed_max="$final"

# What must never happen is the counter exceeding N, which would mean two
# waiters were both inside the critical section at once.
if [[ "$observed_max" -le "$N" ]]; then
	echo "  ok: compound stale lock + stale recovery mutex never let two waiters in at once (counter == $observed_max, <= $N)"
else
	echo "  FAIL: counter == $observed_max exceeds $N -- two waiters' critical sections overlapped"
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
# The compose file's closing heredoc terminator: the IP isn't safely claimed
# until this file exists (a concurrent create's next_loopback_ip() rescans
# it), so this -- not the first exec -- is the ordering release actually has
# to respect. Release-before-exec alone would stay green if release moved up
# to right after the IP pick, which is exactly the NPPM-3647 race back again.
compose_write_line=$(grep -n '^YAML$' <<< "$create_arm" | head -1 | cut -d: -f1) || true

if [[ -n "$acquire_line" && -n "$next_ip_line" && "$acquire_line" -lt "$next_ip_line" ]]; then
	echo "  ok: create's \`create)\` arm acquires the lock before picking an IP"
else
	echo "  FAIL: create's lock-acquire call is missing, or doesn't precede next_loopback_ip"
	failures=$((failures + 1))
fi

if [[ -n "$compose_write_line" && -n "$release_line" && "$compose_write_line" -lt "$release_line" ]]; then
	echo "  ok: create's \`create)\` arm writes the compose file before releasing the lock"
else
	echo "  FAIL: create's lock-release call is missing, or doesn't come after the compose-file write"
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
