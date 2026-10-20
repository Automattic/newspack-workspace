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
# process that will never release it.
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
rm -rf "$WORK/.env-create.lock"
mkdir "$WORK/.env-create.lock"
backdate_stamp=$(date -v-70S +%Y%m%d%H%M.%S 2>/dev/null) || backdate_stamp=$(date -d '-70 seconds' +%Y%m%d%H%M.%S)
touch -t "$backdate_stamp" "$WORK/.env-create.lock"

start=$(date +%s)
env_create_lock_acquire
elapsed=$(( $(date +%s) - start ))
env_create_lock_release

if [[ "$elapsed" -lt 5 ]]; then
	echo "  ok: a lock older than the staleness window is cleared promptly (${elapsed}s), not waited out"
else
	echo "  FAIL: a stale lock blocked acquisition for ${elapsed}s instead of being cleared"
	failures=$((failures + 1))
fi

if [[ "$failures" -gt 0 ]]; then
	echo "$failures assertion(s) failed"
	exit 1
fi
echo "All assertions passed."
