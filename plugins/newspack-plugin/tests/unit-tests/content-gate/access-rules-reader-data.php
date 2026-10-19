<?php
/**
 * Tests reader data rules against server-managed reader data.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use Newspack\Reader_Data;
use Newspack\Reader_Data_Trust;

/**
 * Reader data rules match only values the server wrote for server-managed keys.
 *
 * @group Access_Rules
 * @group reader-data-trust
 */
class Newspack_Test_Access_Rules_Reader_Data extends WP_UnitTestCase {

	/**
	 * Keys the tests treat as server-managed, beyond the built-in ones.
	 */
	const EXTRA_TRUSTED_KEYS = [ 'plan', 'score', 'note' ];

	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $uid;

	/**
	 * Read-only keys filter.
	 *
	 * @var callable
	 */
	private $read_only_filter;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->read_only_filter = function ( $keys ) {
			return array_merge( $keys, self::EXTRA_TRUSTED_KEYS );
		};
		add_filter( 'newspack_reader_data_read_only_keys', $this->read_only_filter );
		Reader_Data_Trust::reset_cache();
		$this->uid = $this->factory->user->create();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'newspack_reader_data_read_only_keys', $this->read_only_filter );
		Reader_Data_Trust::reset_cache();
		parent::tear_down();
	}

	/**
	 * A JSON string value matches the bare rule value.
	 */
	public function test_string_value_matches() {
		Reader_Data::update_item( $this->uid, 'plan', '"premium"' );

		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'plan=premium' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'plan=basic' ) );
	}

	/**
	 * A stored value that is not JSON compares as the raw string.
	 */
	public function test_bare_string_value_matches() {
		Reader_Data::update_item( $this->uid, 'plan', 'premium' );

		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'plan=premium' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'plan=Premium' ) );
	}

	/**
	 * Booleans match `true`/`false`, numbers match numerically.
	 */
	public function test_bool_and_number_match() {
		Reader_Data::update_item( $this->uid, 'is_newsletter_subscriber', true );
		Reader_Data::update_item( $this->uid, 'score', 5 );

		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'is_newsletter_subscriber=true' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'is_newsletter_subscriber=false' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'is_newsletter_subscriber=1' ) );
		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'score=5' ) );
		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'score=5.0' ) );
		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'score=05' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'score=6' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'score=five' ) );
		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'is_newsletter_subscriber=true;score=5' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'is_newsletter_subscriber=true;score=6' ) );
	}

	/**
	 * Arrays, objects and null never match.
	 */
	public function test_array_value_never_matches() {
		Reader_Data::update_item( $this->uid, 'active_subscriptions', [ 1, 2 ] );
		Reader_Data::update_item( $this->uid, 'plan', [ 'tier' => 'gold' ] );
		Reader_Data::update_item( $this->uid, 'note', 'null' );

		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'active_subscriptions=[1,2]' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'active_subscriptions=1' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'plan={"tier":"gold"}' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'note=null' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'note=' ) );
	}

	/**
	 * A key the server does not manage never matches, even with a server write.
	 */
	public function test_untrusted_key_never_matches() {
		Reader_Data::update_item( $this->uid, 'utm_source', '"x"' );

		$this->assertFalse( Reader_Data_Trust::is_trusted_key( 'utm_source' ) );
		$this->assertNull( Reader_Data_Trust::get_trusted_value( $this->uid, 'utm_source' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'utm_source=x' ) );
	}

	/**
	 * A server-managed key whose value the server did not write never matches.
	 */
	public function test_unmarked_trusted_value_never_matches() {
		update_user_meta( $this->uid, 'newspack_reader_data_keys', [ 'is_newsletter_subscriber' ] );
		update_user_meta( $this->uid, Reader_Data::get_meta_key_name( 'is_newsletter_subscriber' ), 'true' );

		$this->assertTrue( Reader_Data_Trust::is_trusted_key( 'is_newsletter_subscriber' ) );
		$this->assertNull( Reader_Data_Trust::get_trusted_value( $this->uid, 'is_newsletter_subscriber' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'is_newsletter_subscriber=true' ) );

		// A value the server wrote, then changed outside it.
		Reader_Data::update_item( $this->uid, 'plan', '"basic"' );
		update_user_meta( $this->uid, Reader_Data::get_meta_key_name( 'plan' ), '"premium"' );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'plan=premium' ) );
	}

	/**
	 * Unset keys never match.
	 */
	public function test_unset_trusted_key_never_matches() {
		$this->assertNull( Reader_Data_Trust::get_trusted_value( $this->uid, 'plan' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'plan=premium' ) );
	}

	/**
	 * Items without a key or `=` fail; whitespace and a trailing `;` are tolerated,
	 * and the value keeps any later `=`.
	 */
	public function test_malformed_items_fail() {
		Reader_Data::update_item( $this->uid, 'is_donor', true );
		Reader_Data::update_item( $this->uid, 'plan', '"premium"' );
		Reader_Data::update_item( $this->uid, 'note', '"a=b"' );

		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'is_donor' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, '=true' ) );
		$this->assertFalse( Access_Rules::has_reader_data( $this->uid, 'plan=premium;0' ) );
		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'plan = premium ;' ) );
		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, 'note=a=b' ) );
	}

	/**
	 * An empty rule still grants access.
	 */
	public function test_empty_rule_still_grants() {
		$this->assertTrue( Access_Rules::has_reader_data( $this->uid, '' ) );
	}

	/**
	 * Stored values compare to rule values by decoded type.
	 */
	public function test_values_match() {
		$this->assertTrue( Reader_Data_Trust::values_match( '"premium"', 'premium' ) );
		$this->assertTrue( Reader_Data_Trust::values_match( 'premium', 'premium' ) );
		$this->assertTrue( Reader_Data_Trust::values_match( 'false', 'false' ) );
		$this->assertFalse( Reader_Data_Trust::values_match( 'false', '' ) );
		$this->assertTrue( Reader_Data_Trust::values_match( '2.5', '2.50' ) );
		$this->assertFalse( Reader_Data_Trust::values_match( '"5"', '5.0' ) );
		$this->assertFalse( Reader_Data_Trust::values_match( '5', '' ) );
		$this->assertFalse( Reader_Data_Trust::values_match( '[]', '' ) );
		$this->assertFalse( Reader_Data_Trust::values_match( '{}', '{}' ) );
	}

	/**
	 * The rule ships the trusted keys to the editor.
	 */
	public function test_rule_lists_trusted_keys() {
		$rule = Access_Rules::get_rule( 'reader_data' );

		$this->assertIsArray( $rule['trusted_keys'] );
		$this->assertContains( 'is_newsletter_subscriber', $rule['trusted_keys'] );
		$this->assertNotContains( 'utm_source', $rule['trusted_keys'] );

		$client = Access_Rules::get_access_rules_for_client();
		$this->assertArrayHasKey( 'trusted_keys', $client['reader_data'] );
	}
}
