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
 * The `block_type_metadata_settings` filter only fires for blocks registered via
 * `register_block_type_from_metadata()`. Blocks registered with a plain
 * `register_block_type()` call (e.g. `newspack-newsletters/posts-inserter`) never
 * run that filter, so their override would never be wired up. To cover those, a
 * second pass (`apply_to_registered_blocks()`) runs at render start and sets
 * `render_email_callback` directly on any already-registered block type that has
 * an override but no callback yet — the same dynamic property the package reads.
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

		// The metadata filter above misses blocks registered without metadata (plain
		// register_block_type()), so it never sets their render_email_callback. Apply
		// the overrides to already-registered block types at render start instead.
		// The package fires this action inside its content renderer right before it
		// renders the blocks, so it runs after all blocks are registered, only when a
		// WC email render actually happens, in every render context (REST preview,
		// sending, cron) — and never for the MJML renderer, which doesn't boot the
		// package and so never fires it.
		add_action( 'woocommerce_email_editor_render_start', [ __CLASS__, 'apply_to_registered_blocks' ] );
	}

	/**
	 * Set render_email_callback on already-registered block types that have an
	 * override but no callback yet.
	 *
	 * Covers blocks registered without metadata, for which
	 * `block_type_metadata_settings` never fires. Idempotent: only fills in a
	 * callback that is missing, so the metadata path stays authoritative for the
	 * blocks it already handled and re-running this is a no-op. Setting the dynamic
	 * `render_email_callback` property directly is what the package itself relies on.
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
		$name     = $settings['name'] ?? '';
		$instance = self::get_renderer_instance( $name );
		if ( null === $instance ) {
			return $settings;
		}
		$settings['render_email_callback'] = [ $instance, 'render' ];
		return $settings;
	}

	/**
	 * Resolve (and lazily instantiate) the override renderer for a block name.
	 *
	 * Shared by both override paths — the metadata filter
	 * (update_block_settings()) and the render-start pass
	 * (apply_to_registered_blocks()) — so they share one fail-closed guard.
	 *
	 * Fails closed (returns null) when the block has no override, the override
	 * class isn't a package block renderer (missing, wrong type, the abstract base
	 * itself), or its constructor throws — so a bad override leaves the package
	 * callback in place rather than fataling during block registration or render.
	 * is_subclass_of() autoloads and returns false for a non-existent class.
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
			// The type guard above doesn't catch an abstract subclass or a throwing /
			// required-arg constructor, so instantiation stays in a try/catch.
			self::$instances[ $name ] = new $renderer_class();
		} catch ( \Throwable $e ) {
			\Newspack_Newsletters_Logger::log( 'Email editor: could not instantiate block override for ' . $name . ' (' . $renderer_class . '): ' . $e->getMessage() );
			return null;
		}
		return self::$instances[ $name ];
	}
}
