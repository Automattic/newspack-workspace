#!/bin/sh
# Pre-commit ESLint helper (invoked by lint-staged for staged JS/TS files).
#
# Lints only staged files that a config actually governs. The root lint-staged
# globs match every JS/TS file in the monorepo, including paths with no reachable
# ESLint config (release tooling under config/, config-less shared packages,
# plugins that ship no lint config). ESLint (v8, eslintrc mode) *errors* rather
# than passing when it can't find a config, which would hard-fail an otherwise-
# clean commit and train contributors to reach for --no-verify. Skipping config-
# less files keeps the hook in step with CI, which only lints packages that carry
# a lint config.
#
# "config-less" is decided by looking for a config FILE from each staged file up
# to the repo root — deliberately NOT by whether `eslint --print-config`
# succeeds. --print-config also fails when a config exists but can't load (a
# missing shareable config/plugin, an invalid config); treating that as "no
# config" would silently skip files that should be linted. Keying off the file's
# presence means a broken config still reaches ESLint and fails the commit loudly.
set -e

# Fail loudly if ESLint itself isn't resolvable, so a broken/absent install
# can't be silently mistaken for "nothing to lint".
if ! command -v eslint >/dev/null 2>&1; then
	echo "" >&2
	echo "✖ Pre-commit JS/TS lint can't run: eslint isn't on PATH." >&2
	echo "  Fix:    pnpm install   (at the workspace root)" >&2
	echo "  Bypass: git commit --no-verify" >&2
	echo "" >&2
	exit 1
fi

repo_root="$(cd "$(git rev-parse --show-toplevel)" && pwd)"

# Return 0 if an ESLint config file governs $1 (searching its directory up to
# the repo root, the same walk ESLint does), 1 otherwise.
eslint_config_exists() {
	dir="$(cd "$(dirname "$1")" && pwd)"
	while :; do
		for name in \
			.eslintrc.js .eslintrc.cjs .eslintrc.mjs .eslintrc.json \
			.eslintrc.yaml .eslintrc.yml .eslintrc \
			eslint.config.js eslint.config.mjs eslint.config.cjs eslint.config.ts; do
			if [ -e "$dir/$name" ]; then
				return 0
			fi
		done
		if [ "$dir" = "$repo_root" ]; then
			return 1
		fi
		parent="$(dirname "$dir")"
		if [ "$parent" = "$dir" ]; then
			return 1
		fi
		dir="$parent"
	done
}

# Partition the args: keep only files a config governs. Append each keeper past
# the original args, then drop the originals with `shift` — this preserves paths
# containing spaces (a flat string would not).
n=$#
for file in "$@"; do
	if eslint_config_exists "$file"; then
		set -- "$@" "$file"
	fi
done
shift "$n"

# Nothing lintable staged (all config-less) — pass cleanly.
[ "$#" -gt 0 ] || exit 0

exec eslint "$@"
