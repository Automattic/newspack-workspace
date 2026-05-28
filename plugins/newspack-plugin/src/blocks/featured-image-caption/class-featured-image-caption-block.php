<?php
/**
 * Featured Image Caption Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\FeaturedImageCaption;

defined( 'ABSPATH' ) || exit;

/**
 * Featured Image Caption Block class.
 */
final class Featured_Image_Caption_Block {

	/**
	 * Initializer.
	 */
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register the block.
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content Block content.
	 * @param \WP_Block $block Block instance.
	 * @return string Rendered block.
	 */
	public static function render_block( $attributes, $content, $block ) {
		$post_id = ! empty( $block->context['postId'] ) ? $block->context['postId'] : get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$featured_image_id = get_post_thumbnail_id( $post_id );
		if ( ! $featured_image_id ) {
			return '';
		}

		$caption = wp_kses_post( wp_get_attachment_caption( $featured_image_id ) );
		$credit  = '';

		if ( class_exists( '\Newspack\Newspack_Image_Credits' ) ) {
			$credit = \Newspack\Newspack_Image_Credits::get_media_credit_string( $featured_image_id );
		}

		$output = trim( $caption );
		if ( $output && $credit ) {
			$output .= ' ' . $credit;
		} elseif ( $credit ) {
			$output = $credit;
		}

		if ( ! $output ) {
			return '';
		}

		$wrapper_attributes = get_block_wrapper_attributes();
		return sprintf( '<figcaption %1$s>%2$s</figcaption>', $wrapper_attributes, $output );
	}
}
Featured_Image_Caption_Block::init();
