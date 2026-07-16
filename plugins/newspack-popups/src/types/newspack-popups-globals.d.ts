/**
 * Newspack Popups front-end/editor globals: this unit's own `window` globals,
 * localized by PHP and consumed across `src/criteria` and `src/editor`.
 *
 * Global ambient script: no top-level import/export, inline import() types only.
 */

/**
 * A single popup/prompt criteria registry entry, as built by
 * `registerCriteria()` (`src/criteria/utils`). Referenced here (rather than
 * re-declared) since criteria/utils.ts is the source of truth for its shape.
 */
type NewspackPopupsCriteria = import( '../criteria/utils' ).Criteria;

/**
 * A single entry of `newspackPopupsCriteria.config`, as built by
 * `Newspack_Popups_Criteria::get_criteria_config()`.
 */
interface NewspackPopupsCriteriaConfigEntry {
	matchingFunction: string;
	matchingAttribute: string;
	optionParams: Record< string, unknown >;
	[ key: string ]: unknown;
}

/**
 * `window.newspackPopupsCriteria` / bare `newspackPopupsCriteria`, localized by
 * `Newspack_Popups_Criteria::enqueue_scripts()` and populated at runtime by
 * `src/criteria/index.ts` (which seeds `.criteria` from `.config`).
 */
interface NewspackPopupsCriteriaGlobal {
	is_non_preview_user?: boolean;
	config?: Record< string, NewspackPopupsCriteriaConfigEntry >;
	criteria: Record< string, NewspackPopupsCriteria >;
}

/**
 * Bare global, referenced without a `window.` prefix by files carrying a
 * `/* global newspackPopupsCriteria *\/` comment (criteria/index.ts,
 * criteria/default/user-account.ts).
 */
declare const newspackPopupsCriteria: NewspackPopupsCriteriaGlobal;

/** An archive page type option, as built by `Newspack_Popups_Model::get_available_archive_page_types()`. */
interface NewspackPopupsArchivePageType {
	name: string;
	label: string;
}

/** A popup size option, as built by `Newspack_Popups_Model::get_popup_size_options()`. */
interface NewspackPopupsSizeOption {
	value: string;
	label: string;
}

/** A public, REST-exposed custom post type, as returned by `get_post_types( [...], 'objects' )`. */
interface NewspackPopupsPostType {
	name: string;
	label: string;
	[ key: string ]: unknown;
}

/**
 * `window.newspack_popups_data` / bare `newspack_popups_data`, localized by
 * `Newspack_Popups::enqueue_block_editor_assets()` on the prompt editor screen.
 */
interface NewspackPopupsData {
	frontend_url: string;
	preview_post: string;
	preview_archive: string;
	/** Custom placement slug to label, always including the built-in `custom1`-`custom3`. */
	custom_placements: Record< string, string >;
	overlay_placements: string[];
	popup_size_options: NewspackPopupsSizeOption[];
	available_archive_page_types: NewspackPopupsArchivePageType[];
	taxonomy: string;
	is_prompt: boolean;
	segments_taxonomy: string;
	segments_admin_url: string;
	available_post_types: NewspackPopupsPostType[];
	/** Post meta key to abbreviated preview query-arg key, e.g. `{ placement: 'pp' }`. */
	preview_query_keys: Record< string, string >;
	segmentation_enabled: boolean;
}

/**
 * Bare global, referenced without a `window.` prefix by `src/editor/index.tsx`
 * (carries a `/* global newspack_popups_data *\/` comment).
 */
declare const newspack_popups_data: NewspackPopupsData;

/** A merge tag, as built by `Merge_Tag::to_array()`. */
interface NewspackPopupsMergeTag {
	name: string;
	title: string;
	description: string;
	criteria?: string | null;
}

/**
 * `window.newspack_popups_merge_tags`, localized by
 * `Merge_Tags::enqueue_block_editor_assets()`.
 */
interface NewspackPopupsMergeTags {
	tags: NewspackPopupsMergeTag[];
}

interface Window {
	newspackPopupsCriteria: NewspackPopupsCriteriaGlobal;
	newspack_popups_data?: NewspackPopupsData;
	newspack_popups_merge_tags?: NewspackPopupsMergeTags;
}
