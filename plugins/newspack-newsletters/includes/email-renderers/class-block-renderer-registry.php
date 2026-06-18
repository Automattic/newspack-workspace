<?php
/**
 * Overrides WC email-editor per-block renderers with Newspack's.
 *
 * The package assigns each core block a `render_email_callback` via the
 * `block_type_metadata_settings` filter (priority 10). This registry hooks the
 * same filter at priority 11 and swaps the callback for the blocks Newspack
 * overrides, leaving every other block untouched.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Column as Package_Column;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Newspack block-renderer overrides with the email-editor package.
 */
class Block_Renderer_Registry {
	/**
	 * Lazily-built map of block name => Newspack renderer instance.
	 *
	 * @var array<string,object>|null
	 */
	private static $renderers = null;

	/**
	 * Hook the override filter once the package renderer classes are present.
	 *
	 * Guards on the package's concrete Column renderer (the class our overrides
	 * extend) so the override only wires up when the email-editor package is
	 * loaded — instantiating our renderers requires that parent class.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! class_exists( Package_Column::class ) ) {
			return;
		}
		add_filter( 'block_type_metadata_settings', [ __CLASS__, 'update_block_settings' ], 11, 1 );
	}

	/**
	 * The block name => renderer instance map, built once.
	 *
	 * Instantiating these renderers requires the package base class, which
	 * init() has already guarded before this is reached at runtime.
	 *
	 * @return array<string,object> Map of block name to renderer instance.
	 */
	private static function renderers(): array {
		if ( null === self::$renderers ) {
			self::$renderers = [
				'core/column' => new Blocks\Column(),
			];
		}
		return self::$renderers;
	}

	/**
	 * Swap the render callback for blocks Newspack overrides.
	 *
	 * @param array $settings Block type registration settings.
	 * @return array The (possibly modified) settings.
	 */
	public static function update_block_settings( array $settings ): array {
		$name      = $settings['name'] ?? '';
		$renderers = self::renderers();
		if ( isset( $renderers[ $name ] ) ) {
			$settings['render_email_callback'] = [ $renderers[ $name ], 'render' ];
		}
		return $settings;
	}
}
