<?php
/**
 * Tests for Reader_Data::get_matched_segments().
 *
 * @package Newspack\Tests
 * @group reader-data
 */

use Newspack\Reader_Data;

/**
 * Tests for Reader_Data::get_matched_segments().
 *
 * @group reader-data
 */
class Newspack_Test_Reader_Data_Matched_Segments extends WP_UnitTestCase {

	/**
	 * Returns an empty array when no matched_segments item exists for the user.
	 */
	public function test_returns_empty_array_when_no_item() {
		$user_id = self::factory()->user->create();
		self::assertSame( [], Reader_Data::get_matched_segments( $user_id ) );
	}

	/**
	 * Returns stored IDs as strings when values are already strings.
	 */
	public function test_returns_stored_ids_as_strings() {
		$user_id = self::factory()->user->create();
		Reader_Data::update_item( $user_id, 'matched_segments', wp_json_encode( [ '12', '43' ] ) );
		self::assertSame( [ '12', '43' ], Reader_Data::get_matched_segments( $user_id ) );
	}

	/**
	 * Coerces numeric IDs to strings.
	 */
	public function test_coerces_numeric_ids_to_strings() {
		$user_id = self::factory()->user->create();
		Reader_Data::update_item( $user_id, 'matched_segments', wp_json_encode( [ 12, 43 ] ) );
		self::assertSame( [ '12', '43' ], Reader_Data::get_matched_segments( $user_id ) );
	}

	/**
	 * Returns an empty array when the stored value is not valid JSON.
	 */
	public function test_returns_empty_array_for_malformed_value() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'newspack_reader_data_keys', [ 'matched_segments' ] );
		update_user_meta( $user_id, Reader_Data::get_meta_key_name( 'matched_segments' ), 'not-json' );
		self::assertSame( [], Reader_Data::get_matched_segments( $user_id ) );
	}
}
