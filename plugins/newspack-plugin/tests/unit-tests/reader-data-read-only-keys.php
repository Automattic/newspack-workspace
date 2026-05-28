<?php
/**
 * Tests for platform-conditional read-only key enforcement in Reader_Data.
 *
 * @package Newspack\Tests
 * @group reader-data-read-only
 */

use Newspack\Donations;
use Newspack\Reader_Data;

/**
 * Tests for Reader_Data read-only key behavior based on donation platform.
 *
 * @group reader-data-read-only
 */
class Newspack_Test_Reader_Data_Read_Only_Keys extends WP_UnitTestCase {

	/**
	 * Filter callback registered during test_filter_can_add_custom_key.
	 *
	 * @var callable|null
	 */
	private $custom_key_filter = null;

	/**
	 * Filter callback registered during test_filter_can_override_tracking.
	 *
	 * @var callable|null
	 */
	private $tracking_filter = null;

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		Donations::set_platform_slug( 'wc' );
		if ( $this->custom_key_filter ) {
			remove_filter( 'newspack_reader_data_read_only_keys', $this->custom_key_filter );
			$this->custom_key_filter = null;
		}
		if ( $this->tracking_filter ) {
			remove_filter( 'newspack_has_server_side_donor_tracking', $this->tracking_filter );
			$this->tracking_filter = null;
		}
		parent::tear_down();
	}

	/**
	 * Test that has_server_side_donor_tracking() returns true for WooCommerce.
	 */
	public function test_has_server_side_donor_tracking_wc() {
		Donations::set_platform_slug( 'wc' );
		self::assertTrue(
			Donations::has_server_side_donor_tracking(),
			'WooCommerce platform should have server-side donor tracking.'
		);
	}

	/**
	 * Test that has_server_side_donor_tracking() returns false for NRH.
	 */
	public function test_has_server_side_donor_tracking_nrh() {
		Donations::set_platform_slug( 'nrh' );
		self::assertFalse(
			Donations::has_server_side_donor_tracking(),
			'NRH platform should not have server-side donor tracking.'
		);
	}

	/**
	 * Test that has_server_side_donor_tracking() returns false for other.
	 */
	public function test_has_server_side_donor_tracking_other() {
		Donations::set_platform_slug( 'other' );
		self::assertFalse(
			Donations::has_server_side_donor_tracking(),
			'Other platform should not have server-side donor tracking.'
		);
	}

	/**
	 * Test that is_donor is read-only on WooCommerce platform.
	 */
	public function test_is_donor_read_only_on_wc() {
		Donations::set_platform_slug( 'wc' );
		self::assertContains(
			'is_donor',
			Reader_Data::get_read_only_keys(),
			'is_donor should be read-only on WooCommerce platform.'
		);
	}

	/**
	 * Test that is_donor is writable on NRH platform.
	 */
	public function test_is_donor_writable_on_nrh() {
		Donations::set_platform_slug( 'nrh' );
		self::assertNotContains(
			'is_donor',
			Reader_Data::get_read_only_keys(),
			'is_donor should be writable on NRH platform.'
		);
	}

	/**
	 * Test that is_donor is writable on other platform.
	 */
	public function test_is_donor_writable_on_other() {
		Donations::set_platform_slug( 'other' );
		self::assertNotContains(
			'is_donor',
			Reader_Data::get_read_only_keys(),
			'is_donor should be writable on other platform.'
		);
	}

	/**
	 * Test that is_former_donor is always read-only regardless of platform.
	 *
	 * @dataProvider platform_provider
	 * @param string $platform Platform slug.
	 */
	public function test_is_former_donor_always_read_only( $platform ) {
		Donations::set_platform_slug( $platform );
		self::assertContains(
			'is_former_donor',
			Reader_Data::get_read_only_keys(),
			"is_former_donor should be read-only on {$platform} platform."
		);
	}

	/**
	 * Test that a non-WC platform can declare server-side tracking via filter.
	 */
	public function test_filter_can_override_tracking() {
		Donations::set_platform_slug( 'nrh' );
		self::assertFalse(
			Donations::has_server_side_donor_tracking(),
			'NRH should not have server-side tracking by default.'
		);

		$this->tracking_filter = '__return_true';
		add_filter( 'newspack_has_server_side_donor_tracking', $this->tracking_filter );

		self::assertTrue(
			Donations::has_server_side_donor_tracking(),
			'Filter should allow NRH to declare server-side tracking.'
		);
		self::assertContains(
			'is_donor',
			Reader_Data::get_read_only_keys(),
			'is_donor should become read-only when filter declares server-side tracking.'
		);
	}

	/**
	 * Test that the newspack_reader_data_read_only_keys filter still works.
	 */
	public function test_filter_can_add_custom_key() {
		$this->custom_key_filter = function ( $keys ) {
			$keys[] = 'custom_key';
			return $keys;
		};
		add_filter( 'newspack_reader_data_read_only_keys', $this->custom_key_filter );

		self::assertContains(
			'custom_key',
			Reader_Data::get_read_only_keys(),
			'Filter should be able to add custom read-only keys.'
		);
	}

	/**
	 * Data provider for all platform slugs.
	 *
	 * @return array[]
	 */
	public function platform_provider() {
		return [
			'wc'    => [ 'wc' ],
			'nrh'   => [ 'nrh' ],
			'other' => [ 'other' ],
		];
	}
}
