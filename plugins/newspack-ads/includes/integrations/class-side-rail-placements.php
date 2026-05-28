<?php
/**
 * Newspack Ads Side Rail Placements.
 *
 * @package Newspack_Ads
 */

namespace Newspack_Ads\Integrations;

use Newspack_Ads\Placements;

/**
 * Side Rail Placements Class.
 */
class Side_Rail_Placements {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		/**
		 * Enables experimental side rail ad placements that display ads
		 * in sticky positions along the left and right margins of the content area.
		 *
		 * @constant NEWSPACK_ADS_SIDE_RAIL_PLACEMENTS
		 * @type     bool
		 * @default  Side rail ad placements disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_ADS_SIDE_RAIL_PLACEMENTS', true );
		 */
		if ( ! defined( 'NEWSPACK_ADS_SIDE_RAIL_PLACEMENTS' ) || ! NEWSPACK_ADS_SIDE_RAIL_PLACEMENTS ) {
			return;
		}

		add_action( 'init', [ __CLASS__, 'register_placements' ] );
		add_filter( 'newspack_ads_gtag_ads_data', [ __CLASS__, 'filter_ad_units' ] );
		add_filter( 'newspack_ads_placement_classnames', [ __CLASS__, 'filter_classnames' ], 10, 2 );
		add_filter( 'newspack_ads_gam_ad_unit_initial_display', [ __CLASS__, 'filter_ad_unit_initial_display' ], 10, 2 );
	}

	/**
	 * Register placements.
	 */
	public static function register_placements() {
		Placements::register_placement(
			'left_side_rail',
			[
				'name'        => __( 'Left Side Rail', 'newspack-ads' ),
				'description' => __( 'Choose an ad unit to display in the left side rail.', 'newspack-ads' ),
				'hook_name'   => 'wp_footer',
			]
		);

		Placements::register_placement(
			'right_side_rail',
			[
				'name'        => __( 'Right Side Rail', 'newspack-ads' ),
				'description' => __( 'Choose an ad unit to display in the right side rail.', 'newspack-ads' ),
				'hook_name'   => 'wp_footer',
			]
		);
	}

	/**
	 * Filter ad unit data
	 *
	 * @param array $ad_unit_data Ad unit data.
	 *
	 * @return array
	 */
	public static function filter_ad_units( $ad_unit_data ) {
		foreach ( $ad_unit_data as $placement_key => $ad_unit ) {
			if ( 'left_side_rail' === $ad_unit['placement'] || 'right_side_rail' === $ad_unit['placement'] ) {
				$ad_unit['fixed_height'] = false;
				$ad_unit['bounds_bleed'] = 0;
				$ad_unit_data[ $placement_key ] = $ad_unit;
			}
		}
		return $ad_unit_data;
	}

	/**
	 * Filter classnames.
	 *
	 * @param array  $classnames    Classnames.
	 * @param string $placement_key Placement key.
	 *
	 * @return array
	 */
	public static function filter_classnames( $classnames, $placement_key ) {
		if ( 'left_side_rail' === $placement_key || 'right_side_rail' === $placement_key ) {
			$classnames['fixed-height'] = false;
		}
		return $classnames;
	}

	/**
	 * Filter ad unit initial display.
	 *
	 * @param string|null $initial_display Ad unit initial display.
	 * @param array       $ad_unit         Ad unit data.
	 *
	 * @return string|null
	 */
	public static function filter_ad_unit_initial_display( $initial_display, $ad_unit ) {
		if ( 'left_side_rail' === $ad_unit['placement'] || 'right_side_rail' === $ad_unit['placement'] ) {
			return 'none';
		}
		return $initial_display;
	}
}
Side_Rail_Placements::init();
