<?php
/**
 * Newspack Ads Complianz Integration
 *
 * @package Newspack
 */

namespace Newspack_Ads\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Ads Complianz Integration Class.
 */
final class Complianz {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		add_filter( 'newspack_ads_ad_targeting', [ __CLASS__, 'gam_ad_targeting' ] );
	}

	/**
	 * Whether to allow reader data to be used for ad targeting.
	 */
	private static function should_allow_reader_data() {
		// Allow reader data if consent strategy is not detected.
		if ( ! function_exists( 'cmplz_has_consent' ) ) {
			return true;
		}
		return cmplz_has_consent( 'marketing' );
	}

	/**
	 * Filter GAM ad targeting according to Complianz settings.
	 *
	 * @param array $targeting Ad targeting.
	 *
	 * @return array Filtered ad targeting.
	 */
	public static function gam_ad_targeting( $targeting ) {
		if ( ! self::should_allow_reader_data() ) {
			// Unset reader data (reader_*) from targeting.
			foreach ( $targeting as $key => $value ) {
				if ( 0 === strpos( $key, 'reader_' ) ) {
					unset( $targeting[ $key ] );
				}
			}
		}
		return $targeting;
	}
}
Complianz::init();
