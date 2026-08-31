<?php
/**
 * Tests for falsy-but-valid value handling in Reader_Data::update_item().
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;

/**
 * A numeric zero JSON-encodes to the string "0", which PHP treats as falsy.
 * The write must still store it: on the integrations pull path a rejection is
 * permanent, so a legitimately-zero field (a donation count, a score) would
 * fail every pull and re-enqueue the reader indefinitely.
 *
 * @group reader-data-falsy-values
 */
class Newspack_Test_Reader_Data_Falsy_Values extends WP_UnitTestCase {

	/**
	 * Falsy-but-valid values that must be stored.
	 *
	 * Non-string values are JSON-encoded by update_item(); strings are stored
	 * as-is, which is why integer 0 and string "0" share an expectation.
	 *
	 * @return array<string, array{mixed, string}> Value and its expected stored form.
	 */
	public function falsy_valid_value_provider() {
		return [
			'integer zero'      => [ 0, '0' ],
			'float zero'        => [ 0.0, '0' ],
			'string zero'       => [ '0', '0' ],
			'boolean false'     => [ false, 'false' ],
			'null'              => [ null, 'null' ],
			'empty array'       => [ [], '[]' ],
			'empty json string' => [ wp_json_encode( '' ), '""' ],
		];
	}

	/**
	 * Test that falsy-but-valid values are stored rather than rejected.
	 *
	 * @param mixed  $value    Value passed to update_item().
	 * @param string $expected Expected stored value.
	 *
	 * @dataProvider falsy_valid_value_provider
	 */
	public function test_falsy_valid_values_are_stored( $value, $expected ) {
		$user_id = self::factory()->user->create();

		$result = Reader_Data::update_item( $user_id, 'crm_score', $value );

		self::assertTrue( $result, 'A falsy-but-valid value should be stored, not rejected.' );
		self::assertSame( $expected, Reader_Data::get_data( $user_id, 'crm_score' ) );
	}

	/**
	 * Test that an empty string is still rejected as invalid.
	 */
	public function test_empty_string_is_rejected() {
		$user_id = self::factory()->user->create();

		$result = Reader_Data::update_item( $user_id, 'crm_score', '' );

		self::assertWPError( $result, 'An empty string carries no value and should be rejected.' );
		self::assertSame( 'invalid_value', $result->get_error_code() );
		self::assertFalse( Reader_Data::get_data( $user_id, 'crm_score' ), 'Nothing should be stored for a rejected write.' );
	}

	/**
	 * Test that a value which cannot be JSON-encoded is rejected.
	 */
	public function test_unencodable_value_is_rejected() {
		$user_id = self::factory()->user->create();

		// NAN cannot be represented in JSON, so wp_json_encode() returns false.
		$result = Reader_Data::update_item( $user_id, 'crm_score', NAN );

		self::assertWPError( $result, 'A value that cannot be JSON-encoded should be rejected.' );
		self::assertSame( 'invalid_value', $result->get_error_code() );
		self::assertFalse( Reader_Data::get_data( $user_id, 'crm_score' ), 'Nothing should be stored for a rejected write.' );
	}

	/**
	 * Test that storing a zero registers the key, so a later read returns it.
	 */
	public function test_zero_value_registers_the_key() {
		$user_id = self::factory()->user->create();

		Reader_Data::update_item( $user_id, 'crm_score', 0 );

		self::assertSame( [ 'crm_score' => '0' ], Reader_Data::get_data( $user_id ) );
	}
}
