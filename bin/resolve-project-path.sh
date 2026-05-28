#!/bin/bash
#
# Resolves a project name (e.g. "newspack-plugin") to its container-side
# path. In the monorepo layout, plugins live at /newspack-plugins/<name>
# and themes at /newspack-themes/<name>.
#
# Usage:
#   source /var/scripts/resolve-project-path.sh
#   path=$(resolve_project_path "newspack-plugin")
#

PLUGINS_PATH="/newspack-plugins"
THEMES_PATH="/newspack-themes"

resolve_project_path() {
    local name="$1"
    if [ -d "$PLUGINS_PATH/$name" ]; then
        echo "$PLUGINS_PATH/$name"
    elif [ -d "$THEMES_PATH/$name" ]; then
        echo "$THEMES_PATH/$name"
    else
        echo ""
    fi
}

# For scripts that iterate all projects:
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
