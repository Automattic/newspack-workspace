#!/bin/bash

# Monorepo layout: plugins live in plugins/, themes in themes/.
# The container mount point is /newspack-plugins/ and /newspack-themes/.

newspack_plugins=(
	"newspack-ads"
	"newspack-blocks"
	"newspack-listings"
	"newspack-newsletters"
	"newspack-plugin"
	"newspack-popups"
	"newspack-sponsors"
	"republication-tracker-tool"
	"super-cool-ad-inserter"
	"newspack-multibranded-site"
	"newspack-network"
	"newspack-story-budget"
)

newspack_themes=(
	"newspack-theme"
	"newspack-block-theme"
)

woocommerce_plugins=(
	"woocommerce"
	"woocommerce-gateway-stripe"
	"woocommerce-subscriptions"
	"woocommerce-memberships"
	"woocommerce-name-your-price"
)

# Maps a plugin/theme name to its host-side directory relative to the
# workspace root. Used by the n script for cwd detection and path translation.
get_repo_host_path() {
	local name="$1"
	for p in "${newspack_plugins[@]}"; do
		if [[ "$p" == "$name" ]]; then
			echo "plugins/$name"
			return
		fi
	done
	for t in "${newspack_themes[@]}"; do
		if [[ "$t" == "$name" ]]; then
			echo "themes/$name"
			return
		fi
	done
	echo ""
}

# Maps a name to its standalone checkout under the gitignored repos/ dir,
# returning the host-side path relative to the workspace root (e.g.
# "repos/plugins/newspack-manager") or "" when no such checkout exists.
#
# Host-side only: needs $NABSPATH (the workspace root), so never call this from
# code that runs inside the container, where repos.sh is sourced without it.
# Monorepo plugins/themes take precedence, so callers should try
# get_repo_host_path first and fall back to this.
get_standalone_repo_host_path() {
	local name="$1"
	if [[ -d "$NABSPATH/repos/plugins/$name" ]]; then
		echo "repos/plugins/$name"
	elif [[ -d "$NABSPATH/repos/themes/$name" ]]; then
		echo "repos/themes/$name"
	else
		echo ""
	fi
}

# Verifies that a repos/ checkout is its own git repository (so a worktree can
# be created from it). Some repos/ entries are plain unzipped plugins whose git
# lookups resolve up to the monorepo's own .git — those cannot be worktree'd.
# Prints nothing; returns 0 when standalone, 1 otherwise. Needs $NABSPATH.
#
# Both toplevels are taken from git (physical, symlink-resolved paths) so the
# comparison holds even when $NABSPATH reaches the workspace through a symlink
# (bin/_common.sh derives it with a plain `pwd`, which keeps symlinks). A raw
# string compare against $NABSPATH would misclassify an unzipped plugin as
# standalone in that case.
is_standalone_git_repo() {
	local host_path="$1"
	local dir="$NABSPATH/$host_path"
	local toplevel monorepo_toplevel
	toplevel=$(git -C "$dir" rev-parse --show-toplevel 2>/dev/null)
	monorepo_toplevel=$(git -C "$NABSPATH" rev-parse --show-toplevel 2>/dev/null)
	[[ -n "$toplevel" && "$toplevel" != "$monorepo_toplevel" ]]
}
