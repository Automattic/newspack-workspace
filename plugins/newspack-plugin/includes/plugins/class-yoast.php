<?php
/**
 * Yoast integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Yoast {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'wpseo_image_image_weight_limit', [ __CLASS__, 'ignore_yoast_weight_limit' ] );
		add_filter( 'wpseo_additional_contactmethods', [ __CLASS__, 'add_bluesky_contact_method' ] );
	}

	/**
	 * We don't want Yoast to exclude large images from og:image tags for 2 reasons:
	 * 1. Yoast cannot calculate the image size for images served via Jetpack CDN, so any calculations will be incorrect.
	 * 2. It increases support burden since Yoast doesn't provide the user any explanation for why the image was excluded.
	 *
	 * @param int $limit Max image size in bytes.
	 * @return int Modified $limit.
	 */
	public static function ignore_yoast_weight_limit( $limit ) {
		return PHP_INT_MAX;
	}

	/**
	 * Add Bluesky as an additional Yoast SEO contact method while Yoast
	 * does not include it natively.
	 *
	 * @param array $contact_methods Registered contact methods.
	 * @return array Modified contact methods.
	 */
	public static function add_bluesky_contact_method( $contact_methods ) {
		foreach ( $contact_methods as $contact_method ) {
			if ( 'bluesky' === $contact_method->get_key() ) {
				return $contact_methods;
			}
		}
		$contact_methods[] = new Yoast_Bluesky_Contact_Method();

		return $contact_methods;
	}
}
Yoast::init();
