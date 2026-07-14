#!/bin/bash
# Portability of date handling. GNU and BSD date disagree on parse/offset flags,
# and BSD's `-d` is a daylight-saving flag rather than a parse flag — so a
# try-GNU-then-fall-back-to-BSD approach is not safe. Everything must go through
# the helpers in common.sh.
set -uo pipefail
cd "$(dirname "$0")"; . ./helpers.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
. ../bin/lib/common.sh

# A known instant: 2020-01-01T00:00:00Z == 1577836800.
assert_eq 1577836800 "$(iso8601_to_epoch '2020-01-01T00:00:00Z')" \
  "iso8601_to_epoch parses a UTC timestamp to epoch seconds"

# Unparseable input must FAIL, not quietly return something usable. The original
# bug was a `|| date +%s` fallback that substituted *now*, making every run look
# 0 days old so the TTL sweep never reaped anything — silently.
iso8601_to_epoch 'not-a-timestamp' >/dev/null 2>&1 && \
  assert_fail "iso8601_to_epoch must return non-zero on garbage" || \
  printf 'ok    %s\n' "iso8601_to_epoch fails on an unparseable timestamp"

iso8601_to_epoch '' >/dev/null 2>&1 && \
  assert_fail "iso8601_to_epoch must return non-zero on empty input" || \
  printf 'ok    %s\n' "iso8601_to_epoch fails on empty input"

# Round-trip: N days ago must parse back to roughly now - N days (±2 min slack).
ago="$(iso8601_days_ago 30)"
epoch_ago="$(iso8601_to_epoch "$ago")"
delta=$(( $(date -u +%s) - epoch_ago ))
expected=$(( 30 * 86400 ))
if [ "$delta" -ge $(( expected - 120 )) ] && [ "$delta" -le $(( expected + 120 )) ]; then
  printf 'ok    %s\n' "iso8601_days_ago round-trips through iso8601_to_epoch"
else
  assert_fail "iso8601_days_ago round-trip: expected ~${expected}s, got ${delta}s"
fi

# Guard: no platform-specific date flag may appear outside common.sh. If this
# fails, someone has reintroduced a macOS-only (or Linux-only) date call.
offenders="$(grep -rEn "date .*(-v-?[0-9]|-j -f|-d '|-d \")" ../bin ../tests \
  --exclude=common.sh --exclude=test-date.sh 2>/dev/null || true)"
if [ -z "$offenders" ]; then
  printf 'ok    %s\n' "no platform-specific date flags outside common.sh"
else
  assert_fail "platform-specific date flag(s) found — route through iso8601_* helpers:
$offenders"
fi

finish
