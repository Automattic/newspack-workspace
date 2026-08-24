<?php
/**
 * Overrides WC email-editor per-block renderers with Newspack's.
 *
 * Hooks `block_type_metadata_settings` at priority 11 (package runs at 10) to
 * swap the callback for overridden blocks. Overrides self-register: each file in
 * `blocks/` calls add() at its bottom; init() loads the directory. A second pass
 * at `woocommerce_email_editor_render_start` covers blocks registered without
 * metadata (plain register_block_type()), which never trigger that filter.
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
	 * Called from each file in blocks/; class is instantiated lazily on first use.
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
	 * Load block overrides and wire up the override filter.
	 *
	 * Guards on Abstract_Block_Renderer so this only runs when the email-editor
	 * package is loaded (override classes extend package renderer classes).
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

		// `block_type_metadata_settings` never fires for blocks registered via
		// register_block_type() (no metadata). Apply the overrides at render start
		// instead — the package fires this action just before rendering, after all
		// blocks are registered, and only during a WC email render (never MJML).
		add_action( 'woocommerce_email_editor_render_start', [ __CLASS__, 'apply_to_registered_blocks' ] );
	}

	/**
	 * Set render_email_callback on registered block types that have an override
	 * but no callback yet. Covers blocks registered without metadata (for which
	 * block_type_metadata_settings never fires). Idempotent — skips blocks already
	 * handled by the metadata filter.
	 *
	 * @return void
	 */
	public static function apply_to_registered_blocks(): void {
		if ( empty( self::$renderers ) ) {
			return;
		}
		$block_registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array_keys( self::$renderers ) as $name ) {
			$block_type = $block_registry->get_registered( $name );
			// Skip unregistered blocks and any block that already has a callback
			// (e.g. set by the metadata filter) so that path stays authoritative.
			if ( ! $block_type instanceof \WP_Block_Type || isset( $block_type->render_email_callback ) ) {
				continue;
			}
			$instance = self::get_renderer_instance( $name );
			if ( null === $instance ) {
				continue;
			}
			$block_type->render_email_callback = [ $instance, 'render' ];
		}
	}

	/**
	 * Load class-*.php override files from a directory; each self-registers via add().
	 *
	 * The $blocks_dir param is a test seam — production always passes __DIR__ . '/blocks'.
	 * Must run after the email-editor package is loaded; init() guarantees that.
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
		$name     = $settings['name'] ?? '';
		$instance = self::get_renderer_instance( $name );
		if ( null === $instance ) {
			return $settings;
		}
		$settings['render_email_callback'] = [ $instance, 'render' ];
		return $settings;
	}

	/**
	 * Lazily instantiate and return the override renderer for a block name.
	 *
	 * Fails closed (returns null) when the block has no override, the class is not
	 * a proper Abstract_Block_Renderer subclass, or its constructor throws — leaving
	 * the package callback in place instead of fataling. is_subclass_of() autoloads.
	 *
	 * @param string $name Block name, e.g. `core/column`.
	 * @return object|null The renderer instance, or null when unavailable.
	 */
	private static function get_renderer_instance( string $name ): ?object {
		if ( ! isset( self::$renderers[ $name ] ) ) {
			return null;
		}
		if ( isset( self::$instances[ $name ] ) ) {
			return self::$instances[ $name ];
		}
		$renderer_class = self::$renderers[ $name ];
		if ( ! is_subclass_of( $renderer_class, Abstract_Block_Renderer::class ) ) {
			\Newspack_Newsletters_Logger::log( 'Email editor: skipping invalid block override for ' . $name . ' (' . $renderer_class . ' is not a block renderer).' );
			return null;
		}
		try {
			// is_subclass_of() doesn't catch abstract subclasses or throwing constructors.
			self::$instances[ $name ] = new $renderer_class();
		} catch ( \Throwable $e ) {
			\Newspack_Newsletters_Logger::log( 'Email editor: could not instantiate block override for ' . $name . ' (' . $renderer_class . '): ' . $e->getMessage() );
			return null;
		}
		return self::$instances[ $name ];
	}
}
