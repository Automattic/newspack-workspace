#!/bin/sh
# Pre-commit Stylelint helper (invoked by lint-staged for staged *.scss files).
#
# Lints only staged .scss files that a config actually governs, for the same
# reason as the ESLint helper: the root glob matches every .scss in the monorepo,
# including config-less shared packages, and Stylelint errors (not passes) when
# no config is found — which would hard-fail an otherwise-clean commit. Skipping
# config-less files keeps the hook in step with CI.
#
# "config-less" is decided by looking for a config FILE from each staged file up
# to the repo root — deliberately NOT by whether `stylelint --print-config`
# succeeds. --print-config also fails when a config exists but can't load;
# treating that as "no config" would silently skip files that should be linted.
# Keying off the file's presence means a broken config still reaches Stylelint
# and fails the commit loudly.
set -e

# Fail loudly if Stylelint itself isn't resolvable, so a broken/absent install
# can't be silently mistaken for "nothing to lint".
if ! command -v stylelint >/dev/null 2>&1; then
	echo "" >&2
	echo "✖ Pre-commit SCSS lint can't run: stylelint isn't on PATH." >&2
	echo "  Fix:    pnpm install   (at the workspace root)" >&2
	echo "  Bypass: git commit --no-verify" >&2
	echo "" >&2
	exit 1
fi

repo_root="$(cd "$(git rev-parse --show-toplevel)" && pwd)"

# Return 0 if a Stylelint config file governs $1 (searching its directory up to
# the repo root, the same walk Stylelint does), 1 otherwise.
stylelint_config_exists() {
	dir="$(cd "$(dirname "$1")" && pwd)"
	while :; do
		for name in \
			.stylelintrc.js .stylelintrc.cjs .stylelintrc.mjs .stylelintrc.json \
			.stylelintrc.yaml .stylelintrc.yml .stylelintrc \
			stylelint.config.js stylelint.config.mjs stylelint.config.cjs; do
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
	if stylelint_config_exists "$file"; then
		set -- "$@" "$file"
	fi
done
shift "$n"

# Nothing lintable staged (all config-less) — pass cleanly.
[ "$#" -gt 0 ] || exit 0

exec stylelint --customSyntax postcss-scss "$@"
