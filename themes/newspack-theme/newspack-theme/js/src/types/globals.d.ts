/**
 * Ambient declarations shared by this theme's editor/front-end TS units.
 * Global-script form (no top-level imports/exports).
 */

/**
 * @wordpress/edit-post ships no TypeScript types (unlike most @wordpress/*
 * packages), so everything imported from it is `any` via this shorthand
 * declaration.
 */
declare module '@wordpress/edit-post';

/**
 * Localized on `newspack-amp-fallback` and `newspack-menu-accessibility` by
 * newspack_scripts() (functions.php).
 */
declare const newspackScreenReaderText: {
	open_search: string;
	close_search: string;
	expand_comments: string;
	collapse_comments: string;
	show_order_details: string;
	hide_order_details: string;
	open_dropdown_menu: string;
	close_dropdown_menu: string;
	is_amp: boolean;
};

/**
 * Localized on `newspack-amp-fallback-sponsors` by
 * newspack_sponsors_enqueue_scripts() (inc/newspack-sponsors.php).
 */
declare const newspackScreenReaderTextSponsors: {
	open_info: string;
	close_info: string;
};

/**
 * Localized on `newspack-hide-fse-blocks` by newspack_fse_blocks_to_remove()
 * (functions.php), a comma-separated string of block names to unregister.
 */
declare const updateAllowedBlocks: {
	removeblocks: string;
};

/**
 * Localized on `newspack-extend-featured-image-script` by
 * newspack_get_featured_image_post_types() (functions.php).
 */
declare const newspack_theme_featured_image_post_types: string[];

interface Window {
	/**
	 * Localized on `newspack-font-loading` by newspack_scripts()
	 * (functions.php). Optional to match the original `?.` access -- the
	 * enqueue always sets it, but the script guards defensively.
	 */
	newspackFontLoading?: {
		fonts: string[];
	};

	/**
	 * Localized on `newspack-post-meta-toggles` by
	 * newspack_get_post_toggle_post_types() (functions.php).
	 */
	newspack_post_meta_post_types: {
		hide_title: string[];
		show_share_buttons: string[];
	};
}
