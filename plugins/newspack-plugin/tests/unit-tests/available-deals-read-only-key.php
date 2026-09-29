<?php
/**
 * Tests that available_deals is a read-only reader-data key.
 *
 * @package Newspack\Tests
 * @group reader-data
 */

use Newspack\Reader_Data;

/**
 * Tests that available_deals is a read-only reader-data key.
 *
 * @group reader-data
 */
class Newspack_Test_Available_Deals_Read_Only_Key extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack\Available_Deals_Bridge::init();
	}
	/**
	 * Test that available_deals is a read-only reader-data key.
	 */
	public function test_available_deals_is_read_only() {
		self::assertContains( 'available_deals', Reader_Data::get_read_only_keys(), 'available_deals must be a read-only reader-data key.' );
	}
}
