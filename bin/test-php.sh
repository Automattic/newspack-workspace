#!/bin/bash

source /var/scripts/_common.sh
# _common.sh derives NABSPATH from its own location, which in here is /var — not
# a workspace root. repos.sh's host-only helpers key off NABSPATH being unset in
# the container to fail visibly rather than probe /var/repos/..., so hand that
# invariant back: keep the value only if it points at a real workspace.
[ -f "${NABSPATH:-}/n" ] || unset NABSPATH
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

PROJECT_NAME=$(basename "$PROJECT_DIR")

# Left to itself the WordPress test bootstrap fails on the missing Yoast
# polyfills and advises setting WP_RUN_CORE_TESTS and running
# `composer update -W` — correct guidance for someone testing WordPress core,
# and a dead end for someone whose env simply has not been built yet. Name the
# command that actually provisions dev dependencies instead.
if [ -f "$PROJECT_DIR/composer.json" ] && ! project_has_dev_deps "$PROJECT_DIR"; then
	echo "$PROJECT_NAME has no dev dependencies installed, so the WordPress test" >&2
	echo "bootstrap cannot load the PHPUnit polyfills it requires." >&2
	echo >&2
	echo "Install them with either command, then re-run this one:" >&2
	echo "  cd $PROJECT_DIR && n composer install" >&2
	echo "      the composer dependencies on their own" >&2
	echo "  n build $PROJECT_NAME" >&2
	echo "      the same, plus the JS build" >&2
	exit 1
fi

echo "Running tests for $PROJECT_NAME (test database: $TEST_DB_NAME)"
cd "$PROJECT_DIR"
bin/install-wp-tests.sh "$TEST_DB_NAME" root $MYSQL_ROOT_PASSWORD $MYSQL_HOST latest 2> /dev/null

# `n` sets NEWSPACK_TEST_OUTPUT=compact when it runs under a coding agent. The
# agent then gets a short summary built from the JUnit log instead of PHPUnit's
# full output, which goes to logs/test-php/ under the workspace root. The
# summary always names the project path, test database and test count: a bare
# PASS would hide a run against the wrong checkout or a filter that matched
# nothing.
# PHPUnit's listing and help modes run no tests and write no JUnit log, so their
# output is the answer and passes through whole.
for arg in "${@:2}"; do
	case "$arg" in
		--list-*|--help|-h|--version) NEWSPACK_TEST_OUTPUT=full ;;
	esac
done

if [ "$NEWSPACK_TEST_OUTPUT" != "compact" ]; then
	echo "Running: phpunit ${*:2}"
	XDEBUG_MODE=coverage phpunit "${@:2}"
	exit $?
fi

LOG_DIR=/newspack-monorepo/logs/test-php
mkdir -p "$LOG_DIR"
LOG_BASE="$LOG_DIR/$PROJECT_NAME-$(date -u +%Y%m%dT%H%M%SZ)-$$"
XDEBUG_MODE=coverage phpunit --log-junit "$LOG_BASE.xml" "${@:2}" > "$LOG_BASE.log" 2>&1
STATUS=$?
php "$(dirname "$0")/test-php-summary.php" "$LOG_BASE.xml" "$LOG_BASE.log" "$PROJECT_DIR" "$TEST_DB_NAME" "$STATUS" "${@:2}"
exit $STATUS
