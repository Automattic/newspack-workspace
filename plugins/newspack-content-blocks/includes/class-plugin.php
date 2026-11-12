<?php
/**
 * Plugin loader.
 *
 * @package Newspack_Content_Blocks
 */

namespace Newspack_Content_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's files, unless the guard keeps it dormant.
 */
final class Plugin {
	/**
	 * Files to load, in load order, as class name (in this namespace) => file path
	 * relative to the plugin root. Each class has an `init()` method; later tasks
	 * add to this list.
	 *
	 * @var array<string, string>
	 */
	const FILES = [];

	/**
	 * Whether the plugin has loaded.
	 *
	 * @var bool
	 */
	private static $is_loaded = false;

	/**
	 * Whether the plugin has loaded.
	 *
	 * @return bool
	 */
	public static function is_loaded() {
		return self::$is_loaded;
	}

	/**
	 * Load the plugin's files and initialize them. Safe to call more than once.
	 */
	public static function load() {
		if ( self::$is_loaded ) {
			return;
		}
		self::$is_loaded = true;

		foreach ( self::FILES as $class => $file ) {
			require_once NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR . $file;
			$class = __NAMESPACE__ . '\\' . $class;
			$class::init();
		}
	}
}
