<?php
/**
 * Builds a per-newsletter theme.json array from existing post meta.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Translates Newspack per-newsletter theme post-meta into the theme.json shape
 * the WC renderer consumes. Non-destructive: reads meta, never writes it.
 */
class Theme_Json_Builder {
	/**
	 * Build a theme.json-shaped array for a newsletter.
	 *
	 * @param \WP_Post $post Newsletter post.
	 * @return array
	 */
	public static function build( \WP_Post $post ): array {
		$background = \sanitize_hex_color( (string) \get_post_meta( $post->ID, 'background_color', true ) );
		$text       = \sanitize_hex_color( (string) \get_post_meta( $post->ID, 'text_color', true ) );

		return [
			'version' => 3,
			'styles'  => [
				'color' => [
					'background' => $background ? $background : '#ffffff',
					'text'       => $text ? $text : '#000000',
				],
			],
		];
	}
}
