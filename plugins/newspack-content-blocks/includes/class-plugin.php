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
	 * relative to the plugin root. `init()` is called on each class that has one; later
	 * tasks add to this list. A sub-namespace is written in the key, e.g. `REST\Foo`.
	 *
	 * @var array<string, string>
	 */
	const FILES = [
		'Blocks'                   => 'includes/class-blocks.php',
		'API'                      => 'includes/class-api.php',
		'REST\Articles_Controller' => 'src/blocks/homepage-articles/class-articles-controller.php',
		'REST\Authors_Controller'  => 'includes/class-authors-controller.php',
		'Caching'                  => 'includes/class-caching.php',
		'Patterns'                 => 'includes/class-patterns.php',
		'Editor'                   => 'includes/class-editor.php',
	];

	/**
	 * Plain files that define namespaced functions rather than a class, as paths
	 * relative to the plugin root. Required, in order, after FILES.
	 *
	 * @var string[]
	 */
	const INCLUDES = [
		'src/blocks/homepage-articles/view.php',
		'src/blocks/carousel/view.php',
	];

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
			if ( method_exists( $class, 'init' ) ) {
				$class::init();
			}
		}

		foreach ( self::INCLUDES as $file ) {
			require_once NEWSPACK_CONTENT_BLOCKS_PLUGIN_DIR . $file;
		}
	}
}
