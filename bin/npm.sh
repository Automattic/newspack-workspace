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
	echo "Example: n npm newspack-plugin install"
	exit 1
fi

PROJECT_DIR=$(find_project "$1")

cd "$PROJECT_DIR"
echo "Running: npm ${@:2}"
npm "${@:2}"
