<?php
/**
 * Newspack Content Distribution Canonical URL Handler
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;

/**
 * Class to filter the canonical URLs for distributed content.
 */
class Canonical_Url {

	const OPTION_NAME = 'newspack_network_canonical_url';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'get_canonical_url', array( __CLASS__, 'filter_canonical_url' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'wpseo_canonical_url' ), 10 );
	}

	/**
	 * Filters the canonical URL for distributed content.
	 *
	 * @param  string $canonical_url Canonical URL.
	 * @param  object $post          Post object.
	 *
	 * @return string
	 */
	public static function filter_canonical_url( $canonical_url, $post ) {
		if ( ! Content_Distribution_Class::is_post_incoming( $post ) ) {
			return $canonical_url;
		}

		$incoming_post = new Incoming_Post( $post->ID );

		if ( ! $incoming_post->is_linked() ) {
			return $canonical_url;
		}

		$canonical_url = $incoming_post->get_original_post_url();

		$base_url = get_option( self::OPTION_NAME, '' );
		if ( $base_url ) {
			$canonical_url = str_replace( $incoming_post->get_original_site_url(), $base_url, $canonical_url );
		}

		return $canonical_url;
	}

	/**
	 * Handles the canonical URL change for distributed content when Yoast SEO is in use.
	 *
	 * @param string $canonical_url The Yoast WPSEO deduced canonical URL.
	 *
	 * @return string $canonical_url The updated distributor friendly URL.
	 */
	public static function wpseo_canonical_url( $canonical_url ) {

		// Return as is if not on a singular page - taken from rel_canonical().
		if ( ! is_singular() ) {
			return $canonical_url;
		}

		$id = get_queried_object_id();

		// Return as is if we do not have a object id for context - taken from rel_canonical().
		if ( 0 === $id ) {
			return $canonical_url;
		}

		$post = get_post( $id );

		// Return as is if we don't have a valid post object - taken from wp_get_canonical_url().
		if ( ! $post ) {
			return $canonical_url;
		}

		// Return as is if current post is not published - taken from wp_get_canonical_url().
		if ( 'publish' !== $post->post_status ) {
			return $canonical_url;
		}

		return self::filter_canonical_url( $canonical_url, $post );
	}
}
