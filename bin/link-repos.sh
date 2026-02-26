#!/bin/bash

source /var/scripts/repos.sh

set -e

WP_PATH=$1
# if WP_PATH is empty, use default
if [ -z "$WP_PATH" ]; then
	WP_PATH="/var/www/html/wp-content"
fi

CODE_PATH="/newspack-repos"

if [ ! -d "${WP_PATH}" ]; then
	echo "$WP_PATH directory does not exist"
	exit 1
fi

# Link all directories in /newspack-repos
readarray -t NEWSPACK_REPOS_DIRS < <(ls -d $CODE_PATH/*/)
for dir in "${NEWSPACK_REPOS_DIRS[@]}"
do :
	dir=$(basename "$dir")
	link="$WP_PATH/plugins/$dir"
	if [ -e "${link}" ]; then
		echo "$dir already symlinked"
	else
		echo "Symlinking $dir"
		ln -s "$CODE_PATH/$dir" "$link"
	fi
done

for dir in "${newspack_themes[@]}"
do :
	link="$WP_PATH/themes/$dir"
	if [ -L "${link}" ]; then
		echo "$dir already symlinked"
	else
		echo "Symlinking $dir"
		ln -s "$CODE_PATH/newspack-theme/$dir" "$link"
	fi
done

for dir in "${newspack_block_theme[@]}"
do :
	link="$WP_PATH/themes/$dir"
	if [ -L "${link}" ]; then
		echo "$dir already symlinked"
	else
		echo "Symlinking $dir"
		ln -s "$CODE_PATH/$dir" "$link"
	fi
done

link="/var/www/manager-html/wp-content/plugins/newspack-manager-client"
if [ -L "${link}" ]; then
	echo "newspack-manager-client already symlinked"
else
	echo "Symlinking newspack-manager-client"
	ln -s "$CODE_PATH/newspack-manager-client" "$link"
fi
