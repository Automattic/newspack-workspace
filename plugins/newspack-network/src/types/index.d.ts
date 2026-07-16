/**
 * Ambient globals localized by this plugin's own PHP (wp_localize_script()).
 * Mirrors the shapes built in includes/content-distribution/class-editor.php
 * and includes/class-story-budget.php -- those are the source of truth.
 */

/**
 * Localized by Editor::enqueue_block_editor_assets_for_incoming_post().
 */
declare const newspack_network_incoming_post: {
	originalSiteUrl: string;
	originalPostEditUrl: string;
	unlinked: boolean;
	postTypeLabel: string;
};

/**
 * Localized by Editor::enqueue_block_editor_assets_for_outgoing_post().
 */
declare const newspack_network_outgoing_post: {
	default_status: string;
	network_sites: string[];
	distributed_meta: string;
	post_type_label: string;
};

/**
 * Localized by Story_Budget::enqueue_assets().
 */
declare const newspackStoryBudgetNetwork: {
	sites: { url: string; name: string }[];
};
