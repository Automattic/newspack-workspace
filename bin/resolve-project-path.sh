#!/bin/bash
#
# Resolves a project name (e.g. "newspack-plugin") to its container-side
# path. In the monorepo layout, plugins live at /newspack-plugins/<name>
# and themes at /newspack-themes/<name>. Standalone/local checkouts dropped
# into the gitignored repos/ dir resolve to /newspack-repos/{plugins,themes}/<name>
# by directory existence -- no registration needed.
#
# Monorepo paths are checked first, so a name that exists both in the monorepo
# and in repos/ resolves to the tracked monorepo copy (it takes precedence).
#
# Usage:
#   source /var/scripts/resolve-project-path.sh
#   path=$(resolve_project_path "newspack-plugin")
#

PLUGINS_PATH="/newspack-plugins"
THEMES_PATH="/newspack-themes"
REPOS_PATH="/newspack-repos"

resolve_project_path() {
    local name="$1"
    if [ -d "$PLUGINS_PATH/$name" ]; then
        echo "$PLUGINS_PATH/$name"
    elif [ -d "$THEMES_PATH/$name" ]; then
        echo "$THEMES_PATH/$name"
    elif [ -d "$REPOS_PATH/plugins/$name" ]; then
        echo "$REPOS_PATH/plugins/$name"
    elif [ -d "$REPOS_PATH/themes/$name" ]; then
        echo "$REPOS_PATH/themes/$name"
    else
        echo ""
    fi
}

# For scripts that iterate all monorepo projects (e.g. `n ci-build all`).
# Standalone repos/ checkouts are intentionally excluded: they're external and
# often distributed/pre-built, so they build on demand via `n build <name>`
# rather than in bulk.
get_all_project_dirs() {
    local dirs=()
    for d in "$PLUGINS_PATH"/*/; do
        [ -d "$d" ] && dirs+=("$d")
    done
    for d in "$THEMES_PATH"/*/; do
        [ -d "$d" ] && dirs+=("$d")
    done
    printf '%s\n' "${dirs[@]}"
}
