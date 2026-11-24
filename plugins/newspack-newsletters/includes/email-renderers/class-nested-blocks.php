<?php
/**
 * Render block markup nested inside another block under the WC email engine.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

use Automattic\WooCommerce\EmailEditor\Email_Editor_Container;
use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Preprocessors\Blocks_Width_Preprocessor;
use Automattic\WooCommerce\EmailEditor\Engine\Theme_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Renders markup that a block override pulls in from elsewhere (posts inserted by the
 * Posts Inserter, an ad's content) so it fits the block that hosts it.
 *
 * During a WC render the package swaps in its own block parser, which runs the whole
 * email preprocessing pipeline on every `parse_blocks()` call. Nested markup is
 * therefore treated as top-level email content: each block is given the email's root
 * padding (another 24px gutter on each side) and a width computed from the full email
 * content size. Inside a host block that already sits in the gutter, that indents the
 * content twice and either shrinks images by 48px or, when the width isn't reduced,
 * overflows the email.
 *
 * This helper drops the distributed root and container padding again and recomputes
 * widths against the host block's own width.
 */
class Nested_Blocks {

	/**
	 * `email_attrs` keys the package's spacing preprocessor uses to distribute the
	 * email's root and container padding to top-level blocks.
	 */
	/**
	 * The email theme's variable values for the current render, or null before first use.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $variables_map = null;

	/**
	 * Forget the cached theme variables. Called at the start of each render, since the
	 * email theme is per newsletter.
	 */
	public static function reset_cache(): void {
		self::$variables_map = null;
	}

	const PADDING_ATTRS = [
		'root-padding-left',
		'root-padding-right',
		'container-padding-left',
		'container-padding-right',
	];

	/**
	 * Render nested block markup to email HTML.
	 *
	 * @param string      $markup Block markup.
	 * @param string|null $width  Width available inside the host block (e.g. `612px`),
	 *                            or null when unknown.
	 * @return string Rendered email HTML.
	 */
	public static function render( string $markup, ?string $width = null ): string {
		$blocks = self::strip_root_padding( parse_blocks( $markup ) );
		if ( null !== $width && '' !== $width && class_exists( Blocks_Width_Preprocessor::class ) ) {
			$blocks = self::clear_computed_column_widths( $blocks, self::parse_as_saved( $markup ) );
			$blocks = ( new Blocks_Width_Preprocessor() )->preprocess(
				$blocks,
				[ 'contentSize' => $width ],
				[ '__variables_map' => self::variables_map() ]
			);
		}
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}
		return $html;
	}

	/**
	 * Parse markup with core's parser, as saved, without the engine's preprocessing.
	 *
	 * Drops the empty top-level blocks the engine's cleanup step drops, so the result
	 * lines up block for block with what `parse_blocks()` returns during a render.
	 *
	 * @param string $markup Block markup.
	 * @return array Parsed blocks.
	 */
	private static function parse_as_saved( string $markup ): array {
		$blocks = ( new \WP_Block_Parser() )->parse( $markup );
		return array_values(
			array_filter(
				$blocks,
				static fn( array $block ): bool => null !== $block['blockName'] || '' !== trim( $block['innerHTML'] ?? '' )
			)
		);
	}

	/**
	 * Remove the column widths the engine computed on its first pass.
	 *
	 * The engine's parser writes a px width into every column saved without one, sized
	 * to the full email. The width preprocessor then treats it as a width the author set,
	 * so it has to go before widths are recomputed for the host block. Columns whose
	 * saved markup has a width keep it.
	 *
	 * @param array $blocks Blocks parsed during the render.
	 * @param array $saved  The same blocks as saved (see parse_as_saved()).
	 * @return array
	 */
	private static function clear_computed_column_widths( array $blocks, array $saved ): array {
		foreach ( $blocks as $key => $block ) {
			$original = $saved[ $key ] ?? null;
			if ( ! is_array( $block ) || ! is_array( $original ) || ( $block['blockName'] ?? null ) !== ( $original['blockName'] ?? null ) ) {
				// The trees no longer line up; leave the rest as parsed.
				return $blocks;
			}
			if ( 'core/column' === $block['blockName'] && empty( $original['attrs']['width'] ) ) {
				unset( $blocks[ $key ]['attrs']['width'] );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $key ]['innerBlocks'] = self::clear_computed_column_widths( $block['innerBlocks'], $original['innerBlocks'] ?? [] );
			}
		}
		return $blocks;
	}

	/**
	 * The email theme's CSS variable values, so preset spacing (`var:preset|spacing|*`)
	 * resolves to px when widths are recomputed instead of counting as zero.
	 *
	 * @return array<string, string>
	 */
	private static function variables_map(): array {
		if ( null !== self::$variables_map ) {
			return self::$variables_map;
		}
		self::$variables_map = [];
		if ( class_exists( Email_Editor_Container::class ) ) {
			try {
				self::$variables_map = Email_Editor_Container::container()->get( Theme_Controller::class )->get_variables_values_map();
			} catch ( \Throwable $e ) {
				self::$variables_map = [];
			}
		}
		return self::$variables_map;
	}

	/**
	 * Remove the root and container padding the package distributed to parsed blocks.
	 *
	 * Outside a WC render `parse_blocks()` adds no `email_attrs`, so this is a no-op there.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array Blocks without distributed root/container padding.
	 */
	public static function strip_root_padding( array $blocks ): array {
		foreach ( $blocks as $key => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			foreach ( self::PADDING_ATTRS as $attr ) {
				unset( $blocks[ $key ]['email_attrs'][ $attr ] );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $key ]['innerBlocks'] = self::strip_root_padding( $block['innerBlocks'] );
			}
		}
		return $blocks;
	}
}
