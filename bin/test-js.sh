#!/bin/bash

# Sourced relative to this script rather than by absolute container path, so
# bin/tests/ can run it on the host. In the container bin/ is mounted at
# /var/scripts, so this resolves to the same files.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/repos.sh"
source "$SCRIPT_DIR/resolve-project-path.sh"

MONOREPO_ROOT="${MONOREPO_ROOT:-/newspack-monorepo}"

# pnpm is provided by Node 20's bundled corepack. Enable on first use; the
# pnpm version is pinned via the `packageManager` field in package.json.
if ! command -v pnpm >/dev/null 2>&1; then
    corepack enable pnpm >/dev/null
fi

find_project() {
    local path=$(resolve_project_path "$1")
    if [ -z "$path" ]; then path=$(resolve_project_path "newspack-$1"); fi
    if [ -z "$path" ]; then echo "Project $1 not found" >&2; exit 1; fi
    echo "$path"
}

# Run a standalone repos/ checkout's own JS test script. These live outside the
# pnpm workspace, so `pnpm --filter` can't see them; install + run their declared
# `test` script in-place with their own package manager. A missing package.json
# or test script is a no-op (matching pnpm --filter's silent skip for a package
# with no test script).
test_standalone_repo() {
    local dir="$1"
    if [ ! -f "$dir/package.json" ]; then
        echo "No package.json in $dir; nothing to test" >&2
        return 0
    fi
    # Read scripts.test through node rather than grepping the file: a *dependency*
    # named "test" matches the same `"test":` shape and would send us on to
    # `run test`, which then fails with "Missing script". Failing to read the file
    # at all is kept distinct from finding no script — an unreadable package.json
    # reported as "nothing to test" would skip a repo's whole suite and still exit 0.
    local has_test
    if ! has_test=$(node -p "require('$dir/package.json').scripts?.test ?? ''" 2>/dev/null); then
        echo "Could not read $(basename "$dir")/package.json" >&2
        return 1
    fi
    if [ -z "$has_test" ]; then
        echo "No \"test\" script in $(basename "$dir")/package.json; nothing to test" >&2
        return 0
    fi
    # Detect package manager from lockfile; default npm for a lockfile-less repo
    # (matches `n build` so the two never pick a different PM for one checkout).
    local pm="npm"
    if [ -f "$dir/pnpm-lock.yaml" ]; then
        pm="pnpm"
    elif [ -f "$dir/yarn.lock" ]; then
        pm="yarn"
    fi
    ( cd "$dir" && "$pm" install ) || return 1
    ( cd "$dir" && "$pm" run test )
}

if [ $# -eq 0 ]; then
	echo "No arguments provided"
	echo "Possible arguments: theme, block-theme, or any plugin slug"
	exit 1
fi

# `exit 1` inside find_project ends only the command-substitution subshell, so
# the status has to be propagated here — otherwise an unknown name leaves
# PROJECT_DIR empty and the workspace branch below installs and tests every
# package instead of reporting the typo.
PROJECT_DIR=$(find_project "$1") || exit 1

if [[ "$PROJECT_DIR" == "$REPOS_PATH"/* ]]; then
    # Standalone repo: outside the pnpm workspace -- run its own test toolchain.
    test_standalone_repo "$PROJECT_DIR"
else
    # Derive the pnpm package name from package.json — it can differ from the
    # directory name (e.g. plugins/newspack-plugin is the package "newspack"),
    # and pnpm --filter matches by package name, not directory.
    PKG=$(node -p "require('$PROJECT_DIR/package.json').name" 2>/dev/null)
    [ -z "$PKG" ] && PKG=$(basename "$PROJECT_DIR")
    # Guarded for the same reason find_project's status is propagated above: an
    # unreachable root would otherwise leave the shell wherever it started and run
    # `pnpm --filter` against that directory instead of the workspace.
    cd "$MONOREPO_ROOT" || exit 1
    pnpm install
    pnpm --filter "$PKG" run test
fi
