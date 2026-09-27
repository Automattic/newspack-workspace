#!/usr/bin/env bash
#
# test-test-summaries.sh
#
# Self-proving spec for the compact summaries `n test-php` and `n test-js` print
# under a coding agent: bin/test-php-summary.php and bin/test-js-summary.sh.
#
# The verdict is the part an agent acts on, so the cases are the ones a bare exit
# code gets wrong. PHPUnit 9 exits 0 when a filter matches nothing, and a package
# whose `test` script is an `echo` exits 0 having run no suite; both must read as
# NO TESTS RAN, never PASS. Fixtures are trimmed from real runs.
#
# Run: bash bin/tests/test-test-summaries.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN="$SCRIPT_DIR/.."

WORK=$(mktemp -d -t test-summaries-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

failures=0
expect() {
	local desc="$1" pattern="$2" file="$3"
	if grep -qE -- "$pattern" "$file"; then
		echo "ok   - $desc"
	else
		echo "FAIL - $desc: no line matching [$pattern] in:"
		sed 's/^/       /' "$file"
		failures=$((failures + 1))
	fi
}
reject() {
	local desc="$1" pattern="$2" file="$3"
	if grep -qE -- "$pattern" "$file"; then
		echo "FAIL - $desc: unexpected line matching [$pattern]"
		failures=$((failures + 1))
	else
		echo "ok   - $desc"
	fi
}

export NEWSPACK_TEST_CODE='main@abc1234 (plugins/newspack-demo)'

# --- PHP ---------------------------------------------------------------------

php_summary() { # <junit> <exit>
	php "$BIN/test-php-summary.php" "$1" "$WORK/php.log" /newspack-plugins/newspack-demo wp_tests "$2" --filter x > "$WORK/out" 2>&1
}
echo "PHPUnit output tail" > "$WORK/php.log"

cat > "$WORK/pass.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites><testsuite name="demo" tests="3" assertions="5" errors="0" warnings="0" failures="0" skipped="1" time="0.2"><testcase name="test_a" class="Demo_Test"/></testsuite></testsuites>
XML
php_summary "$WORK/pass.xml" 0
expect "php: a clean run is PASS with its counts" '^result:  PASS - 3 tests, 5 assertions' "$WORK/out"
expect "php: names the code under test" '^code:    main@abc1234 \(plugins/newspack-demo\)$' "$WORK/out"
expect "php: names the project" '^project: /newspack-plugins/newspack-demo$' "$WORK/out"

cat > "$WORK/fail.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites><testsuite name="demo" tests="2" assertions="1" errors="1" warnings="0" failures="1" skipped="0" time="0.1">
<testcase name="test_fails" class="Demo_Test"><failure type="PHPUnit\Framework\ExpectationFailedException">Demo_Test::test_fails
Failed asserting that two strings are identical.
/tests/test-demo.php:3</failure></testcase>
<testcase name="test_errors" class="Demo_Test"><error type="RuntimeException">Demo_Test::test_errors
RuntimeException: planted error
/tests/test-demo.php:4</error></testcase>
</testsuite></testsuites>
XML
php_summary "$WORK/fail.xml" 2
expect "php: failures make FAIL" '^result:  FAIL - 2 tests' "$WORK/out"
expect "php: lists a failure by test id" '^- failure: Demo_Test::test_fails$' "$WORK/out"
expect "php: lists an error by test id" '^- error: Demo_Test::test_errors$' "$WORK/out"
expect "php: keeps the failure location" '/tests/test-demo.php:3$' "$WORK/out"
reject "php: drops the repeated test id from the message" '^    Demo_Test::test_fails$' "$WORK/out"

printf '<?xml version="1.0" encoding="UTF-8"?>\n<testsuites/>\n' > "$WORK/empty.xml"
php_summary "$WORK/empty.xml" 0
expect "php: a filter matching nothing is NO TESTS RAN" '^result:  NO TESTS RAN' "$WORK/out"
reject "php: and is never PASS" 'PASS' "$WORK/out"

php_summary "$WORK/missing.xml" 1
expect "php: no report is NO REPORT" '^result:  NO REPORT \(phpunit exit 1\)' "$WORK/out"
expect "php: and shows the output tail" 'PHPUnit output tail' "$WORK/out"

# --- JS ----------------------------------------------------------------------

js_summary() { # <log> <exit> [install-exit]
	bash "$BIN/test-js-summary.sh" "$1" /newspack-plugins/newspack-demo newspack-demo "$2" "$WORK" "${3:-0}" > "$WORK/out" 2>&1
}

cat > "$WORK/js-pass.log" <<'LOG'
Scope: all 19 workspace projects
Done in 1.2s
=== pnpm run test ===

> newspack-demo@1.0.0 test /newspack-monorepo/plugins/newspack-demo
> newspack-scripts test

Test Suites: 6 passed, 6 total
Tests:       71 passed, 71 total
LOG
js_summary "$WORK/js-pass.log" 0
expect "js: a clean run is PASS" '^result:  PASS \(exit 0\)$' "$WORK/out"
expect "js: shows Jest's test count" 'Tests: +71 passed, 71 total' "$WORK/out"
expect "js: names the directory pnpm ran in" '^ran in:  /newspack-monorepo/plugins/newspack-demo$' "$WORK/out"
expect "js: names the code under test" '^code:    main@abc1234' "$WORK/out"
reject "js: no install warning after a clean install" '^install:' "$WORK/out"

cat > "$WORK/js-fail.log" <<'LOG'
=== pnpm run test ===

> newspack-demo@1.0.0 test /newspack-monorepo/plugins/newspack-demo
> newspack-scripts test

  ● planted › fails on purpose

    expect(received).toBe(expected) // Object.is equality

    Expected: "expected"
    Received: "actual"

    > 2 | 	it( 'fails on purpose', () => { expect( 'actual' ).toBe( 'expected' ); } );
        | 	                                                   ^

      at Object.toBe (src/zz-planted/planted.test.js:2:53)

  ● Console

    console.log
      noise

Test Suites: 1 failed, 6 passed, 7 total
Tests:       1 failed, 72 passed, 73 total
LOG
js_summary "$WORK/js-fail.log" 1
expect "js: failures make FAIL" '^result:  FAIL \(exit 1\)$' "$WORK/out"
expect "js: lists the failing test" '^- failed: planted › fails on purpose$' "$WORK/out"
expect "js: keeps the assertion" 'Received: "actual"' "$WORK/out"
expect "js: keeps the location" 'planted.test.js:2:53' "$WORK/out"
reject "js: drops the source excerpt" '\| ' "$WORK/out"
reject "js: skips console blocks" 'Console|noise' "$WORK/out"

cat > "$WORK/js-echo.log" <<'LOG'
=== pnpm run test ===

> newspack-demo@1.0.0 test /newspack-monorepo/plugins/newspack-demo
> echo 'No JS unit tests in this repository.'

No JS unit tests in this repository.
LOG
js_summary "$WORK/js-echo.log" 0
expect "js: an echo-only test script is NO TESTS RAN" '^result:  NO TESTS RAN \(exit 0\)' "$WORK/out"
reject "js: and is never PASS" 'PASS' "$WORK/out"

{ echo ' ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY  Aborted removal of modules directory due to no TTY'; cat "$WORK/js-pass.log"; } > "$WORK/js-install.log"
js_summary "$WORK/js-install.log" 0 1
expect "js: a failed install is reported" '^install: FAILED \(exit 1\)' "$WORK/out"
expect "js: with pnpm's error" 'ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY' "$WORK/out"
expect "js: and the tests still report" '^result:  PASS' "$WORK/out"

if [[ $failures -gt 0 ]]; then
	echo "$failures failure(s)"
	exit 1
fi
echo "all passed"
