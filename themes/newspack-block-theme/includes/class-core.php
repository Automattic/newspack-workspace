<?php
/**
 * Newspack Block Theme core.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Main Core class.
 * Primary theme functionaltiy.
 */
final class Core {
	/**
	 * Initializer.
	 */
	public static function init() {
		\add_action( 'after_setup_theme', [ __CLASS__, 'theme_support' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'theme_styles' ] );
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'enqueue_block_assets' ] );
		\add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
		\add_filter( 'block_type_metadata', [ __CLASS__, 'block_variations' ] );
		\add_filter( 'get_block_templates', [ __CLASS__, 'set_custom_template_titles' ], 10, 3 );
	}

	/**
	 * Sets up theme defaults and registers support for various WordPress features.
	 *
	 * @since Newspack Block Theme 1.0
	 *
	 * @return void
	 */
	public static function theme_support() {
		// Enqueue editor styles.
		\add_editor_style( 'style.css' );

		// Make theme available for translation.
		\load_theme_textdomain( 'newspack-block-theme' );

		\add_theme_support( 'post-subtitle' );
	}

	/**
	 * Enqueue styles.
	 *
	 * @since Newspack Block Theme 1.0
	 *
	 * @return void
	 */
	public static function theme_styles() {
		// Register theme stylesheet.
		\wp_register_style(
			'newspack_block_theme-style',
			\get_stylesheet_directory_uri() . '/style.css',
			array(),
			\wp_get_theme()->get( 'Version' )
		);

		// Enqueue theme stylesheet.
		\wp_enqueue_style( 'newspack_block_theme-style' );

		// Strings for translation.
		$newspack_l10n = array(
			'close_menu'       => esc_html__( 'Close Menu', 'newspack-block-theme' ),
			'close_search'     => esc_html__( 'Close Search', 'newspack-block-theme' ),
			'comment_too_fast' => esc_html__( 'You are posting comments too quickly. Please wait a moment before trying again.', 'newspack-block-theme' ),
		);
		if ( wp_script_is( 'jetpack-instant-search', 'enqueued' ) ) {
			$newspack_l10n['jetpack_instant_search'] = 'true';
		}

		// Enqueue front-end JavaScript.
		wp_enqueue_script( 'newspack-main', get_theme_file_uri( '/dist/main.js' ), array(), wp_get_theme()->get( 'Version' ), true );
		wp_localize_script( 'newspack-main', 'newspackScreenReaderText', $newspack_l10n );
	}

	/**
	 * Enqueue editor scripts.
	 */
	public static function enqueue_block_assets() {
		if ( ! wp_should_load_block_editor_scripts_and_styles() ) {
			return;
		}
		// Enqueue editor JavaScript.
		wp_enqueue_script( 'editor-script', get_theme_file_uri( '/dist/editor.js' ), array( 'wp-blocks', 'wp-dom' ), wp_get_theme()->get( 'Version' ), true );
	}

	/**
	 * Body class.
	 *
	 * @since Newspack Block Theme 1.0
	 *
	 * @param array $classes Array of body class names.
	 * @return array Modified array of body class names.
	 */
	public static function body_class( $classes ) {
		$global_settings = wp_get_global_settings();

		$classes[] = 'theme-variation-' . esc_attr(
			$global_settings['custom']['className'] ?? 'default'
		);

		return $classes;
	}

	/**
	 * Set human-readable titles for custom post type templates.
	 *
	 * @param \WP_Block_Template[] $templates Array of block templates.
	 * @param array                $query     Template query arguments.
	 * @param string               $template_type Template type (wp_template or wp_template_part).
	 *
	 * @return \WP_Block_Template[] Modified array of block templates.
	 */
	public static function set_custom_template_titles( $templates, $query, $template_type ) {
		if ( 'wp_template' !== $template_type ) {
			return $templates;
		}

		$titles = [
			'single-newspack_nl_cpt'  => \__( 'Single Newsletter', 'newspack-block-theme' ),
			'archive-newspack_nl_cpt' => \__( 'Newsletter Archive', 'newspack-block-theme' ),
		];

		foreach ( $templates as $template ) {
			if ( isset( $titles[ $template->slug ] ) && 'theme' === $template->source ) {
				$template->title = $titles[ $template->slug ];
			}
		}

		return $templates;
	}

	/**
	 * Add block variations.
	 *
	 * @since Newspack Block Theme 1.0
	 *
	 * We may be able to replace this with JavaScript; I'm unclear whether isDefault isn't working, or just not working as I expect it to.
	 * See: https://github.com/WordPress/gutenberg/issues/28119
	 *
	 * @param array $metadata Block metadata.
	 * @return array Block metadata.
	 */
	public static function block_variations( $metadata ) {
		if ( $metadata['name'] == 'core/search' ) {
			$metadata['attributes']['buttonPosition']['default'] = 'button-inside';
			$metadata['attributes']['buttonUseIcon']['default']  = true;
			$metadata['attributes']['placeholder']['default']    = esc_html__( 'Search posts, categories, authors, ...', 'newspack-block-theme' );
			$metadata['attributes']['showLabel']['default']      = false;
		}
		if ( $metadata['name'] == 'core/navigation' ) {
			$metadata['attributes']['overlayMenu']['default'] = 'never';
		}
		return $metadata;
	}
}

Core::init();
