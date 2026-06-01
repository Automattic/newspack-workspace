#!/bin/bash

source /var/scripts/repos.sh
source /var/scripts/resolve-project-path.sh

set -e

WP_PATH=$1
if [ -z "$WP_PATH" ]; then
	WP_PATH="/var/www/html/wp-content"
fi

if [ ! -d "${WP_PATH}" ]; then
	echo "$WP_PATH directory does not exist"
	exit 1
fi

# Symlink all plugins from /newspack-plugins/ into wp-content/plugins/.
for dir in "$PLUGINS_PATH"/*/; do
	name=$(basename "$dir")
	link="$WP_PATH/plugins/$name"
	if [ -e "${link}" ]; then
		echo "$name already symlinked"
	else
		echo "Symlinking plugin $name"
		ln -s "$dir" "$link" || true
	fi
done

# Symlink standalone repos from /newspack-repos/ into wp-content/plugins/.
# These are separate checkouts under the gitignored repos/ directory (e.g.
# private or customer-specific plugins, newspack-manager) that live outside the
# monorepo. The mount is optional, so skip silently when repos/ is empty/absent.
REPOS_PATH="/newspack-repos"
if [ -d "$REPOS_PATH" ]; then
	for dir in "$REPOS_PATH"/*/; do
		[ -d "$dir" ] || continue
		name=$(basename "$dir")
		link="$WP_PATH/plugins/$name"
		# -L also catches dangling symlinks that -e (which follows links) misses.
		if [ -L "${link}" ] || [ -e "${link}" ]; then
			echo "$name already symlinked"
		else
			echo "Symlinking standalone repo $name"
			ln -s "$dir" "$link" || true
		fi
	done
fi

# Symlink themes. The classic theme contains child themes as subdirectories.
for dir in "$THEMES_PATH"/*/; do
	name=$(basename "$dir")
	if [ "$name" = "newspack-theme" ]; then
		# Classic theme: symlink each child theme directory.
		for child in "$dir"newspack-*/; do
			[ -d "$child" ] || continue
			child_name=$(basename "$child")
			link="$WP_PATH/themes/$child_name"
			if [ -L "${link}" ]; then
				echo "$child_name already symlinked"
			else
				echo "Symlinking theme $child_name"
				ln -s "$child" "$link" || true
			fi
		done
		# Also symlink the base theme directory itself.
		link="$WP_PATH/themes/$name"
		if [ -L "${link}" ]; then
			echo "$name already symlinked"
		else
			echo "Symlinking theme $name"
			ln -s "${dir}newspack-theme" "$link" || true
		fi
	else
		link="$WP_PATH/themes/$name"
		if [ -L "${link}" ]; then
			echo "$name already symlinked"
		else
			echo "Symlinking theme $name"
			ln -s "$dir" "$link" || true
		fi
	fi
done
