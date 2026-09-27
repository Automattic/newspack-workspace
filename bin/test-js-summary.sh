#!/bin/bash
#
# Condenses a Jest run's output into a short summary for agents.
#
# Usage: test-js-summary.sh <log> <project-dir> <package> <exit-code> <monorepo-root> <install-exit-code>
#
# <project-dir> is accepted for symmetry with the PHP summary but not printed:
# the directory pnpm reports running in is the one that matters.
#
# Reads Jest's own summary lines rather than a JSON report, because
# `newspack-scripts test` appends its subcommand after any arguments, so no
# reporter flag can be passed through. Like the PHP summary, it always names
# what ran, and treats a run with no test summary as its own verdict: a package
# whose `test` script is an `echo` exits 0 having tested nothing.

LOG="$1"
PKG="$3"
STATUS="$4"
ROOT="$5"
INSTALL_STATUS="${6:-0}"
LOG_SHOWN="${LOG#"$ROOT"/}"

# Only the test half of the log; test-js.sh writes the install output above this marker.
TEST_OUT=$(sed -n '/^=== pnpm run test ===$/,$p' "$LOG" | tail -n +2)

# pnpm echoes "> <package>@<version> test <dir>" for the directory it ran in,
# which is what shows whether a worktree or the root checkout was tested.
RAN_IN=$(grep -m1 -oE '^> [^ ]+ test (/.*)$' <<< "$TEST_OUT" | sed -E 's/^> [^ ]+ test //')

echo "project: $PKG"
echo "ran in:  ${RAN_IN:-unknown (pnpm matched no package, or never started)}"
echo "code:    ${NEWSPACK_TEST_CODE:-unknown}"
if [ "$INSTALL_STATUS" != "0" ]; then
    INSTALL_ERR=$(sed -n '/^=== pnpm run test ===$/q;p' "$LOG" | grep -m1 -E 'ERR_|ERROR' | sed 's/^ *//')
    echo "install: FAILED (exit $INSTALL_STATUS) - tests ran against the existing node_modules"
    echo "  ${INSTALL_ERR:-see the full log}"
fi

TESTS_LINE=$(grep -E '^Tests:' <<< "$TEST_OUT" | tail -1)
SUITES_LINE=$(grep -E '^Test Suites:' <<< "$TEST_OUT" | tail -1)

if [ -z "$TESTS_LINE" ]; then
    if [ "$STATUS" = "0" ]; then
        echo "result:  NO TESTS RAN (exit 0) - the package's test script ran no Jest suite"
    else
        echo "result:  NO REPORT (exit $STATUS) - last lines of output:"
    fi
    tail -n 15 <<< "$TEST_OUT" | sed 's/^/  /'
    echo "full log: $LOG_SHOWN"
    exit 0
fi

if [ "$STATUS" = "0" ] && ! grep -q 'failed' <<< "$TESTS_LINE"; then
    VERDICT=PASS
else
    VERDICT=FAIL
fi
echo "result:  $VERDICT (exit $STATUS)"
echo "  $SUITES_LINE"
echo "  $TESTS_LINE"

# Jest heads each failure with "  ● Suite › test" (its console.log blocks use
# the same bullet, ending in "Console", and are skipped). Keep the assertion
# lines and the first stack frame in a test file; drop the source excerpt.
awk '
    /^  ● / && !/Console$/ {
        if (++shown > 10) { print "  ... more in the full log"; exit }
        sub(/^  ● /, "- failed: "); print; body = 1; kept = 0; next
    }
    /^  ● / { body = 0; next }
    body && /^ *at .*test\.[jt]sx?:[0-9]/ { sub(/^ */, "    "); print; body = 0; next }
    body && /^ *>? *[0-9]* *\|/ { next }
    body && NF && kept < 5 { sub(/^ */, "    "); print; kept++ }
' <<< "$TEST_OUT"

echo "full log: $LOG_SHOWN"
