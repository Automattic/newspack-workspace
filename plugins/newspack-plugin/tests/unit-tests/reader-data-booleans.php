<?php
/**
 * Tests for reading boolean reader data items.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;

/**
 * Reader data stores a boolean JSON-encoded, so a false reads back from
 * get_data() as the string "false", which PHP treats as true. get_bool() is the
 * read that tells the two apart.
 *
 * @group reader-data-booleans
 */
class Newspack_Test_Reader_Data_Booleans extends WP_UnitTestCase {

	/**
	 * Test that a flag reads as false until set, then follows the stored value.
	 */
	public function test_get_bool_reads_a_stored_false_as_false() {
		$user_id = self::factory()->user->create();

		self::assertFalse( Reader_Data::get_bool( $user_id, 'is_donor' ), 'A flag that was never set should read as false.' );

		Reader_Data::update_item( $user_id, 'is_donor', true );
		self::assertTrue( Reader_Data::get_bool( $user_id, 'is_donor' ) );

		Reader_Data::update_item( $user_id, 'is_donor', false );
		self::assertFalse( Reader_Data::get_bool( $user_id, 'is_donor' ), 'A stored false should read as false.' );
	}
}
