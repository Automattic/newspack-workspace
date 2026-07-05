/**
 * Ambient declarations for globals consumed by the block editor and view
 * scripts under src/blocks/. This file is a global script: no top-level
 * imports, so every declaration lands in the global scope (inline import()
 * types only).
 */

/**
 * SCSS modules (e.g. the shared colors palette) export a map of names to
 * resolved values. Plain (non-module) .scss imports are side-effect only and
 * need no declaration.
 */
declare module '*.module.scss' {
	const classes: Record< string, string >;
	export default classes;
}

/**
 * Ambient module declaration for @wordpress/server-side-render, which ships no
 * types of its own (unlike most @wordpress/* packages). Everything imported
 * from it is `any`.
 */
declare module '@wordpress/server-side-render';

/**
 * Metering data exposed to the editor when Memberships is active
 * (`content_gate_data` in Blocks::enqueue_block_editor_assets()). Nested in
 * the localized object, so numbers survive as numbers.
 */
interface NewspackBlocksContentGateData {
	anonymous_metered_views: number;
	loggedin_metered_views: number;
	metered_views: number;
	metering_period: string;
}

/**
 * Editor-side config localized as `newspack_blocks` on the `newspack-blocks`
 * script handle by Blocks::enqueue_block_editor_assets()
 * (includes/class-blocks.php). Top-level scalars pass through
 * wp_localize_script(), which casts them to strings ('1'/'') — the boolean
 * flags are only ever used as truthy/falsy.
 */
interface NewspackBlocksScriptData {
	has_newsletters: boolean | string;
	has_reader_activation: boolean | string;
	newsletters_url: string;
	has_google_oauth: boolean | string;
	google_logo_svg: string;
	reader_activation_terms: string;
	reader_activation_url: string;
	has_recaptcha: boolean | string;
	recaptcha_url: string;
	is_block_theme: boolean | string;
	corrections_enabled: boolean | string;
	collections_enabled: boolean | string;
	has_memberships: boolean | string;
	is_content_gate_countdown_active: boolean | string;
	/** Present only when Memberships is active. */
	content_gate_data?: NewspackBlocksContentGateData;
}

declare const newspack_blocks: NewspackBlocksScriptData;

/**
 * Metering config localized as `newspack_metering_settings` by
 * Metering::enqueue_scripts() (includes/content-gate/class-metering.php).
 * Top-level scalars pass through wp_localize_script(), which casts them to
 * strings. Only the members consumed by the Content Gate Countdown view
 * script are declared explicitly.
 */
declare const newspack_metering_settings: {
	count: number | string;
	gate_id?: number | string;
	[ key: string ]: unknown;
};

/**
 * A social/contact entry in the author profile data shared by the
 * newspack-blocks Author Profile block (via block or React context).
 */
interface NewspackAuthorSocialData {
	url?: string;
	svg?: string;
}

/**
 * Author profile data provided by a parent Author Profile block, as exposed
 * by the newspack-blocks plugin.
 */
interface NewspackAuthorProfileData {
	name?: string;
	avatar?: string;
	email?: string | NewspackAuthorSocialData;
	newspack_phone_number?: string | NewspackAuthorSocialData;
	social?: Record< string, NewspackAuthorSocialData >;
}

/*
 * The reader-activation client (including its store) is declared canonically
 * in newspack-scripts/types/newspack-globals.d.ts, included via tsconfig.
 */

interface Window {
	newspack_blocks: NewspackBlocksScriptData;
	/**
	 * WP admin global identifying the current screen (e.g. 'site-editor').
	 */
	pagenow?: string;
	/**
	 * Editor data localized by Contribution_Meter_Block::enqueue_editor_assets().
	 * All values pass through wp_localize_script() as strings.
	 */
	newspack_contribution_meter_data?: {
		currencySymbol?: string;
		currencyPosition?: string;
		thousandSeparator?: string;
		decimalSeparator?: string;
		decimals?: number | string;
		minStartDate?: string;
		maxEndDate?: string;
	};
}
