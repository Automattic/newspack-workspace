<?php
/**
 * Load guard: keeps this plugin dormant while newspack-blocks is active.
 *
 * @package Newspack_Content_Blocks
 */

namespace Newspack_Content_Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether newspack-blocks, which registers the same blocks, is active.
 */
final class Guard {
	/**
	 * Cached answer.
	 *
	 * @var bool|null Null until checked.
	 */
	private static $is_legacy_plugin_active = null;

	/**
	 * Whether the newspack-blocks plugin has loaded, or will load later in this request.
	 *
	 * The bundled copy of newspack-blocks in newspack-plugin defines the same version
	 * constant, so that constant only counts when the bundled copy is not what
	 * defined it. The answer is cached on the first call.
	 *
	 * @return bool
	 */
	public static function is_legacy_plugin_active() {
		if ( null === self::$is_legacy_plugin_active ) {
			$bundled_copy_loaded            = class_exists( 'Newspack\Bundled_Blocks', false ) && \Newspack\Bundled_Blocks::is_loaded();
			self::$is_legacy_plugin_active = ( defined( 'NEWSPACK_BLOCKS__VERSION' ) && ! $bundled_copy_loaded ) || self::will_legacy_plugin_load(); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- newspack-blocks' own constant, not a site setting.
		}
		return self::$is_legacy_plugin_active;
	}

	/**
	 * Forget the cached answer. For tests.
	 */
	public static function reset() {
		self::$is_legacy_plugin_active = null;
	}

	/**
	 * Whether WordPress lists newspack-blocks as active and its file exists.
	 *
	 * Plugins load alphabetically, so newspack-blocks normally loads before this
	 * plugin; this also covers an active plugins list that's out of order.
	 *
	 * @return bool
	 */
	private static function will_legacy_plugin_load() {
		$legacy_plugin = 'newspack-blocks/newspack-blocks.php';
		$plugins       = (array) \get_option( 'active_plugins', [] );
		if ( \is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) \get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		return in_array( $legacy_plugin, $plugins, true ) && file_exists( WP_PLUGIN_DIR . '/' . $legacy_plugin );
	}
}
