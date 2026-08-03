<?php
/**
 * Complianz integration class.
 * https://complianz.io/
 *
 * Provides more control for publishers over Complianz behavior with regards to blocking
 * Newspack-software-added trackers (GAM, Meta, etc.) before consent is given.
 * This is primarily intended for US-based publishers that want to block trackers until after
 * consent is given but do not set up Complianz specifically for GDPR. It allows them to have
 * a US-focused setup AND ability to block things until after consent is given.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Wizards\Newspack\Privacy_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Complianz {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'cmplz_cookie_blocker_output', [ __CLASS__, 'extra_third_party_script_blocking' ] );
		add_filter( 'cmplz_option_enable_cookie_blocker', [ __CLASS__, 'block_before_consent' ] );
		add_filter( 'cmplz_consenttype', [ __CLASS__, 'force_optin_consenttype' ], 10, 2 );
		add_filter( 'newspack_pixel_script_markup', [ __CLASS__, 'pixel_handling_for_complianz' ] );
	}

	/**
	 * Force Complianz cookie blocker on if the setting is enabled.
	 *
	 * @param mixed $value Current option value.
	 * @return mixed 'yes' if force is enabled, original value otherwise.
	 */
	public static function block_before_consent( $value ) {
		if ( self::should_block_trackers_before_consent() ) {
			return 'yes';
		}
		return $value;
	}

	/**
	 * Force optin consent type when force cookie blocker is enabled.
	 *
	 * @param string $consenttype Current consent type.
	 * @param string $region      Region being evaluated.
	 * @return string
	 */
	public static function force_optin_consenttype( $consenttype, $region ) {
		if ( self::should_block_trackers_before_consent() ) {
			return 'optin';
		}
		return $consenttype;
	}

	/**
	 * In Cookie Blocker mode, make sure some third-party scripts are blocked too.
	 *
	 * @param string $output HTML output after Complianz has done an initial pass for cookie/script blocking.
	 * @return string Modified $output.
	 */
	public static function extra_third_party_script_blocking( $output ) {
		// Format is 'domain' => 'category'.
		// Category is one of 'statistics', 'marketing', or 'functional'.
		// 'functional' doesn't make sense here though because those shouldn't be blocked.
		$trackers = [
			'googletagmanager.com' => 'statistics',
			'parsely.com'          => 'statistics',
		];
		$ads = [
			'doubleclick.net' => 'marketing',
		];

		if ( ! self::should_block_trackers_before_consent() ) {
			return $output;
		}

		$scripts_to_block = $trackers;
		if ( self::should_block_ads_before_consent() ) {
			$scripts_to_block = array_merge( $scripts_to_block, $ads );
		}

		// The regex matches <script src=""> tags.
		$script_pattern = '/<script[^>]*?\s+src\s*=\s*([\'"])([^\'"]*?)\1[^>]*?>/is';
		if ( preg_match_all( $script_pattern, $output, $matches, PREG_PATTERN_ORDER ) ) {
			foreach ( $matches[0] as $index => $full_markup ) {

				// Skip any scripts that have already been set up to be deferred until consent.
				if ( false !== stripos( $full_markup, 'data-cmplz-src' ) ) {
					continue;
				}

				$src = $matches[2][ $index ];
				foreach ( $scripts_to_block as $domain => $category ) {
					if ( false === stripos( $src, $domain ) ) {
						continue;
					}

					$new_full_markup = preg_replace( '/\s+src\s*=/i', ' type="text/plain" data-category="' . $category . '" data-cmplz-src=', $full_markup );
					$output = str_replace( $full_markup, $new_full_markup, $output );
					break;
				}
			}
		}

		return $output;
	}

	/**
	 * In Cookie Blocker mode, also block pixels until consent is given.
	 *
	 * @param string $markup Pixel markup.
	 * @return string Modified $markup.
	 */
	public static function pixel_handling_for_complianz( $markup ) {
		if ( self::is_complianz_with_cookie_blocker_active() && self::should_block_trackers_before_consent() ) {
			$markup = str_ireplace( '<script', '<script type="text/plain" data-category="marketing"', $markup );
		}
		return $markup;
	}

	/**
	 * Determines whether the Complianz plugin is active.
	 *
	 * @return bool True if active. False if not.
	 */
	public static function is_complianz_active() {
		return function_exists( 'cmplz_can_run_cookie_blocker' );
	}

	/**
	 * Determine whether Complianz is active and can run cookie blocker.
	 *
	 * @return bool Whether Cookie Blocker is running.
	 */
	public static function is_complianz_with_cookie_blocker_active() {
		return function_exists( 'cmplz_can_run_cookie_blocker' ) && cmplz_can_run_cookie_blocker();
	}

	/**
	 * Determine whether to block trackers before consent is given.
	 *
	 * @return bool True if it should block. False otherwise.
	 */
	public static function should_block_trackers_before_consent() {
		$privacy_settings = Privacy_Section::get_settings();
		return (bool) $privacy_settings['block_before_consent'];
	}

	/**
	 * Determine whether to block ads before consent is given.
	 * Note: Blocking ads only makes sense if also blocking trackers.
	 *
	 * @return bool True if it should block. False otherwise.
	 */
	public static function should_block_ads_before_consent() {
		$privacy_settings = Privacy_Section::get_settings();
		return (bool) $privacy_settings['block_before_consent'] && (bool) $privacy_settings['block_ads_before_consent'];
	}
}
Complianz::init();
