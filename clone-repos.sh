#!/bin/bash

source bin/repos.sh

mkdir -p repos

PROTOCOL="ssh"

while test $# -gt 0; do
    case "$1" in
        -h|--https)
        shift
        PROTOCOL="https"
        ;;
    *)
        break
        ;;
    esac
done

cd repos
for dir in "${newspack_plugins[@]}"
    do :
        if [[ $PROTOCOL = "ssh" ]]; then
            git clone git@github.com:Automattic/${dir}.git
        else
            git clone https://github.com/Automattic/${dir}.git
        fi
    done

if [[ $PROTOCOL = "ssh" ]]; then
    git clone git@github.com:Automattic/newspack-theme.git
else
    git clone https://github.com/Automattic/newspack-theme.git
fi

if [[ $PROTOCOL = "ssh" ]]; then
    git clone git@github.com:Automattic/newspack-block-theme.git
else
    git clone https://github.com/Automattic/newspack-block-theme.git
fi
