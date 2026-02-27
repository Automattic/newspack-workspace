#!/bin/bash

source /var/scripts/repos.sh
CODE_PATH="/newspack-repos"

if [ $# -eq 0 ]; then
	echo "No arguments provided"
    echo "Possible arguments are:"
    echo "theme: run tests for the newspack-theme repo"
    echo "*: Any plugin slug. ex: newspack-listings (only listings is also accepted)"
	exit 1
fi

WHAT_TO_WATCH="$1"

case $WHAT_TO_WATCH in
    theme)
        cd "$CODE_PATH/newspack-theme"
        bin/install-wp-tests.sh wp_tests root $MYSQL_ROOT_PASSWORD $MYSQL_HOST latest
        echo "Running: phpunit ${@:2}"
        phpunit "${@:2}"
        ;;
    block-theme)
        echo "Block theme tests are not supported yet"
        exit 1
        ;;
    *)
        if [ ! -d "${CODE_PATH}/${WHAT_TO_WATCH}" ]; then
            echo "$WHAT_TO_WATCH directory does not exist"
            WHAT_TO_WATCH="newspack-${WHAT_TO_WATCH}"
            echo "Trying ${WHAT_TO_WATCH}"
            if [ ! -d "${CODE_PATH}/${WHAT_TO_WATCH}" ]; then
                echo "${WHAT_TO_WATCH} directory does not exits"
                exit 1
            fi
        fi
        echo "Running tests for ${WHAT_TO_WATCH}"
        cd "${CODE_PATH}/${WHAT_TO_WATCH}"
        bin/install-wp-tests.sh wp_tests root $MYSQL_ROOT_PASSWORD $MYSQL_HOST latest 2> /dev/null
        echo "Running: phpunit ${@:2}"
        XDEBUG_MODE=coverage phpunit "${@:2}"
        ;;
esac
