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
