/**
 * Constants for the settings page.
 */

/**
 * Settings page namespace.
 */
export const PAGE_NAMESPACE = 'newspack-settings';

/**
 * Theme and Brand.
 */
export const THEME_BRAND_DEFAULTS = {
	// Colors.
	header_color: 'custom',
	theme_colors: 'default',
	primary_color_hex: '#003da5',
	secondary_color_hex: '#666666',
	// Typography.
	font_header: '',
	font_body: '',
	accent_allcaps: true,
	custom_font_import_code: undefined,
	custom_font_import_code_alternate: undefined,
	// Header.
	header_center_logo: false,
	header_simplified: false,
	header_solid_background: false,
	header_color_hex: '#003da5',
	custom_logo: '',
	logo_size: 0,
	header_text: false, // No custom_logo set.
	header_display_tagline: false, // No custom_logo set.
	// Footer.
	footer_copyright: '',
	footer_color: 'default',
	footer_color_hex: '',
	newspack_footer_logo: '',
	footer_logo_size: 'medium',
	// Homepage pattern.
	homepage_pattern_index: 0,
};

export const ADVANCED_SETTINGS_DEFAULTS = {
	// Author Bio.
	show_author_bio: true,
	show_author_email: false,
	author_bio_length: 200,
	// Default Featured Image and Post Template.
	featured_image_default: 'large',
	post_template_default: 'default',
	// Featured Image and Post Template for All Posts.
	featured_image_all_posts: 'none',
	post_template_all_posts: 'none',
	// Media Credits.
	newspack_image_credits_placeholder_url: undefined,
	newspack_image_credits_class_name: 'image-credit',
	newspack_image_credits_prefix_label: 'Credit:',
	newspack_image_credits_placeholder: null,
	newspack_image_credits_auto_populate: false,
	// Post Date.
	post_time_ago: false,
	post_time_ago_cut_off: 14,
	post_updated_date: false,
	post_updated_date_threshold: 24,
	// PWA Display Mode.
	pwa_display_mode: 'minimal-ui',
	// Post content fallback image.
	newspack_default_image_url: undefined,
	// Private Tags settings (populated from server when NEWSPACK_PRIVATE_TAGS_ENABLED is active).
	newspack_private_tags_settings: undefined,
};

export const DEFAULT_THEME_MODS: ThemeMods = {
	...THEME_BRAND_DEFAULTS,
	...ADVANCED_SETTINGS_DEFAULTS,

	/**
	 * Misc.
	 */
	custom_css_post_id: -1,
};

export default {
	PAGE_NAMESPACE,
};
