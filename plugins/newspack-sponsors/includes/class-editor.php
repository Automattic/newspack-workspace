<?php
/**
 * Newspack Sponsors editor.
 *
 * Editor resources for Newspack Sponsors CPT.
 *
 * @package Newspack_Sponsors
 */

namespace Newspack_Sponsors;

use Newspack_Sponsors\Core;
use Newspack_Sponsors\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Editor class.
 * Editor resources needed for the Sponsors CPT.
 */
final class Editor {

	/**
	 * The single instance of the class.
	 *
	 * @var Editor
	 */
	protected static $instance = null;

	/**
	 * Main Editor Instance.
	 * Ensures only one instance of Editor is loaded or can be loaded.
	 *
	 * @return Editor - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wpseo_primary_term_taxonomies', [ __CLASS__, 'disable_yoast_primary_category_picker' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );
	}

	/**
	 * Disable the Yoast primary category picker for Sponsor posts and terms.
	 *
	 * @param array  $taxonomies Array of taxonomies.
	 * @param string $post_type Post type of the current post.
	 */
	public static function disable_yoast_primary_category_picker( $taxonomies, $post_type ) {
		// Disable for all taxonomies on Sponsor posts.
		if ( Core::NEWSPACK_SPONSORS_CPT === $post_type ) {
			return [];
		}

		// Disable for sponsor tax terms everywhere.
		if ( isset( $taxonomies[ Core::NEWSPACK_SPONSORS_TAX ] ) ) {
			unset( $taxonomies[ Core::NEWSPACK_SPONSORS_TAX ] );
		}

		return $taxonomies;
	}

	/**
	 * Load up JS/CSS for editor.
	 */
	public static function enqueue_block_editor_assets() {
		$allowed_post_types = apply_filters(
			'newspack_sponsors_post_types',
			[ 'post', 'page' ]
		);

		$allowed_post_types[] = Core::NEWSPACK_SPONSORS_CPT;

		// Only enqueue assets for allowed post types.
		if ( ! in_array( get_post_type(), $allowed_post_types, true ) ) {
			return;
		}

		wp_enqueue_script(
			'newspack-sponsors-editor',
			NEWSPACK_SPONSORS_URL . 'dist/editor.js',
			[],
			filemtime( NEWSPACK_SPONSORS_PLUGIN_FILE . 'dist/editor.js' ),
			true
		);

		wp_localize_script(
			'newspack-sponsors-editor',
			'newspack_sponsors_data',
			[
				'post_type' => get_post_type(),
				'settings'  => Settings::get_settings(),
				'defaults'  => Settings::get_default_settings(),
				'cpt'       => Core::NEWSPACK_SPONSORS_CPT,
				'tax'       => Core::NEWSPACK_SPONSORS_TAX,
			]
		);

		wp_register_style(
			'newspack-sponsors-editor',
			plugins_url( '../dist/editor.css', __FILE__ ),
			[],
			filemtime( NEWSPACK_SPONSORS_PLUGIN_FILE . 'dist/editor.css' )
		);
		wp_style_add_data( 'newspack-sponsors-editor', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-sponsors-editor' );
	}
}

Editor::instance();
