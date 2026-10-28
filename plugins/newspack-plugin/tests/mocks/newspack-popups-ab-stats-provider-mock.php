<?php
/**
 * Newspack_Popups_AB_Stats_Provider for tests.
 *
 * Newspack Campaigns declares the interface a stats provider implements. CI
 * runs each plugin's suite alone, so the real file is loaded when the sibling
 * plugin is checked out beside this one (the monorepo), and mirrored here
 * otherwise. A monorepo run checks the provider against the real interface;
 * the mirror is never declared there, so it is kept in step by hand.
 *
 * @package Newspack\Tests
 */

$newspack_popups_interface = dirname( __DIR__, 3 ) . '/newspack-popups/includes/interface-newspack-popups-ab-stats-provider.php';
if ( ! interface_exists( 'Newspack_Popups_AB_Stats_Provider' ) && file_exists( $newspack_popups_interface ) ) {
	require_once $newspack_popups_interface;
}

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
