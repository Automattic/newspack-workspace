<?php
/**
 * Co-Authors Plus RSS feed integration.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Adds co-author support to RSS and other feeds.
 */
class Co_Authors_Plus_RSS_Feed {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'the_author', [ __CLASS__, 'coauthors_in_rss' ] );
	}

	/**
	 * Filter the_author to include all co-authors in RSS and other feeds.
	 *
	 * /wp-includes/feed-rss2.php uses the_author(), so we selectively filter
	 * the_author value to include all co-authors when inside a feed.
	 *
	 * @param string $the_author The post author's display name.
	 * @return string The co-authors' display names, or the original author if not in a feed.
	 */
	public static function coauthors_in_rss( $the_author ) {
		if ( ! is_feed() || ! function_exists( 'get_coauthors' ) ) {
			return $the_author;
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $the_author;
		}
		$coauthors = get_coauthors( $post_id );
		if ( empty( $coauthors ) ) {
			return $the_author;
		}
		$names = array_map( fn( $author ) => wp_strip_all_tags( html_entity_decode( $author->display_name ) ), $coauthors );
		return wp_sprintf_l( '%l', $names );
	}
}
Co_Authors_Plus_RSS_Feed::init();
