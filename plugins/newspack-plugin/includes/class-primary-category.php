<?php
/**
 * Primary Category utility.
 *
 * Provides a shared API for retrieving a post's Yoast SEO primary category
 * and filters the core/post-terms block output to show only the primary category.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Primary Category utility class.
 */
final class Primary_Category {

	/**
	 * Option name for the feature toggle.
	 */
	const OPTION_NAME = 'newspack_primary_category_enabled';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'render_block_core/post-terms', [ __CLASS__, 'filter_post_terms_block' ], 10, 3 );
	}

	/**
	 * Check if Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function is_yoast_active(): bool {
		return class_exists( 'WPSEO_Primary_Term' );
	}

	/**
	 * Check if the primary category feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( ! self::is_yoast_active() ) {
			return false;
		}

		// Honor the legacy classic theme mod if it was explicitly disabled.
		if ( ! wp_is_block_theme() ) {
			$theme_mods = get_theme_mods();
			if ( isset( $theme_mods['post_primary_category'] ) && ! $theme_mods['post_primary_category'] ) {
				return false;
			}
		}

		return (bool) get_option( self::OPTION_NAME, 1 );
	}

	/**
	 * Get the primary category for a post.
	 *
	 * @param int|null $post_id Post ID. Defaults to current post.
	 * @return \WP_Term|false The primary category term object, or false.
	 */
	public static function get( ?int $post_id = null ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( null === $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return false;
		}

		$primary_term = new \WPSEO_Primary_Term( 'category', $post_id );
		$category_id  = $primary_term->get_primary_term();

		if ( ! $category_id ) {
			return false;
		}

		$term = get_term( $category_id, 'category' );

		if ( is_wp_error( $term ) || ! $term ) {
			return false;
		}

		return $term;
	}

	/**
	 * Filter the core/post-terms block to show only the primary category.
	 *
	 * @param string    $block_content  The block content.
	 * @param array     $parsed_block   The parsed block data.
	 * @param \WP_Block $block_instance The block instance.
	 * @return string Filtered block content.
	 */
	public static function filter_post_terms_block( string $block_content, array $parsed_block, $block_instance ): string {
		// Only filter on the front end.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $block_content;
		}

		$taxonomy = $parsed_block['attrs']['term'] ?? '';

		if ( 'category' !== $taxonomy ) {
			return $block_content;
		}

		// Get post ID from block context, fall back to global.
		$post_id = null;
		if ( is_object( $block_instance ) && isset( $block_instance->context['postId'] ) ) {
			$post_id = (int) $block_instance->context['postId'];
		}

		$primary_category = self::get( $post_id );

		if ( ! $primary_category ) {
			return $block_content;
		}

		$category_link = get_category_link( $primary_category->term_id );

		if ( ! $category_link ) {
			return $block_content;
		}

		$category_name = esc_html( $primary_category->name );
		$category_html = '<a href="' . esc_url( $category_link ) . '" rel="tag">' . $category_name . '</a>';

		// Preserve the original wrapper tag and all its attributes by slicing the HTML.
		$first_close = strpos( $block_content, '>' );
		$last_open   = strrpos( $block_content, '</' );

		if ( false === $first_close || false === $last_open ) {
			return $block_content;
		}

		$opening_tag = substr( $block_content, 0, $first_close + 1 );
		$closing_tag = substr( $block_content, $last_open );

		// Check for prefix and suffix in block attributes.
		$prefix = $parsed_block['attrs']['prefix'] ?? '';
		$suffix = $parsed_block['attrs']['suffix'] ?? '';

		$inner_html = '';
		if ( $prefix ) {
			$inner_html .= '<span class="wp-block-post-terms__prefix">' . wp_kses_post( $prefix ) . '</span>';
		}
		$inner_html .= $category_html;
		if ( $suffix ) {
			$inner_html .= '<span class="wp-block-post-terms__suffix">' . wp_kses_post( $suffix ) . '</span>';
		}

		return $opening_tag . $inner_html . $closing_tag;
	}
}
Primary_Category::init();
