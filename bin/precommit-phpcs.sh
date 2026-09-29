#!/bin/sh
# Pre-commit PHP lint helper (invoked by lint-staged for staged *.php files).
#
# Runs PHP_CodeSniffer against the staged PHP files using the shared root
# ruleset (phpcs.xml). PHP_CodeSniffer + the WP coding standards live in the
# workspace-root vendor/ (provisioned by `composer install` / `n ci-build all`).
#
# vendor/ is resolved preferring this checkout, then falling back to the main
# checkout: a git worktree normally has node_modules (from pnpm) but no vendor/
# (composer is a separate, less-frequent step), so committing PHP from a
# worktree must reach the main checkout's tooling instead of hard-failing on a
# missing vendor/. If neither has it, it fails with an actionable hint instead
# of the raw "Referenced sniff ... does not exist" / "No such file" output the
# bare `composer phpcs` would otherwise emit.
set -e

# The checkout the staged files live in, and the main checkout that backs any
# worktree (git rev-parse --git-common-dir points a worktree at the shared .git
# in the main checkout; its parent is the main working tree).
self="$(git rev-parse --show-toplevel)"
main="$(cd "$(dirname "$(git rev-parse --git-common-dir)")" 2>/dev/null && pwd || true)"

# No files passed (e.g. a manual no-arg invocation) — exit cleanly instead of
# falling through to phpcs, which would read stdin and appear to hang.
[ "$#" -eq 0 ] && exit 0

# Drop staged files that fall outside the ruleset's <file> scope.
#
# Passing paths on the phpcs command line overrides the <file> elements, so
# without this the hook would lint PHP the project has deliberately declared
# out of scope (bin/, the Docker dev tooling) and block commits over violations
# CI never reports. Reading the scope out of phpcs.xml rather than hardcoding it
# keeps the hook in step with CI automatically when that list changes.
#
# This runs before the vendor/ resolution below so that a commit touching only
# out-of-scope PHP is free: it must not fail on missing tooling it was never
# going to invoke.
scope=$(sed -n 's|.*<file>\([^<]*\)</file>.*|\1|p' "$self/phpcs.xml")

# Fail loudly rather than silently linting nothing. Every other failure in this
# script is fail-closed; an unparsable ruleset must be too, or a reformatted
# phpcs.xml would turn the PHP hook into a no-op with no signal.
if [ -z "$scope" ]; then
	echo "" >&2
	echo "✖ Pre-commit PHP lint can't run: no <file> elements found in" >&2
	echo "  $self/phpcs.xml — the hook derives its scope from them." >&2
	echo "  Bypass: git commit --no-verify" >&2
	echo "" >&2
	exit 1
fi

remaining=$#
while [ "$remaining" -gt 0 ]; do
	file=$1
	shift
	remaining=$((remaining - 1))
	relative=${file#"$self"/}
	# A path that didn't shed the prefix isn't under this checkout (e.g. a
	# symlinked worktree resolving to a different realpath). Keep it rather
	# than silently dropping it — phpcs will judge it against the ruleset.
	case "$relative" in
		/*)
			set -- "$@" "$file"
			continue
			;;
	esac
	for dir in $scope; do
		# `"$dir"` matches a <file> naming a single file, `"$dir"/*` a directory.
		case "$relative" in
			"$dir" | "$dir"/*)
				set -- "$@" "$file"
				break
				;;
		esac
	done
done

# Everything staged was out of scope — nothing to lint.
[ "$#" -eq 0 ] && exit 0

ROOT=""
for dir in "$self" "$main"; do
	if [ -n "$dir" ] && [ -x "$dir/vendor/bin/phpcs" ]; then
		ROOT="$dir"
		break
	fi
done

if [ -z "$ROOT" ]; then
	echo "" >&2
	echo "✖ Pre-commit PHP lint can't run: PHP_CodeSniffer + the WP coding standards" >&2
	echo "  aren't installed at the workspace root." >&2
	echo "  Fix:    composer install   (run once at ${main:-$self})" >&2
	echo "  Bypass: git commit --no-verify" >&2
	echo "" >&2
	exit 1
fi

# Lint against the staged files' own ruleset ($self), using whichever phpcs
# binary we resolved ($ROOT) — identical when committing from the main checkout.
exec "$ROOT/vendor/bin/phpcs" --standard="$self/phpcs.xml" "$@"
