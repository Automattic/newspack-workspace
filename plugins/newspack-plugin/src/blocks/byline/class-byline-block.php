<?php
/**
 * Byline Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\Byline;

use Newspack\Bylines;

defined( 'ABSPATH' ) || exit;

/**
 * Byline_Block Class
 */
final class Byline_Block {
	/**
	 * Initializes the block.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register the byline block.
	 *
	 * @return void
	 */
	public static function register_block() {
		register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
				'uses_context'    => [ 'postId', 'postType' ],
			]
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array  $attributes The block attributes.
	 * @param string $content    The block content.
	 * @param object $block      The block.
	 *
	 * @return string The block HTML.
	 */
	public static function render_block( array $attributes, string $content, $block ) {
		$post_id = $block->context['postId'] ?? get_the_ID();

		if ( empty( $post_id ) ) {
			return '';
		}

		// 1. Check for custom byline (use post meta check).
		if ( class_exists( 'Newspack\Bylines' ) && Bylines::is_enabled() ) {
			$byline_active = get_post_meta( $post_id, Bylines::META_KEY_ACTIVE, true );
			if ( $byline_active ) {
				// Use Bylines::get_post_byline_html() as the authoritative source.
				// Pass false for include_avatars and byline_wrapper since we handle output ourselves.
				$custom_byline = Bylines::get_post_byline_html( false, false, $post_id );
				if ( ! empty( $custom_byline ) ) {
					return self::render_custom_byline( $attributes, $custom_byline );
				}
			}
		}

		// 2. Check for CoAuthors Plus.
		if ( function_exists( 'get_coauthors' ) ) {
			$coauthors = get_coauthors( $post_id );
			if ( ! empty( $coauthors ) ) {
				return self::render_coauthors( $attributes, $coauthors );
			}
		}

		// 3. Fallback to default author.
		return self::render_default_author( $attributes, $post_id );
	}

	/**
	 * Render custom byline.
	 *
	 * @param array  $attributes    The block attributes.
	 * @param string $custom_byline The custom byline HTML from Bylines class.
	 *
	 * @return string The block HTML.
	 */
	private static function render_custom_byline( array $attributes, string $custom_byline ) {
		// Custom bylines already include their prefix text, so we ignore the prefix attribute.
		$wrapper_attributes = get_block_wrapper_attributes();

		return sprintf(
			'<div %1$s><span class="byline">%2$s</span></div>',
			$wrapper_attributes,
			wp_kses_post( $custom_byline )
		);
	}

	/**
	 * Render CoAuthors Plus authors.
	 *
	 * @param array $attributes The block attributes.
	 * @param array $coauthors  The coauthors array.
	 *
	 * @return string The block HTML.
	 */
	private static function render_coauthors( array $attributes, array $coauthors ) {
		$prefix             = self::get_translated_prefix( $attributes['prefix'] ?? '' );
		$link_to_archive    = $attributes['linkToAuthorArchive'] ?? true;
		$wrapper_attributes = get_block_wrapper_attributes();

		$author_links = [];
		foreach ( $coauthors as $coauthor ) {
			$display_name = isset( $coauthor->display_name ) ? $coauthor->display_name : '';

			if ( empty( $display_name ) ) {
				continue;
			}

			if ( $link_to_archive ) {
				$author_links[] = self::get_coauthor_link( $coauthor, $display_name );
			} else {
				$author_links[] = sprintf(
					'<span class="author vcard"><span class="fn n">%1$s</span></span>',
					esc_html( $display_name )
				);
			}
		}

		if ( empty( $author_links ) ) {
			return '';
		}

		$byline_content = self::format_author_list( $author_links );

		return sprintf(
			'<div %1$s><span class="byline">%2$s%3$s</span></div>',
			$wrapper_attributes,
			! empty( $prefix ) ? esc_html( $prefix ) . ' ' : '',
			$byline_content
		);
	}

	/**
	 * Get the author link HTML for a CoAuthors Plus author.
	 *
	 * Uses get_author_posts_url() which CAP hooks via the 'author_link' filter
	 * (CoAuthors_Guest_Authors::filter_author_link) to construct proper URLs for
	 * both WordPress users and CAP guest authors.
	 *
	 * Also applies the 'coauthors_posts_link' filter for compatibility with CAP's
	 * own coauthors_posts_links_single() function.
	 *
	 * @param object $coauthor     The coauthor object.
	 * @param string $display_name The display name to show.
	 *
	 * @return string Author link HTML.
	 */
	private static function get_coauthor_link( $coauthor, string $display_name ) {
		$args = [
			'before_html' => '',
			'href'        => get_author_posts_url( $coauthor->ID, $coauthor->user_nicename ),
			'rel'         => 'author',
			'class'       => 'url fn n',
			'text'        => $display_name,
			'after_html'  => '',
		];

		/**
		 * Filter the author link arguments.
		 *
		 * This filter is provided by CoAuthors Plus and allows modification of
		 * author link attributes. We apply it for full CAP compatibility.
		 *
		 * @param array  $args     Link arguments: href, rel, class, text, before_html, after_html.
		 * @param object $coauthor The coauthor object.
		 */
		$args = apply_filters( 'coauthors_posts_link', $args, $coauthor );

		return sprintf(
			'%1$s<span class="author vcard"><a class="%2$s" href="%3$s" rel="%4$s">%5$s</a></span>%6$s',
			wp_kses_post( $args['before_html'] ),
			esc_attr( $args['class'] ),
			esc_url( $args['href'] ),
			esc_attr( $args['rel'] ),
			esc_html( $args['text'] ),
			wp_kses_post( $args['after_html'] )
		);
	}

	/**
	 * Render default WordPress author.
	 *
	 * @param array $attributes The block attributes.
	 * @param int   $post_id    The post ID.
	 *
	 * @return string The block HTML.
	 */
	private static function render_default_author( array $attributes, int $post_id ) {
		$prefix             = self::get_translated_prefix( $attributes['prefix'] ?? '' );
		$link_to_archive    = $attributes['linkToAuthorArchive'] ?? true;
		$wrapper_attributes = get_block_wrapper_attributes();

		$author_id = get_post_field( 'post_author', $post_id );
		$author    = get_userdata( $author_id );

		if ( ! $author ) {
			return '';
		}

		$display_name = $author->display_name;

		if ( $link_to_archive ) {
			$author_url  = get_author_posts_url( $author_id );
			$author_html = sprintf(
				'<span class="author vcard"><a class="url fn n" href="%1$s">%2$s</a></span>',
				esc_url( $author_url ),
				esc_html( $display_name )
			);
		} else {
			$author_html = sprintf(
				'<span class="author vcard"><span class="fn n">%1$s</span></span>',
				esc_html( $display_name )
			);
		}

		return sprintf(
			'<div %1$s><span class="byline">%2$s%3$s</span></div>',
			$wrapper_attributes,
			! empty( $prefix ) ? esc_html( $prefix ) . ' ' : '',
			$author_html
		);
	}

	/**
	 * Get the translated prefix.
	 *
	 * The block.json default "By" is not translatable. This method checks if
	 * the prefix matches the English default and returns the translated version.
	 * If the user has set a custom prefix, it's returned as-is.
	 *
	 * @param string $prefix The prefix attribute value.
	 *
	 * @return string The translated or custom prefix.
	 */
	private static function get_translated_prefix( string $prefix ) {
		// If prefix is empty or matches the English default, use translated version.
		if ( empty( $prefix ) || 'By' === $prefix ) {
			return __( 'By', 'newspack-plugin' );
		}
		return $prefix;
	}

	/**
	 * Format a list of author links with proper separators.
	 *
	 * @param array $author_links Array of author HTML strings.
	 *
	 * @return string Formatted author list.
	 */
	private static function format_author_list( array $author_links ) {
		if ( empty( $author_links ) ) {
			return '';
		}

		if ( 1 === count( $author_links ) ) {
			return $author_links[0];
		}

		$last = array_pop( $author_links );
		return implode( ', ', $author_links ) . _x( ' and ', 'post author separator', 'newspack-plugin' ) . $last;
	}
}

Byline_Block::init();
