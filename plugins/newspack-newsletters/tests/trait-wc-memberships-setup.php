<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files
/**
 * Class Newsletters Test WC Memberships Setup.
 *
 * @package Newspack_Newsletters
 */

trait WC_Memberships_Setup {
	public function set_up() {
		global $test_wc_memberships;
		$test_wc_memberships = self::setup_test_memberships();
	}

	public function tear_down() {
		global $test_wc_memberships;
		$test_wc_memberships = [];
	}
}
