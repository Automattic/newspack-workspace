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

# Every container - the main site and each isolated env - shares one MariaDB
# server ($MYSQL_HOST is `db:3306` everywhere), and the WordPress test bootstrap
# drops and recreates all tables on each run. A fixed database name therefore
# lets concurrent `n test-php` runs truncate each other mid-run. Deriving it from
# the env's own site database ($MYSQL_DATABASE, set per env in
# docker-compose.env-<name>.yml) gives each env its own; the main checkout
# (MYSQL_DATABASE=wordpress) keeps plain `wp_tests`.
TEST_DB_NAME="wp_tests"
case "$MYSQL_DATABASE" in
	wordpress_*) TEST_DB_NAME="wp_tests_${MYSQL_DATABASE#wordpress_}" ;;
esac

echo "Running tests for $(basename "$PROJECT_DIR") (test database: $TEST_DB_NAME)"
cd "$PROJECT_DIR"
bin/install-wp-tests.sh "$TEST_DB_NAME" root $MYSQL_ROOT_PASSWORD $MYSQL_HOST latest 2> /dev/null
echo "Running: phpunit ${@:2}"
XDEBUG_MODE=coverage phpunit "${@:2}"
