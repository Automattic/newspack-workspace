<?php
/**
 * Newspack Listings Blocks.
 *
 * Custom Gutenberg Blocks for Newspack Listings.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Core;
use Newspack_Listings\Products;
use Newspack_Listings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks class.
 * Sets up custom blocks for listings.
 */
final class Blocks {
	/**
	 * The single instance of the class.
	 *
	 * @var Blocks
	 */
	protected static $instance = null;

	/**
	 * Main Blocks instance.
	 * Ensures only one instance of Blocks is loaded or can be loaded.
	 *
	 * @return Blocks - Main instance.
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
		add_action( 'enqueue_block_assets', [ __CLASS__, 'manage_block_assets' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'custom_scripts' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'custom_styles' ] );
		add_action( 'init', [ __CLASS__, 'manage_view_assets' ] );
	}

	/**
	 * Enqueue editor assets.
	 */
	public static function manage_block_assets() {
		if ( ! wp_should_load_block_editor_scripts_and_styles() ) {
			return;
		}

		wp_enqueue_script(
			'newspack-listings-editor',
			NEWSPACK_LISTINGS_URL . 'dist/editor.js',
			[],
			NEWSPACK_LISTINGS_VERSION,
			true
		);

		$total_count     = 0;
		$post_type       = get_post_type();
		$post_types      = [];
		$post_type_label = ! empty( $post_type ) ? get_post_type_object( $post_type )->labels->singular_name : 'Post';

		foreach ( Core::NEWSPACK_LISTINGS_POST_TYPES as $slug => $name ) {
			$post_count          = wp_count_posts( $name )->publish;
			$total_count         = $total_count + $post_count;
			$post_types[ $slug ] = [
				'name'             => $name,
				'label'            => get_post_type_object( Core::NEWSPACK_LISTINGS_POST_TYPES[ $slug ] )->labels->singular_name,
				'show_in_inserter' => 0 < $post_count,
			];
		}

		$localized_data = [
			'post_type_label'    => $post_type_label,
			'post_type'          => $post_type,
			'post_type_slug'     => array_search( $post_type, Core::NEWSPACK_LISTINGS_POST_TYPES ),
			'post_types'         => $post_types,
			'currency'           => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : __( 'USD', 'newspack-listings' ),
			'currencies'         => function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [ 'USD' => __( 'United States (US) dollar', 'newspack-listings' ) ],

			// If we don't have ANY listings that can be added to a list yet, alert the editor so we can show messaging.
			'no_listings'        => 0 === $total_count,
			'date_format'        => get_option( 'date_format' ),
			'time_format'        => get_option( 'time_format' ),

			// Self-serve listings features are gated behind an environment variable.
			'self_serve_enabled' => Products::is_active(),
		];

		if ( Products::is_active() ) {
			$localized_data['self_serve_listing_types']      = Products::get_listing_types();
			$localized_data['self_serve_listing_expiration'] = Settings::get_settings( 'newspack_listings_single_purchase_expiration' );

			if ( Products::is_listing_customer() ) {
				$localized_data['is_listing_customer'] = true;
			}
		}

		wp_localize_script(
			'newspack-listings-editor',
			'newspack_listings_data',
			$localized_data
		);

		wp_register_style(
			'newspack-listings-editor',
			plugins_url( '../dist/editor.css', __FILE__ ),
			[],
			NEWSPACK_LISTINGS_VERSION
		);
		wp_style_add_data( 'newspack-listings-editor', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-listings-editor' );
	}

	/**
	 * Enqueue front-end assets.
	 */
	public static function manage_view_assets() {
		// Do nothing in editor environment.
		if ( is_admin() ) {
			return;
		}

		$src_directory  = NEWSPACK_LISTINGS_PLUGIN_FILE . 'src/blocks/';
		$dist_directory = NEWSPACK_LISTINGS_PLUGIN_FILE . 'dist/';
		$iterator       = new \DirectoryIterator( $src_directory );

		foreach ( $iterator as $block_directory ) {
			if ( ! $block_directory->isDir() || $block_directory->isDot() ) {
				continue;
			}
			$type = $block_directory->getFilename();

			// If view.php is found, include it and use for block rendering.
			$view_php_path = $src_directory . $type . '/view.php';
			if ( file_exists( $view_php_path ) ) {
				include_once $view_php_path;
				continue;
			}

			// If block.json exists, use it to register the block with default attributes.
			$block_config_file        = $src_directory . $type . '/block.json';
			$block_name               = "newspack-listings/{$type}";
			$block_default_attributes = null;
			if ( file_exists( $block_config_file ) ) {
				$block_config             = json_decode( file_get_contents( $block_config_file ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				$block_name               = $block_config['name'];
				$block_default_attributes = $block_config['attributes'];
			}

			// If view.php is missing but view Javascript file is found, do generic view asset loading.
			$view_js_path = $dist_directory . $type . '/view.js';
			if ( file_exists( $view_js_path ) ) {
				register_block_type(
					$block_name,
					[
						'api_version'     => 3,
						'render_callback' => function( $attributes, $content ) use ( $type ) {
							Newspack_Blocks::enqueue_view_assets( $type );
							return $content;
						},
						'attributes'      => $block_default_attributes,
					]
				);
			}
		}
	}

	/**
	 * Enqueue custom scripts for Newspack Listings front-end components.
	 */
	public static function custom_scripts() {
		if ( ! Utils\is_amp() && has_block( 'newspack-listings/curated-list', get_the_ID() ) ) {
			wp_enqueue_script(
				'newspack-listings-curated-list',
				NEWSPACK_LISTINGS_URL . 'dist/curated-list.js',
				[ 'mediaelement-core' ],
				NEWSPACK_LISTINGS_VERSION,
				true
			);
		}
	}

	/**
	 * Enqueue custom styles for Newspack Listings front-end components.
	 */
	public static function custom_styles() {
		if ( is_admin() ) {
			return;
		}

		$post_id = get_the_ID();

		// Styles for listing archives.
		if ( Utils\archive_should_include_listings() ) {
			wp_enqueue_style(
				'newspack-listings-archives',
				NEWSPACK_LISTINGS_URL . 'dist/archives.css',
				[],
				NEWSPACK_LISTINGS_VERSION
			);
		}

		// Styles for Curated List block.
		wp_enqueue_style(
			'newspack-listings-curated-list',
			NEWSPACK_LISTINGS_URL . 'dist/curated-list.css',
			[],
			NEWSPACK_LISTINGS_VERSION
		);

		// Styles for any singular listing type.
		if ( is_singular( array_values( Core::NEWSPACK_LISTINGS_POST_TYPES ) ) ) {
			wp_enqueue_style(
				'newspack-listings-patterns',
				NEWSPACK_LISTINGS_URL . 'dist/patterns.css',
				[],
				NEWSPACK_LISTINGS_VERSION
			);
		}

		// Styles for singular event listings.
		if ( is_singular( Core::NEWSPACK_LISTINGS_POST_TYPES['event'] ) ) {
			wp_enqueue_style(
				'newspack-listings-event',
				NEWSPACK_LISTINGS_URL . 'dist/event.css',
				[],
				NEWSPACK_LISTINGS_VERSION
			);
		}

		// Styles for Self-Serve Listings admin UI.
		if (
			( is_singular() && has_block( 'newspack-listings/self-serve-listings', $post_id ) ) ||
			( function_exists( 'is_account_page' ) && is_account_page() )
		) {
			wp_enqueue_style(
				'newspack-listings-self-serve-listings',
				NEWSPACK_LISTINGS_URL . 'dist/self-serve-listings.css',
				[],
				NEWSPACK_LISTINGS_VERSION
			);
		}
	}
}

Blocks::instance();
