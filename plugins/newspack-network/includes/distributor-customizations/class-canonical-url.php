<?php
/**
 * Newspack Node Canonical URL handler.
 *
 * @package Newspack
 */

namespace Newspack_Network\Distributor_Customizations;

use Newspack\Data_Events;

/**
 * Class to filter the Distributor Canonical URLs based on information received from the Hub.
 */
class Canonical_Url {

	const OPTION_NAME = 'newspack_network_canonical_url';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Registers the filters with priority 20, after Distributor.
		add_filter( 'get_canonical_url', array( __CLASS__, 'filter_canonical_url' ), 20, 2 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'wpseo_canonical_url' ), 20 );
	}

	/**
	 * Make sure canonical url header is outputted
	 *
	 * @param  string $canonical_url Canonical URL.
	 * @param  object $post Post object.
	 * @return string
	 */
	public static function filter_canonical_url( $canonical_url, $post ) {
		$base_url = get_option( self::OPTION_NAME, '' );
		if ( ! $base_url ) {
			return $canonical_url;
		}

		$original_site_url = get_post_meta( $post->ID, 'dt_original_site_url', true );
		$original_post_url = get_post_meta( $post->ID, 'dt_original_post_url', true );
		$unlinked          = (bool) get_post_meta( $post->ID, 'dt_unlinked', true );
		$original_deleted  = (bool) get_post_meta( $post->ID, 'dt_original_post_deleted', true );

		if ( empty( $original_post_url ) || $unlinked || $original_deleted ) {
			return $canonical_url;
		}

		$original_post_url = str_replace( $original_site_url, $base_url, $original_post_url );

		return $original_post_url;
	}

	/**
	 * Handles the canonical URL change for distributed content when Yoast SEO is in use
	 *
	 * @param string $canonical_url The Yoast WPSEO deduced canonical URL.
	 * @return string $canonical_url The updated distributor friendly URL
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
