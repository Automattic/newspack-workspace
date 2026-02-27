#!/bin/bash

source /var/scripts/repos.sh
CODE_PATH="/newspack-repos"

if [ $# -eq 0 ]; then
	echo "No arguments provided"
    echo "Possible arguments are:"
    echo "all: build all repos"
    echo "theme: builds the newspack-theme repo"
    echo "*: Any plugin slug. ex: newspack-listings (only listings is also accepted)"
	exit 1
fi

WHAT_TO_BUILD="$1"

build_dir() {
    echo "Building $1"
    cd $1
    if [ "$2" == "ci" ]; then
        npm ci --legacy-peer-deps
    fi
    composer install
    npm run build
}

case $WHAT_TO_BUILD in
    all)
        for dir in "${newspack_plugins[@]}"
        do :
            build_dir "$CODE_PATH/$dir" $2
        done
        build_dir "$CODE_PATH/newspack-theme" $2
        build_dir "$CODE_PATH/newspack-block-theme" $2
        ;;
    theme)
        build_dir "$CODE_PATH/newspack-theme" $2
        ;;
    block-theme)
        build_dir "$CODE_PATH/newspack-block-theme" $2
        ;;
    *)
        if [ ! -d "${CODE_PATH}/${WHAT_TO_BUILD}" ]; then
            echo "$WHAT_TO_BUILD directory does not exist"
            WHAT_TO_BUILD="newspack-${WHAT_TO_BUILD}"
            echo "Trying ${WHAT_TO_BUILD}"
            if [ ! -d "${CODE_PATH}/${WHAT_TO_BUILD}" ]; then
                echo "${WHAT_TO_BUILD} directory does not exits"
                exit 1
            fi
        fi
        build_dir "${CODE_PATH}/${WHAT_TO_BUILD}" $2
        ;;
esac
