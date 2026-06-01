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

# For scripts that iterate all projects. Monorepo dirs come first; a repos/
# checkout whose name duplicates a monorepo project is skipped (tracked wins).
get_all_project_dirs() {
    local dirs=()
    local seen=" "
    local d name
    for d in "$PLUGINS_PATH"/*/ "$THEMES_PATH"/*/; do
        [ -d "$d" ] || continue
        name=$(basename "$d")
        dirs+=("$d")
        seen="$seen$name "
    done
    for d in "$REPOS_PATH"/plugins/*/ "$REPOS_PATH"/themes/*/; do
        [ -d "$d" ] || continue
        name=$(basename "$d")
        case "$seen" in *" $name "*) continue;; esac
        dirs+=("$d")
        seen="$seen$name "
    done
    printf '%s\n' "${dirs[@]}"
}
