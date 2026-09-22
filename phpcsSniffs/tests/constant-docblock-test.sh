#!/usr/bin/env bash
#
# Behavioural test for phpcsSniffs.Constants.ConstantDocblock.
#
# Runs the sniff alone over the fixture and asserts it reports exactly the
# lines the fixture marks `// ERROR:` -- no more, no fewer. Expectations live
# in the fixture rather than here, so a case cannot be added to one and
# forgotten in the other.
#
# Needs the root composer dependencies, so it runs from the PHPCS CI job
# rather than from bin/tests/, whose job checks out without them.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture="$root/phpcsSniffs/tests/fixtures/constant-docblock.php"
# PHPCS_BIN lets a worktree without its own vendor/ borrow another checkout's
# binary; CI and a normal checkout use the root install.
phpcs="${PHPCS_BIN:-$root/vendor/bin/phpcs}"

if [ ! -x "$phpcs" ]; then
	echo "FAIL: $phpcs not found. Run 'composer install' at the repository root, or set PHPCS_BIN."
	exit 1
fi

expected="$(grep -n '// ERROR:' "$fixture" | cut -d: -f1 | sort -n)"

# phpcs exits non-zero whenever it reports anything, which is the expected
# case here, so the pipeline must not take the whole script down with it.
report="$("$phpcs" \
	--standard="$root/phpcsSniffs" \
	--sniffs=phpcsSniffs.Constants.ConstantDocblock \
	--report=csv --no-colors -q \
	"$fixture" || true)"

# CSV columns: File,Line,Column,Type,Message,Source,Severity,Fixable.
actual="$(printf '%s\n' "$report" | tail -n +2 | cut -d, -f2 | grep -E '^[0-9]+$' | sort -n || true)"

if [ "$expected" != "$actual" ]; then
	echo "FAIL: phpcsSniffs.Constants.ConstantDocblock reported the wrong lines."
	echo "Expected (fixture lines marked // ERROR:):"
	printf '%s\n' "$expected" | sed 's/^/  /'
	echo "Actual:"
	printf '%s\n' "$actual" | sed 's/^/  /'
	echo "Full report:"
	printf '%s\n' "$report" | sed 's/^/  /'
	exit 1
fi

echo "PASS: reported $(printf '%s\n' "$expected" | grep -c .) expected error line(s), and nothing else."
