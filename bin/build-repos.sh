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
# pnpm filter selector (the workspace package's directory name).
package_filter_for_dir() {
    basename "$1"
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
    pnpm install --frozen-lockfile
else
    pnpm install
fi

case $WHAT_TO_BUILD in
    all)
        # Composer install per project (each plugin still has its own composer.json
        # for production deps; dev deps are hoisted to the root composer.json).
        while IFS= read -r dir; do
            [ -d "$dir" ] && composer install --working-dir "$dir"
        done < <(get_all_project_dirs)
        pnpm run build
        ;;
    *)
        dir="$(find_project "$WHAT_TO_BUILD")"
        pkg=$(package_filter_for_dir "$dir")
        echo "Building $dir (package: $pkg)"
        composer install --working-dir "$dir"
        pnpm --filter "$pkg" run build
        ;;
esac
