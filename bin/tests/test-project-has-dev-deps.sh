#!/usr/bin/env bash
#
# test-project-has-dev-deps.sh
#
# Self-proving spec for project_has_dev_deps (_common.sh): a vendor/ is judged
# by composer's record of how it was installed, never by vendor/autoload.php.
#
# `n env up` provisions vendor/ with --no-dev, which is all plugin activation
# needs but leaves out PHPUnit and the Yoast polyfills. That install writes
# vendor/autoload.php exactly as a full one does, so autoload.php cannot tell
# the two apart — only installed.json's top-level "dev" flag can. Reading the
# wrong signal sends `n test-php` into the WordPress bootstrap, which fails on
# the missing polyfills and advises setting WP_RUN_CORE_TESTS and running
# `composer update -W` — neither of which provisions anything.
#
# Run: bash bin/tests/test-project-has-dev-deps.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../_common.sh
. "$SCRIPT_DIR/../_common.sh"

WORK=$(mktemp -d -t dev-deps-XXXXXX)
trap 'rm -rf "$WORK"' EXIT

# A project whose vendor/ looks installed in every way a caller might check —
# autoload.php present, packages listed — and differs only in the dev flag.
#
# The packages array carries two decoys ahead of the top-level flag. The string
# one is real: package metadata in this workspace already contains
# `"dev": "Development related task"`, so a reader that accepts a string value
# is wrong today. The nested boolean is prophylactic — no package here ships one
# yet, and it is what pins the `tail -1`. Keep it: without it nothing proves the
# last match is the one that counts.
make_project() {
	local name="$1" dev="$2"
	local dir="$WORK/$name"
	mkdir -p "$dir/vendor/composer"
	echo '{}' > "$dir/composer.json"
	echo '<?php // autoloader' > "$dir/vendor/autoload.php"
	cat > "$dir/vendor/composer/installed.json" <<JSON
{
    "packages": [
        {
            "name": "vendor/decoy",
            "scripts": {
                "dev": "Development related task"
            },
            "extra": {
                "dev": true
            }
        }
    ],
    "dev": $dev,
    "dev-package-names": []
}
JSON
	echo "$dir"
}

failures=0
assert_dev_deps() {
	local dir="$1" want="$2" desc="$3" got
	if project_has_dev_deps "$dir"; then got=present; else got=absent; fi
	if [[ "$got" == "$want" ]]; then
		echo "  ok: $desc"
	else
		echo "  FAIL: $desc — want $want, got $got"
		failures=$((failures + 1))
	fi
}

echo "project_has_dev_deps:"

full=$(make_project full true)
assert_dev_deps "$full" present "a full install reads present"

# The case the guard exists for, and the reason autoload.php is not the signal:
# this project is byte-for-byte a working plugin apart from the dev flag. It
# also pins the decoys — a nested `"dev": true` sits above the top-level false.
runtime=$(make_project runtime false)
assert_dev_deps "$runtime" absent "a --no-dev install reads absent despite vendor/autoload.php and a nested dev:true"

# The two unhappy paths, which deliberately answer in opposite directions.

# Nothing installed: the tests cannot run, so block and let the caller explain.
mkdir -p "$WORK/vendorless"
echo '{}' > "$WORK/vendorless/composer.json"
assert_dev_deps "$WORK/vendorless" absent "a project with no vendor/ at all reads absent"

# A record whose flag this function cannot find — the shape a composer format
# change would take. Reporting absent here would block every project at once
# with no way past it, so an unrecognised file reads present and the run
# proceeds. Flip this assertion and you have chosen the opposite trade.
mkdir -p "$WORK/unrecognised/vendor/composer"
echo '{ "packages": [' > "$WORK/unrecognised/vendor/composer/installed.json"
assert_dev_deps "$WORK/unrecognised" present "an installed.json with no readable flag reads present, so one format change cannot block everyone"

if [[ "$failures" -gt 0 ]]; then
	echo "$failures assertion(s) failed"
	exit 1
fi
echo "All assertions passed."
