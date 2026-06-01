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

# Symlink standalone/local plugins and themes from the gitignored repos/ dir.
# These are separate checkouts that live outside the monorepo (e.g. private or
# customer-specific plugins, newspack-manager, licensed WooCommerce extensions),
# split by type so each lands in the right place:
#   repos/plugins/<name> -> wp-content/plugins/<name>
#   repos/themes/<name>  -> wp-content/themes/<name>
# Any directory works -- no registration needed. This runs after the monorepo
# loops above, so a name that also ships in plugins/ or themes/ is already
# linked and is skipped here: the tracked (monorepo) copy takes precedence.
# The mount is optional, so skip silently when a subdir is empty/absent.
REPOS_PATH="/newspack-repos"
for kind in plugins themes; do
	src_base="$REPOS_PATH/$kind"
	[ -d "$src_base" ] || continue
	for dir in "$src_base"/*/; do
		[ -d "$dir" ] || continue
		name=$(basename "$dir")
		link="$WP_PATH/$kind/$name"
		# -L also catches dangling symlinks that -e (which follows links) misses.
		if [ -L "${link}" ] || [ -e "${link}" ]; then
			# Distinguish a tracked monorepo copy (which takes precedence) from a
			# plain idempotent re-run where this same repos/ link already exists.
			case "$(readlink "${link}" 2>/dev/null)" in
				"$PLUGINS_PATH"/*|"$THEMES_PATH"/*)
					echo "skipping repos/$kind/$name: tracked monorepo copy takes precedence" ;;
				*)
					echo "$name already symlinked" ;;
			esac
		else
			echo "Symlinking standalone $kind $name"
			ln -s "$dir" "$link" || true
		fi
	done
done
