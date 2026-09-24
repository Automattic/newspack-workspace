<?php
/**
 * Republish block pattern registration.
 *
 * @package Republication_Tracker_Tool
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Republish Section" pattern and its category.
 */
final class Republication_Tracker_Tool_Republish_Pattern {

	/**
	 * Pattern name.
	 */
	public const PATTERN_NAME = 'republication-tracker-tool/republish-section';

	/**
	 * Pattern category slug.
	 */
	public const CATEGORY_SLUG = 'republication-tracker-tool';

	/**
	 * Hook up registration.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	/**
	 * Register the pattern category and pattern.
	 */
	public static function register() {
		// Block pattern APIs are only available on WP 5.5+. Bail gracefully on
		// older installs instead of fataling on init.
		if ( ! function_exists( 'register_block_pattern' ) || ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category(
			self::CATEGORY_SLUG,
			[ 'label' => esc_html__( 'Republication', 'republication-tracker-tool' ) ]
		);

		$description = esc_html__( 'Republish our articles for free, online or in print, under a Creative Commons license.', 'republication-tracker-tool' );

		// Plain __() (not esc_html__): buttonText is JSON-encoded into a block
		// attribute and escaped again by the block's render_callback, so escaping
		// here would double-encode entities in translations (apostrophes, etc.).
		$button_text     = __( 'Republish This Story', 'republication-tracker-tool' );
		$button_text_enc = wp_json_encode( $button_text );

		$content = <<<HTML
<!-- wp:group {"className":"republication-tracker-tool-republish-section"} -->
<div class="wp-block-group republication-tracker-tool-republish-section">
	<!-- wp:paragraph -->
	<p>{$description}</p>
	<!-- /wp:paragraph -->

	<!-- wp:republication-tracker-tool/republish-button {"buttonText":{$button_text_enc}} /-->
</div>
<!-- /wp:group -->
HTML;

		// Hide the pattern from the inserter on classic themes (matches the
		// republish-button block's gating). Stays registered so existing
		// instances and inter-block references keep working.
		$inserter = ! function_exists( 'wp_is_block_theme' ) || wp_is_block_theme();

		register_block_pattern(
			self::PATTERN_NAME,
			[
				'title'       => esc_html__( 'Republish Section', 'republication-tracker-tool' ),
				'description' => esc_html__( 'A paragraph, republish button, and Creative Commons license badge grouped together.', 'republication-tracker-tool' ),
				'categories'  => [ self::CATEGORY_SLUG ],
				'content'     => $content,
				'inserter'    => $inserter,
			]
		);
	}
}

Republication_Tracker_Tool_Republish_Pattern::init();
