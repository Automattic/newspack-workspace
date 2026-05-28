#!/bin/bash

source /var/scripts/repos.sh
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

echo "Running tests for $(basename "$PROJECT_DIR")"
cd "$PROJECT_DIR"
bin/install-wp-tests.sh wp_tests root $MYSQL_ROOT_PASSWORD $MYSQL_HOST latest 2> /dev/null
echo "Running: phpunit ${@:2}"
XDEBUG_MODE=coverage phpunit "${@:2}"
