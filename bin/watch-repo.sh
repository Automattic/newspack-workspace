#!/bin/bash

CODE_PATH="/newspack-repos"

if [ $# -eq 0 ]; then
	echo "No arguments provided"
    echo "Possible arguments are:"
    echo "theme: watches the newspack-theme repo"
    echo "*: Any plugin slug. ex: newspack-listings (only listings is also accepted)"
	exit 1
fi

WHAT_TO_WATCH="$1"

case $WHAT_TO_WATCH in
    theme)
        cd "$CODE_PATH/newspack-theme"
        npm run watch
        ;;
    block-theme)
        cd "$CODE_PATH/newspack-block-theme"
        npm run watch
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
        cd "${CODE_PATH}/${WHAT_TO_WATCH}"
        npm run watch
        ;;
esac
