<?php
/**
 * Author Social Link Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Author_Social_Link;

use Newspack\Social_Icons;
use Newspack_Blocks;
use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Author_Social_Link_Block Class
 */
final class Author_Social_Link_Block {
	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register the Author Social Link block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
				'uses_context'    => [ 'newspack-blocks/author', 'newspack-blocks/iconSize' ],
			]
		);
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
		$author  = $block->context['newspack-blocks/author'] ?? null;
		$service = $attributes['service'] ?? '';

		if ( ! $author || ! $service ) {
			return '';
		}

		$url = self::get_social_service_url( $author, $service );
		if ( ! $url ) {
			return '';
		}

		$icon_size     = (int) ( round( ( $block->context['newspack-blocks/iconSize'] ?? 24 ) / 2 ) * 2 );
		$svg           = self::get_social_service_svg( $author, $service );
		$service_label = ucfirst( $service );

		$output  = '<li class="wp-block-newspack-author-social-link" data-service="' . esc_attr( $service ) . '">';
		$output .= sprintf( '<a href="%s" aria-label="%s">', esc_url( $url ), esc_attr( $service_label ) );

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

		return $output;
	}

	/**
	 * Get the URL for a social service from author data.
	 *
	 * @param array  $author  Author data array.
	 * @param string $service Service key.
	 * @return string|null URL or null.
	 */
	private static function get_social_service_url( array $author, string $service ): ?string {
		if ( 'email' === $service ) {
			$email = $author['email'] ?? null;
			if ( ! $email ) {
				return null;
			}
			if ( is_array( $email ) ) {
				return $email['url'] ?? null;
			}
			return 'mailto:' . $email;
		}

		if ( 'phone' === $service ) {
			$phone = $author['newspack_phone_number'] ?? null;
			if ( ! $phone ) {
				return null;
			}
			if ( is_array( $phone ) ) {
				return $phone['url'] ?? null;
			}
			return 'tel:' . $phone;
		}

		// Social services.
		return $author['social'][ $service ]['url'] ?? null;
	}

	/**
	 * Get the SVG icon for a social service from author data, with fallback to built-in icons.
	 *
	 * @param array  $author  Author data array.
	 * @param string $service Service key.
	 * @return string|null SVG markup or null.
	 */
	private static function get_social_service_svg( array $author, string $service ): ?string {
		// Check if REST API provided an SVG.
		if ( 'email' === $service ) {
			$email = $author['email'] ?? null;
			if ( is_array( $email ) && ! empty( $email['svg'] ) ) {
				return $email['svg'];
			}
		} elseif ( 'phone' === $service ) {
			$phone = $author['newspack_phone_number'] ?? null;
			if ( is_array( $phone ) && ! empty( $phone['svg'] ) ) {
				return $phone['svg'];
			}
		} else {
			$svg = $author['social'][ $service ]['svg'] ?? null;
			if ( $svg ) {
				return $svg;
			}
		}

		// Fall back to built-in SVG map from parent block.
		return Social_Icons::get_svg( $service );
	}
}

Author_Social_Link_Block::init();
