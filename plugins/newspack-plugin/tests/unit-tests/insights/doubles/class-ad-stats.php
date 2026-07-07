<?php
/**
 * Test double for the newsletters plugin's Ad_Stats reader (NPPD-1861).
 *
 * The newsletters plugin is not loaded in this suite's bootstrap, so the
 * Newsletter Ads metric tests ship this minimal stand-in — just enough
 * surface for {@see \Newspack\Insights\Newsletter_Ads_Metric} (the
 * class_exists readiness gate + get_table_name()). Guarded so it never
 * shadows the real class if the plugin is ever added to the bootstrap.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack_Newsletters\Tracking;

if ( ! class_exists( 'Newspack_Newsletters\Tracking\Ad_Stats' ) ) {
	/**
	 * Minimal Ad_Stats double.
	 */
	class Ad_Stats {
		/**
		 * Stats table name, matching the real class's contract.
		 *
		 * @return string
		 */
		public static function get_table_name() {
			global $wpdb;
			return $wpdb->prefix . 'newspack_newsletters_ad_stats';
		}
	}
}
