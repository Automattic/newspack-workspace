<?php
/**
 * Normalize parsed blocks before the WC email engine preprocesses them.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites block attributes the WC email engine doesn't understand into ones it does.
 *
 * The engine renders from each block's saved markup and attributes. Anything WordPress
 * only works out at render time, through block supports, never reaches it. This runs on
 * the package's parsed-blocks filter, ahead of its own preprocessing, so the engine's
 * width and spacing calculations see the normalized attributes.
 */
class Block_Normalizer {

	/**
	 * Priority on `woocommerce_email_blocks_renderer_parsed_blocks`: before the package's
	 * own preprocessing, which runs at the default priority 10.
	 */
	const PRIORITY = 5;

	/**
	 * Whether core's block-hooks pass over `the_content` is running.
	 *
	 * @var bool
	 */
	private static bool $in_block_hooks_pass = false;

	/**
	 * Number of renders currently holding the hooks. `render_wc()` can nest, and the
	 * callbacks are shared, so only the outermost render adds and removes them.
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Register the render-scoped hooks. Called by Renderer_Controller::render_wc().
	 */
	public static function add_hooks(): void {
		if ( self::$depth++ > 0 ) {
			return;
		}
		add_filter( 'woocommerce_email_blocks_renderer_parsed_blocks', [ __CLASS__, 'normalize' ], self::PRIORITY );
		add_filter( 'the_content', [ __CLASS__, 'start_block_hooks_pass' ], 7 );
		add_filter( 'the_content', [ __CLASS__, 'end_block_hooks_pass' ], 8 );
		add_filter( 'block_parser_class', [ __CLASS__, 'block_parser_class' ], 20 );
	}

	/**
	 * Remove the render-scoped hooks.
	 */
	public static function remove_hooks(): void {
		if ( self::$depth > 0 && --self::$depth > 0 ) {
			return;
		}
		remove_filter( 'woocommerce_email_blocks_renderer_parsed_blocks', [ __CLASS__, 'normalize' ], self::PRIORITY );
		remove_filter( 'the_content', [ __CLASS__, 'start_block_hooks_pass' ], 7 );
		remove_filter( 'the_content', [ __CLASS__, 'end_block_hooks_pass' ], 8 );
		remove_filter( 'block_parser_class', [ __CLASS__, 'block_parser_class' ], 20 );
		self::$in_block_hooks_pass = false;
	}

	/**
	 * Mark the start of core's block-hooks pass (`apply_block_hooks_to_content_from_post_object`,
	 * `the_content` priority 8).
	 *
	 * That pass parses the content and serializes it back. During an email render the
	 * engine's parser would preprocess it on the way, and the widths the engine computes
	 * (stored in block attributes, e.g. on columns) would be serialized into the content
	 * the email is then rendered from. Computed for the wrong context (the content wrapped
	 * in a post-content block), they override the correct ones. Core's plain parser is
	 * used for that pass instead.
	 *
	 * @param mixed $content Content.
	 * @return mixed Unchanged content.
	 */
	public static function start_block_hooks_pass( mixed $content ): mixed {
		self::$in_block_hooks_pass = true;
		return $content;
	}

	/**
	 * Mark the end of core's block-hooks pass. Registered at priority 8 after core's
	 * callback, so it runs once that pass is done and before `do_blocks()` (priority 9).
	 *
	 * @param mixed $content Content.
	 * @return mixed Unchanged content.
	 */
	public static function end_block_hooks_pass( mixed $content ): mixed {
		self::$in_block_hooks_pass = false;
		return $content;
	}

	/**
	 * Use core's block parser during the block-hooks pass.
	 *
	 * @param mixed $parser_class Block parser class.
	 * @return mixed
	 */
	public static function block_parser_class( mixed $parser_class ): mixed {
		return self::$in_block_hooks_pass ? \WP_Block_Parser::class : $parser_class;
	}

	/**
	 * Normalize a list of parsed blocks.
	 *
	 * @param mixed $blocks Parsed blocks.
	 * @return mixed Normalized blocks.
	 */
	public static function normalize( mixed $blocks ): mixed {
		if ( ! is_array( $blocks ) ) {
			return $blocks;
		}
		foreach ( $blocks as $key => $block ) {
			if ( is_array( $block ) ) {
				$blocks[ $key ] = self::unwrap_plain_full_width_group( $block );
			}
		}
		return self::normalize_recursive( $blocks );
	}

	/**
	 * Apply the per-block rules that hold at any depth.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private static function normalize_recursive( array $blocks ): array {
		foreach ( $blocks as $key => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block = self::map_button_width( $block );
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::normalize_recursive( $block['innerBlocks'] );
			}
			$blocks[ $key ] = $block;
		}
		return $blocks;
	}

	/**
	 * Map a button's `style.dimensions.width` onto the `width` attribute.
	 *
	 * Since WordPress 7.1 a button's width is saved as `style.dimensions.width` (e.g. `50%`)
	 * and turned into the `wp-block-button__width-*` classes only at render time. The
	 * engine sizes buttons from the numeric `width` attribute the older format used, so
	 * without this a 7.1 button always renders at its natural width.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	private static function map_button_width( array $block ): array {
		if ( 'core/button' !== ( $block['blockName'] ?? '' ) || ! empty( $block['attrs']['width'] ) ) {
			return $block;
		}
		$width = $block['attrs']['style']['dimensions']['width'] ?? null;
		if ( ! is_string( $width ) || ! preg_match( '/^\s*(\d+(?:\.\d+)?)\s*%\s*$/', $width, $matches ) ) {
			return $block;
		}
		$percent = (float) $matches[1];
		if ( $percent <= 0 || $percent > 100 ) {
			return $block;
		}
		$block['attrs']['width'] = $percent;
		return $block;
	}

	/**
	 * Keep the content of a top-level full-width group in the content column.
	 *
	 * In the editor a full-width group's box spans the canvas, but its content stays in
	 * the content column, whether or not the group sets `0px` side padding. The engine
	 * treats an explicit zero as "has its own padding": it neither insets the group nor
	 * hands the email gutter down to its children, so they are sized to the full email
	 * width and run to its edge (and on phones, to the edge of the screen).
	 *
	 * Dropping the zero side padding lets the engine hand the gutter to the children, as
	 * it does for a full-width group with no padding set. A group that draws nothing of
	 * its own (no background or border) also loses its full alignment, so it renders as a
	 * plain block; groups with a background keep it and bleed via Full_Bleed_Sections.
	 * Groups with real (non-zero) side padding are left alone.
	 *
	 * @param array $block Parsed top-level block.
	 * @return array
	 */
	private static function unwrap_plain_full_width_group( array $block ): array {
		if ( 'core/group' !== ( $block['blockName'] ?? '' ) || 'full' !== ( $block['attrs']['align'] ?? '' ) ) {
			return $block;
		}
		$attrs = $block['attrs'];
		$style = $attrs['style'] ?? [];
		foreach ( [ 'left', 'right' ] as $side ) {
			$value = $style['spacing']['padding'][ $side ] ?? null;
			if ( null !== $value && ! self::is_zero( $value ) ) {
				return $block;
			}
		}
		unset( $block['attrs']['style']['spacing']['padding']['left'], $block['attrs']['style']['spacing']['padding']['right'] );

		$draws_box = ! empty( $attrs['backgroundColor'] ) || ! empty( $attrs['gradient'] ) || ! empty( $attrs['borderColor'] )
			|| ! empty( $style['color']['background'] ) || ! empty( $style['color']['gradient'] )
			|| ! empty( $style['background'] ) || ! empty( $style['border'] );
		if ( ! $draws_box ) {
			unset( $block['attrs']['align'] );
		}
		return $block;
	}

	/**
	 * Whether a CSS length is zero (`0`, `0px`, `0em`…).
	 *
	 * @param mixed $value CSS length.
	 * @return bool
	 */
	private static function is_zero( mixed $value ): bool {
		return is_string( $value ) || is_numeric( $value )
			? (bool) preg_match( '/^\s*0+(?:\.0+)?[a-z%]*\s*$/i', (string) $value )
			: false;
	}
}
