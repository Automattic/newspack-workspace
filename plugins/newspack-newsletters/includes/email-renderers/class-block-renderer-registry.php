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

		$files = glob( __DIR__ . '/blocks/class-*.php' );
		foreach ( (array) $files as $file ) {
			require_once $file;
		}

		add_filter( 'block_type_metadata_settings', [ __CLASS__, 'update_block_settings' ], 11, 1 );
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
			$renderer_class            = self::$renderers[ $name ];
			self::$instances[ $name ]  = new $renderer_class();
		}
		$settings['render_email_callback'] = [ self::$instances[ $name ], 'render' ];
		return $settings;
	}
}
