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
	 * The cap bounds how many keys a reader accumulates, so it must not block
	 * an update to a key they already have — that write doesn't grow the list.
	 * Enforcing it unconditionally strands an at-cap reader: their stored
	 * fields could never be refreshed, and on the integrations pull path a
	 * rejected write is permanent-class, so every re-pull would fail with no
	 * operator remedy short of deleting reader data (NPPD-2076 review).
	 */
	public function test_at_cap_reader_can_still_refresh_an_existing_item() {
		$user_id = $this->create_user_at_cap();

		$result = Reader_Data::update_item( $user_id, 'key_1', 'refreshed' );

		self::assertTrue( $result, 'Updating an existing key must work at the cap.' );
		self::assertSame( 'refreshed', Reader_Data::get_data( $user_id, 'key_1' ) );
		self::assertCount( Reader_Data::MAX_ITEMS, get_user_meta( $user_id, 'newspack_reader_data_keys', true ), 'The key list did not grow.' );
	}

	/**
	 * The same holds for validate_item(), so a dry-run preview agrees.
	 */
	public function test_validate_item_allows_refreshing_an_existing_item_at_cap() {
		$user_id = $this->create_user_at_cap();

		self::assertTrue( Reader_Data::validate_item( $user_id, 'key_1', 'refreshed' ) );
		self::assertWPError( Reader_Data::validate_item( $user_id, 'brand_new', 'value' ) );
	}

	/**
	 * A preview writes nothing, so a batch of new keys that collectively crosses
	 * the cap must be validated against the count it would really reach —
	 * otherwise every per-field check passes and the preview reports zero errors
	 * for a reader the real run fails (NPPD-2076 review).
	 */
	public function test_validate_item_honors_pending_keys_from_a_preview() {
		$user_id = self::factory()->user->create();
		$keys    = [];
		for ( $i = 1; $i < Reader_Data::MAX_ITEMS; $i++ ) {
			$keys[] = 'key_' . $i;
		}
		update_user_meta( $user_id, 'newspack_reader_data_keys', $keys );

		// One slot left: the first new key fits, the second only fails once the
		// first is accounted for as pending.
		self::assertTrue( Reader_Data::validate_item( $user_id, 'new_a', 'value' ) );
		self::assertTrue( Reader_Data::validate_item( $user_id, 'new_b', 'value' ), 'Without pending keys both look storable.' );

		$result = Reader_Data::validate_item( $user_id, 'new_b', 'value', [ 'new_a' ] );

		self::assertWPError( $result, 'With new_a pending, the reader is at the cap.' );
		self::assertSame( 'too_many_items', $result->get_error_code() );
	}

	/**
	 * Pending keys that the reader already has must not double-count.
	 */
	public function test_pending_keys_do_not_double_count_existing_keys() {
		$user_id = $this->create_user_at_cap();

		self::assertTrue( Reader_Data::validate_item( $user_id, 'key_1', 'refreshed', [ 'key_2' ] ) );
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
