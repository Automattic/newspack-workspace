<?php
/**
 * Overrides WC email-editor per-block renderers with Newspack's.
 *
 * The package assigns each core block a `render_email_callback` via the
 * `block_type_metadata_settings` filter (priority 10). This registry hooks the
 * same filter at priority 11 and swaps the callback for the blocks Newspack
 * overrides, leaving every other block untouched.
 *
 * Overrides self-register: each renderer in `blocks/` calls
 * `Block_Renderer_Registry::add()` at the bottom of its file, and `init()` loads
 * every file in that directory so the overrides register themselves. Adding an
 * override is therefore a drop-in new file with no edits to this class.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Newspack block-renderer overrides with the email-editor package.
 */
class Block_Renderer_Registry {
	/**
	 * Map of block name => renderer class name.
	 *
	 * @var array<string,string>
	 */
	private static $renderers = [];

	/**
	 * Lazily-instantiated renderer instances, keyed by block name.
	 *
	 * @var array<string,object>
	 */
	private static $instances = [];

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Register a Newspack renderer override for a block.
	 *
	 * Called from the bottom of each renderer file in `blocks/`. The class is
	 * instantiated lazily, the first time its block type is registered.
	 *
	 * @param string $block_name     Block name, e.g. `core/column`.
	 * @param string $renderer_class Fully-qualified renderer class name.
	 * @return void
	 */
	public static function add( string $block_name, string $renderer_class ): void {
		self::$renderers[ $block_name ] = $renderer_class;
		// Drop any instance cached under a previous class so a re-registration
		// doesn't keep serving the stale renderer.
		unset( self::$instances[ $block_name ] );
	}

	/**
	 * Load the block overrides and hook the override filter.
	 *
	 * Guards on the package's base block renderer so this only wires up when the
	 * email-editor package is loaded — the overrides extend package renderer
	 * classes. Loads every file in `blocks/` so each self-registers via add().
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		if ( ! class_exists( Abstract_Block_Renderer::class ) ) {
			return;
		}
		self::$initialized = true;

		self::discover( __DIR__ . '/blocks' );

		add_filter( 'block_type_metadata_settings', [ __CLASS__, 'update_block_settings' ], 11, 1 );
	}

	/**
	 * Discover and load the override files in a `blocks/` directory.
	 *
	 * Each `class-*.php` file self-registers via add() at its bottom, so loading
	 * the file is what populates the registry — there is no manual map. The files
	 * extend package renderer classes, so this must only run once the package is
	 * loaded (init() guards on that before calling here; the standalone seam is
	 * for tests that point it at a fixtures dir).
	 *
	 * @param string $blocks_dir Absolute path to a directory of `class-*.php` overrides.
	 * @return void
	 */
	public static function discover( string $blocks_dir ): void {
		$files = glob( $blocks_dir . '/class-*.php' );
		if ( false === $files ) {
			\Newspack_Newsletters_Logger::log( 'Email editor: could not read the block overrides directory; no overrides loaded.' );
			return;
		}
		foreach ( $files as $file ) {
			require_once $file;
		}
	}

	/**
	 * Swap the render callback for blocks Newspack overrides.
	 *
	 * @param array $settings Block type registration settings.
	 * @return array The (possibly modified) settings.
	 */
	public static function update_block_settings( array $settings ): array {
		$name = $settings['name'] ?? '';
		if ( ! isset( self::$renderers[ $name ] ) ) {
			return $settings;
		}
		if ( ! isset( self::$instances[ $name ] ) ) {
			$renderer_class = self::$renderers[ $name ];
			// Fail closed: a missing class (typo / premature registration) or one
			// without a render() method leaves the package callback in place
			// rather than fataling during block registration.
			if ( ! class_exists( $renderer_class ) || ! method_exists( $renderer_class, 'render' ) ) {
				\Newspack_Newsletters_Logger::log( 'Email editor: skipping invalid block override for ' . $name . ' (' . $renderer_class . ').' );
				return $settings;
			}
			self::$instances[ $name ] = new $renderer_class();
		}
		$settings['render_email_callback'] = [ self::$instances[ $name ], 'render' ];
		return $settings;
	}
}
