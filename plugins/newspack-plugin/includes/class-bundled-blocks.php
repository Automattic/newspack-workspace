<?php
/**
 * Bundled copy of newspack-blocks.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Loads this plugin's copy of newspack-blocks: every newspack-blocks feature
 * except the Content Loop and Carousel blocks.
 *
 * The copy keeps newspack-blocks' file layout relative to the plugin root, and
 * its class, function, block, route and hook names, so it only loads when the
 * newspack-blocks plugin is not active.
 *
 * It loads while this plugin's files are included, as newspack-blocks did: plugins
 * load alphabetically, so newspack-blocks ran before this plugin and before
 * WooCommerce and its extensions. Waiting for `plugins_loaded` would hook the copy
 * in after all of them, which changes the order of callbacks sharing a priority and
 * lets `class_exists()` checks that were always false in newspack-blocks pass.
 */
final class Bundled_Blocks {
	/**
	 * Whether the newspack-blocks plugin is active.
	 *
	 * @var bool|null Null until checked.
	 */
	private static $is_legacy_plugin_active = null;

	/**
	 * Whether the bundled copy has been loaded.
	 *
	 * @var bool
	 */
	private static $is_loaded = false;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		self::maybe_load();
	}

	/**
	 * Whether the newspack-blocks plugin is active.
	 *
	 * True when newspack-blocks has already loaded, or will load later in this
	 * request. The answer is cached on the first call, because loading the bundled
	 * copy defines the same constant.
	 *
	 * @return bool
	 */
	public static function is_legacy_plugin_active() {
		if ( null === self::$is_legacy_plugin_active ) {
			self::$is_legacy_plugin_active = defined( 'NEWSPACK_BLOCKS__VERSION' ) || self::will_legacy_plugin_load(); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant, not a site setting.
		}
		return self::$is_legacy_plugin_active;
	}

	/**
	 * Whether WordPress will load newspack-blocks after this plugin, in this request.
	 *
	 * Plugins load alphabetically, so newspack-blocks normally loads first. This
	 * covers an active plugins list that's out of order.
	 *
	 * @return bool
	 */
	private static function will_legacy_plugin_load() {
		$plugins = (array) \get_option( 'active_plugins', [] );
		if ( \is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) \get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		foreach ( $plugins as $plugin ) {
			if ( 'newspack-blocks.php' === basename( $plugin ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the bundled copy has been loaded.
	 *
	 * @return bool
	 */
	public static function is_loaded() {
		return self::$is_loaded;
	}

	/**
	 * Load the bundled copy, unless the newspack-blocks plugin is active.
	 */
	public static function maybe_load() {
		if ( self::$is_loaded || self::is_legacy_plugin_active() ) {
			return;
		}

		// newspack-blocks' paths resolve against the plugin root, where the copy keeps its layout.
		define( 'NEWSPACK_BLOCKS__PLUGIN_FILE', NEWSPACK_PLUGIN_FILE ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.
		define( 'NEWSPACK_BLOCKS__BLOCKS_DIRECTORY', 'dist/' ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.
		define( 'NEWSPACK_BLOCKS__PLUGIN_DIR', NEWSPACK_ABSPATH ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.
		define( 'NEWSPACK_BLOCKS__VERSION', NEWSPACK_PLUGIN_VERSION ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.

		self::$is_loaded = true;
		self::load();
	}

	/**
	 * Load the copy. What newspack-blocks.php does, without the Content Loop and
	 * Carousel code and the text domain loading.
	 */
	private static function load() {
		require_once NEWSPACK_ABSPATH . 'includes/class-newspack-blocks.php';
		require_once NEWSPACK_ABSPATH . 'includes/class-newspack-blocks-api.php';
		require_once NEWSPACK_ABSPATH . 'includes/class-newspack-blocks-patterns.php';
		require_once NEWSPACK_ABSPATH . 'includes/modal-checkout/class-checkout-data.php';
		require_once NEWSPACK_ABSPATH . 'includes/modal-checkout/class-change-payment-gateway.php';
		require_once NEWSPACK_ABSPATH . 'includes/class-modal-checkout.php';
		require_once NEWSPACK_ABSPATH . 'includes/tracking/class-data-events.php';
		require_once NEWSPACK_ABSPATH . 'src/shared/authors.php';
		require_once NEWSPACK_ABSPATH . 'src/blocks/author-profile/class-wp-rest-newspack-authors-controller.php';
		require_once NEWSPACK_ABSPATH . 'src/blocks/author-list/class-wp-rest-newspack-author-list-controller.php';
		require_once NEWSPACK_ABSPATH . 'src/blocks/iframe/class-wp-rest-newspack-iframe-controller.php';

		// A closure rather than newspack-blocks' named functions: activating newspack-blocks
		// includes its main file in a request where this copy is loaded, and PHP declares
		// that file's functions before any of it runs.
		\add_action(
			'rest_api_init',
			function() {
				( new \WP_REST_Newspack_Authors_Controller() )->register_routes();
				( new \WP_REST_Newspack_Author_List_Controller() )->register_routes();
				( new \WP_REST_Newspack_Iframe_Controller() )->register_routes();
			}
		);

		\Newspack_Blocks::manage_view_scripts();
		\add_action( 'enqueue_block_assets', [ 'Newspack_Blocks', 'enqueue_block_assets' ] );
		\add_action( 'enqueue_block_assets', [ 'Newspack_Blocks', 'enqueue_placeholder_blocks_assets' ], 9999 );
		\add_action( 'enqueue_block_assets', [ 'Newspack_Blocks', 'enqueue_block_styles_assets' ] );
	}
}
Bundled_Blocks::init();
