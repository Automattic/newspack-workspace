<?php
/**
 * Author Profile Social Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Author_Profile_Social;

use Newspack\Social_Icons;
use Newspack_Blocks;
use WP_Block;
use WP_Theme_JSON_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Author_Profile_Social_Block Class
 */
final class Author_Profile_Social_Block {
	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
		add_filter( 'wp_theme_json_data_blocks', [ __CLASS__, 'set_default_block_gap' ] );
	}

	/**
	 * Register the Author Profile Social Links block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		// Enable inserter only in block themes where nested layout is supported.
		// Use block_type_metadata filter rather than passing supports in $args to avoid
		// shallow-overwriting all supports from block.json (array_merge is not deep).
		$is_nested_mode = wp_is_block_theme();
		$set_inserter   = static function ( array $metadata ) use ( $is_nested_mode ): array {
			if ( ( $metadata['name'] ?? '' ) === 'newspack/author-profile-social' ) {
				$metadata['supports']['inserter'] = $is_nested_mode;
			}
			return $metadata;
		};

		add_filter( 'block_type_metadata', $set_inserter );

		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
				'uses_context'    => [ 'newspack-blocks/author' ],
			]
		);

		remove_filter( 'block_type_metadata', $set_inserter );
	}

	/**
	 * Set a default blockGap for this block via the blocks theme.json layer.
	 * This matches what newspack-block-theme does for core/social-links
	 * but works with any block theme.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme JSON data.
	 * @return WP_Theme_JSON_Data
	 */
	public static function set_default_block_gap( WP_Theme_JSON_Data $theme_json ): WP_Theme_JSON_Data {
		$theme_json->update_with(
			[
				'version' => 3,
				'styles'  => [
					'blocks' => [
						'newspack/author-profile-social' => [
							'spacing' => [
								'blockGap' => [
									'left' => 'var:preset|spacing|20',
									'top'  => 'var:preset|spacing|20',
								],
							],
						],
					],
				],
			]
		);
		return $theme_json;
	}

	/**
	 * Block render callback.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Block default content.
	 * @param WP_Block $block      Block instance.
	 *
	 * @return string The rendered block markup.
	 */
	public static function render_block( array $attributes, string $content, WP_Block $block ): string {
		$author = $block->context['newspack-blocks/author'] ?? null;
		if ( ! $author ) {
			return '';
		}

		$icon_size = (int) ( round( ( $attributes['iconSize'] ?? 24 ) / 2 ) * 2 );

		// If we have inner blocks (InnerBlocks mode), render them.
		if ( ! empty( $block->inner_blocks ) ) {
			return self::render_social_with_inner_blocks( $attributes, $block, $author, $icon_size );
		}

		// Legacy flat render: build social links from author data.
		return self::render_social_flat( $attributes, $block, $author, $icon_size );
	}

	/**
	 * Render social links using InnerBlocks (author-social-link children).
	 *
	 * @param array    $attributes Block attributes.
	 * @param WP_Block $block      Block instance.
	 * @param array    $author     Author data.
	 * @param int      $icon_size  Icon size in pixels.
	 * @return string Rendered HTML.
	 */
	private static function render_social_with_inner_blocks( array $attributes, WP_Block $block, array $author, int $icon_size ): string {
		$inner_content = '';

		foreach ( $block->inner_blocks as $inner_block ) {
			$inner_block_instance = new WP_Block(
				$inner_block->parsed_block,
				array_merge(
					$block->context,
					[
						'newspack-blocks/author'   => $author,
						'newspack-blocks/iconSize' => $icon_size,
					]
				)
			);

			$rendered = $inner_block_instance->render();
			if ( $rendered ) {
				$inner_content .= $rendered;
			}
		}

		if ( empty( $inner_content ) ) {
			return '';
		}

		$wrapper_attributes = self::get_block_wrapper_attributes( $block, $attributes, $icon_size );

		return sprintf(
			'<ul %s>%s</ul>',
			$wrapper_attributes,
			$inner_content
		);
	}

	/**
	 * Render social links in flat mode (legacy, no inner blocks).
	 *
	 * @param array    $attributes Block attributes.
	 * @param WP_Block $block      Block instance.
	 * @param array    $author     Author data.
	 * @param int      $icon_size  Icon size in pixels.
	 * @return string Rendered HTML.
	 */
	private static function render_social_flat( array $attributes, WP_Block $block, array $author, int $icon_size ): string {
		$show_email = $attributes['showEmail'] ?? false;

		// Build social links array.
		$social_links = [];

		if ( ! empty( $author['social'] ) && is_array( $author['social'] ) ) {
			foreach ( $author['social'] as $service => $data ) {
				if ( ! empty( $data['url'] ) ) {
					$social_links[ $service ] = $data;
				}
			}
		}

		// Add email if enabled.
		if ( $show_email && ! empty( $author['email'] ) ) {
			$social_links['email'] = [
				'url' => 'mailto:' . $author['email'],
				'svg' => null,
			];
		}

		if ( empty( $social_links ) ) {
			return '';
		}

		$wrapper_attributes = self::get_block_wrapper_attributes( $block, $attributes, $icon_size );

		$output = '';

		foreach ( $social_links as $service => $social_data ) {
			$service_label = ucfirst( $service );
			$output       .= '<li data-service="' . esc_attr( $service ) . '">';
			$output       .= sprintf( '<a href="%s" aria-label="%s">', esc_url( $social_data['url'] ), esc_attr( $service_label ) );

			$svg = ! empty( $social_data['svg'] ) ? $social_data['svg'] : Social_Icons::get_svg( $service );

			if ( $svg ) {
				$output .= sprintf(
					'<span style="width: %dpx; height: %dpx;" aria-hidden="true">%s</span>',
					absint( $icon_size ),
					absint( $icon_size ),
					Newspack_Blocks::sanitize_svg( $svg )
				);
			} else {
				$output .= sprintf( '<span class="service-name">%s</span>', esc_html( $service ) );
			}

			$output .= '</a></li>';
		}

		return sprintf( '<ul %s>%s</ul>', $wrapper_attributes, $output );
	}

	/**
	 * Get wrapper attributes (class, style, etc.) for the block.
	 *
	 * @param WP_Block $block      Block instance.
	 * @param array    $attributes Block attributes.
	 * @param int      $icon_size  Icon size in pixels.
	 * @return string HTML attributes for the wrapper element.
	 */
	private static function get_block_wrapper_attributes( WP_Block $block, array $attributes, int $icon_size ): string {
		// Defensive: each inner WP_Block::render() snapshots/restores
		// $block_to_render around its own callback, so by this point it
		// should already point at the parent's parsed_block. Re-set
		// explicitly to guard against a third-party filter or future Core
		// change breaking that chain — otherwise the wrapper would be built
		// from the wrong block's supports and lose this block's className,
		// spacing, default class, etc.
		$previous                            = \WP_Block_Supports::$block_to_render ?? null;
		\WP_Block_Supports::$block_to_render = $block->parsed_block;

		$wrapper_attributes = get_block_wrapper_attributes(
			[
				'class' => 'author-profile-social__list',
				'style' => self::get_wrapper_style( $attributes, $icon_size ),
			]
		);

		\WP_Block_Supports::$block_to_render = $previous;

		return $wrapper_attributes;
	}

	/**
	 * Resolve a color value from the block's icon color attributes.
	 * When both a preset slug and a resolved value are present, emits the
	 * CSS variable with the value as a native fallback — so theme switches
	 * that redefine the slug pick up the new palette value, and theme
	 * switches that drop the slug fall back to the saved hex instead of
	 * rendering uncoloured.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $preset_key Preset slug attribute key (e.g. "iconColor").
	 * @param string $value_key  Resolved CSS value attribute key (e.g. "iconColorValue").
	 * @return string|null CSS color value or null.
	 */
	private static function resolve_color( array $attributes, string $preset_key, string $value_key ): ?string {
		$slug  = ! empty( $attributes[ $preset_key ] ) && is_string( $attributes[ $preset_key ] ) ? $attributes[ $preset_key ] : null;
		$value = ! empty( $attributes[ $value_key ] ) && is_string( $attributes[ $value_key ] ) ? $attributes[ $value_key ] : null;

		if ( $slug && $value ) {
			return sprintf( 'var(--wp--preset--color--%s, %s)', $slug, $value );
		}
		if ( $slug ) {
			return sprintf( 'var(--wp--preset--color--%s)', $slug );
		}
		if ( $value ) {
			return $value;
		}
		return null;
	}

	/**
	 * Build wrapper inline style with CSS variables for icon sizing and color.
	 * Margin is handled natively by get_block_wrapper_attributes().
	 * Gap is handled by WP layout support (outputs scoped <style> tag per block).
	 *
	 * @param array $attributes Block attributes.
	 * @param int   $icon_size  Icon size in pixels.
	 * @return string Inline style string for the block wrapper.
	 */
	private static function get_wrapper_style( array $attributes, int $icon_size ): string {
		$parts    = [];
		$is_brand = ! empty( $attributes['className'] ) && str_contains( $attributes['className'], 'is-style-brand' );

		if ( ! $is_brand ) {
			$icon_color      = self::resolve_color( $attributes, 'iconColor', 'iconColorValue' );
			$icon_background = self::resolve_color( $attributes, 'iconBackgroundColor', 'iconBackgroundColorValue' );

			if ( null !== $icon_color ) {
				$parts[] = sprintf( '--icon-color: %s;', $icon_color );
			}
			if ( null !== $icon_background ) {
				$parts[] = sprintf( '--icon-background: %s;', $icon_background );
			}
		}

		$parts[] = sprintf( '--icon-size: %dpx;', absint( $icon_size ) );

		return implode( ' ', $parts );
	}
}

Author_Profile_Social_Block::init();
