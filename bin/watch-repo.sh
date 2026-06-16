#!/bin/bash

source /var/scripts/resolve-project-path.sh

find_project() {
    local path=$(resolve_project_path "$1")
    if [ -z "$path" ]; then path=$(resolve_project_path "newspack-$1"); fi
    if [ -z "$path" ]; then echo "Project $1 not found" >&2; exit 1; fi
    echo "$path"
}

if [ $# -eq 0 ]; then
	echo "No arguments provided"
	echo "Possible arguments: theme, block-theme, or any plugin slug"
	exit 1
fi

PROJECT_DIR=$(find_project "$1")

# Run from the monorepo mount. For workspace plugins/themes, pnpm links deps with
# relative symlinks (e.g. node_modules/newspack-scripts -> ../../../packages/scripts)
# that only resolve under /newspack-monorepo; the standalone /newspack-{plugins,themes}
# mounts make those symlinks escape their root, breaking the watch toolchain. The
# /newspack-repos remap is just path-normalization parity -- standalone repos/
# checkouts live outside the pnpm workspace and build with their own toolchain.
#
# Only adopt the remap when it points at the SAME directory. In the main
# container /newspack-plugins/<name> and /newspack-monorepo/plugins/<name> are
# the same mount, so the remap is a safe prefix swap. In an isolated env a
# worktree override is mounted at /newspack-plugins/<name> while
# /newspack-monorepo/plugins/<name> is the base checkout -- remapping there would
# silently watch the wrong branch, so the -ef guard keeps the worktree mount.
remapped="$PROJECT_DIR"
remapped="${remapped/#\/newspack-plugins//newspack-monorepo/plugins}"
remapped="${remapped/#\/newspack-themes//newspack-monorepo/themes}"
remapped="${remapped/#\/newspack-repos//newspack-monorepo/repos}"
if [ "$remapped" != "$PROJECT_DIR" ] && [ "$remapped" -ef "$PROJECT_DIR" ]; then
	PROJECT_DIR="$remapped"
fi

cd "$PROJECT_DIR"
npm run watch
