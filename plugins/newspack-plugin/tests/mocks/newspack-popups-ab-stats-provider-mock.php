<?php
/**
 * Newspack_Popups_AB_Stats_Provider stub for tests.
 *
 * Newspack Campaigns declares the interface a stats provider implements. CI
 * runs each plugin's suite alone, so it is never loaded here; this stub
 * mirrors it so `GA4_AB_Stats_Provider` can be declared. Keep the three
 * method signatures identical to the interface in newspack-popups.
 *
 * @package Newspack\Tests
 */

if ( ! interface_exists( 'Newspack_Popups_AB_Stats_Provider' ) ) {
	/**
	 * Mirror of the Campaigns provider interface.
	 */
	interface Newspack_Popups_AB_Stats_Provider {
		public function get_slug(): string; // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		public function is_available(); // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		public function get_counts( array $test, string $goal, string $start, string $end ); // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	}
}
