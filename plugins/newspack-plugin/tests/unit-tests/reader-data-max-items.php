<?php
/**
 * Tests for the max items cap in Reader_Data::update_item().
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;

/**
 * Tests for the Reader_Data max items cap and its filter.
 *
 * @group reader-data-max-items
 */
class Newspack_Test_Reader_Data_Max_Items extends WP_UnitTestCase {

	/**
	 * Filter callback registered during test_filter_can_raise_max_items.
	 *
	 * @var callable|null
	 */
	private $max_items_filter = null;

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		if ( $this->max_items_filter ) {
			remove_filter( 'newspack_reader_data_max_items', $this->max_items_filter );
			$this->max_items_filter = null;
		}
		parent::tear_down();
	}

	/**
	 * Create a user that already has MAX_ITEMS reader data keys.
	 *
	 * @return int User ID.
	 */
	private function create_user_at_cap() {
		$user_id = self::factory()->user->create();
		$keys    = [];
		for ( $i = 1; $i <= Reader_Data::MAX_ITEMS; $i++ ) {
			$keys[] = 'key_' . $i;
		}
		update_user_meta( $user_id, 'newspack_reader_data_keys', $keys );
		return $user_id;
	}

	/**
	 * Test that a new item beyond the default cap is rejected.
	 */
	public function test_default_cap_blocks_new_item() {
		$user_id = $this->create_user_at_cap();

		$result = Reader_Data::update_item( $user_id, 'beyond_cap', 'value' );

		self::assertWPError( $result, 'Writing a new item beyond the default cap should fail.' );
		self::assertSame( 'too_many_items', $result->get_error_code() );
	}

	/**
	 * Test that the newspack_reader_data_max_items filter can raise the cap.
	 */
	public function test_filter_can_raise_max_items() {
		$user_id = $this->create_user_at_cap();

		$this->max_items_filter = function () {
			return Reader_Data::MAX_ITEMS + 10;
		};
		add_filter( 'newspack_reader_data_max_items', $this->max_items_filter );

		$result = Reader_Data::update_item( $user_id, 'beyond_cap', 'value' );

		self::assertTrue( $result, 'Raising the cap via the filter should allow writing an item beyond MAX_ITEMS.' );
		self::assertSame( 'value', Reader_Data::get_data( $user_id, 'beyond_cap' ) );
	}
}
