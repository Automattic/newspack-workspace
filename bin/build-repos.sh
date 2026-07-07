#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

MONOREPO_ROOT="/newspack-monorepo"

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

# Translate a container path under /newspack-{plugins,themes}/<name> to the
# pnpm filter selector for a workspace package: the package's actual `name`
# read from its package.json. We can't derive this from the folder, because a
# package's name often differs from its directory (plugins/newspack-plugin is
# named "newspack", plugins/newspack-blocks is "@automattic/newspack-blocks").
# A bare `--filter <dirname>` matches no projects there, so pnpm silently builds
# nothing and exits 0. Falls back to the basename if no name is found.
package_filter_for_dir() {
    local name
    name=$(sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1/package.json" 2>/dev/null | head -n1)
    if [ -n "$name" ]; then echo "$name"; else basename "$1"; fi
}

# Build a standalone repos/ checkout with its own toolchain. These live outside
# the pnpm workspace, so `pnpm --filter` can't see them. Composer + JS deps are
# installed in-place; a missing composer.json/package.json is a no-op.
build_standalone_repo() {
    local dir="$1"
    echo "Building standalone repo $dir"
    [ -f "$dir/composer.json" ] && composer install --working-dir "$dir"
    [ -f "$dir/package.json" ] || return 0
    local pm="npm"
    [ -f "$dir/pnpm-lock.yaml" ] && pm="pnpm"
    [ -f "$dir/yarn.lock" ] && pm="yarn"
    ( cd "$dir" && "$pm" install )
    if grep -q '"build"[[:space:]]*:' "$dir/package.json" 2>/dev/null; then
        ( cd "$dir" && "$pm" run build )
    fi
}

if [ $# -eq 0 ]; then
    echo "No arguments provided"
    echo "Possible arguments: all, theme, block-theme, or any plugin slug"
    exit 1
fi

WHAT_TO_BUILD="$1"
MODE="$2"

cd "$MONOREPO_ROOT"

# Workspace install: pnpm resolves `workspace:*` deps (e.g. newspack-scripts)
# and links shared bins into each package's node_modules/.bin.
if [ "$MODE" = "ci" ]; then
    # CI=true keeps pnpm non-interactive so a one-time node_modules re-layout
    # (e.g. after a hoist-pattern change in .npmrc) auto-purges instead of
    # blocking on a confirmation prompt -- `n` runs pnpm via `docker exec`
    # without a TTY in non-interactive contexts, where that prompt aborts.
    CI=true pnpm install --frozen-lockfile
else
    pnpm install
fi

case $WHAT_TO_BUILD in
    all)
        # Root composer install provisions the shared PHP dev tooling
        # (PHP_CodeSniffer + the WP coding standards in ./vendor) that the
        # pre-commit hook's PHP check and `composer phpcs` depend on -- the
        # counterpart to the root `pnpm install` above that provisions the JS
        # linters. The monorepo root is bind-mounted (.:/newspack-monorepo), so
        # this lands on the host ./vendor where the git hook runs.
        composer install --working-dir "$MONOREPO_ROOT"
        # Composer install per monorepo project (each plugin still has its own
        # composer.json for production deps; dev deps are hoisted to the root).
        # Standalone repos/ checkouts are not built here -- they're external and
        # often distributed/pre-built; build one on demand with `n build <name>`.
        while IFS= read -r dir; do
            [ -d "$dir" ] && composer install --working-dir "$dir"
        done < <(get_all_project_dirs)
        pnpm run build
        ;;
    *)
        dir="$(find_project "$WHAT_TO_BUILD")"
        if [[ "$dir" == "$REPOS_PATH"/* ]]; then
            build_standalone_repo "$dir"
        else
            pkg=$(package_filter_for_dir "$dir")
            echo "Building $dir (package: $pkg)"
            composer install --working-dir "$dir"
            pnpm --filter "$pkg" run build
        fi
        ;;
esac
