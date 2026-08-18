#!/bin/sh
# Pre-commit ShellCheck helper (invoked by lint-staged for staged *.sh files).
#
# Gates at `error` only, matching the CI step in .github/workflows/ci.yml, so the
# hook never blocks a commit over a finding CI would let through. Configuration
# comes from .shellcheckrc at the repo root, which deliberately leaves the local
# severity at `style` for editors; the -S here is what narrows the hook.
#
# Scope is *.sh. CI additionally sweeps tracked extensionless files whose shebang
# names a shell (`n`, bin/newspack-manage-host, the .hooks/pre-push scripts),
# which lint-staged cannot glob without matching every file in the repo.
#
# Unlike the ESLint, Stylelint and PHPCS helpers, a missing tool here is a SKIP
# rather than a hard failure. Those three come from `pnpm install`, so their
# absence means a broken checkout worth failing on. ShellCheck is a system
# package the repo cannot provision, and failing closed would stop anyone without
# it from committing a shell file at all. CI re-lints every PR, so a local skip
# cannot land an unchecked script.
set -e

if ! command -v shellcheck >/dev/null 2>&1; then
	echo "" >&2
	echo "• Pre-commit shell lint skipped: shellcheck isn't on PATH." >&2
	echo "  Install: brew install shellcheck   (or your platform's package manager)" >&2
	echo "  CI runs the same check on every PR, so this is a local convenience only." >&2
	echo "" >&2
	exit 0
fi

[ "$#" -gt 0 ] || exit 0

exec shellcheck -S error -x "$@"
