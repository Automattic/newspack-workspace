<?php
/**
 * Bundled copy of newspack-blocks.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the copy of newspack-blocks in includes/newspack-blocks/, which holds
 * every newspack-blocks feature except the Content Loop and Carousel blocks.
 *
 * The copy keeps newspack-blocks' class, function, block, route and hook
 * names, so it only loads when the newspack-blocks plugin is not active.
 */
final class Bundled_Blocks {
	/**
	 * Whether the newspack-blocks plugin was active when plugins loaded.
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
		// Every plugin file is included by now, so newspack-blocks has defined its constants if it's active.
		\add_action( 'plugins_loaded', [ __CLASS__, 'maybe_load' ], 1 );
	}

	/**
	 * Whether the newspack-blocks plugin is active.
	 *
	 * The answer is cached on the first call, because loading the bundled copy
	 * defines the same constant.
	 *
	 * @return bool
	 */
	public static function is_legacy_plugin_active() {
		if ( null === self::$is_legacy_plugin_active ) {
			self::$is_legacy_plugin_active = defined( 'NEWSPACK_BLOCKS__VERSION' );
		}
		return self::$is_legacy_plugin_active;
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

		$loader_file = NEWSPACK_ABSPATH . 'includes/newspack-blocks/loader.php';
		define( 'NEWSPACK_BLOCKS__PLUGIN_FILE', $loader_file ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.
		define( 'NEWSPACK_BLOCKS__BLOCKS_DIRECTORY', 'dist/' ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.
		define( 'NEWSPACK_BLOCKS__PLUGIN_DIR', plugin_dir_path( $loader_file ) ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.
		define( 'NEWSPACK_BLOCKS__VERSION', NEWSPACK_PLUGIN_VERSION ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant.

		self::$is_loaded = true;
		require_once $loader_file;
	}
}
Bundled_Blocks::init();
