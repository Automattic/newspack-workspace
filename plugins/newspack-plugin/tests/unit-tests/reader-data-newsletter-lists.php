<?php
/**
 * Tests for the newsletter list handlers in Reader_Data: the stored
 * `newsletter_subscribed_lists` item stays a JSON array of list IDs, merges
 * on every subscribe event, and is not blanked by a failed ESP lookup.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;

require_once dirname( __DIR__ ) . '/mocks/newsletters-mocks.php';

/**
 * Tests for Reader_Data::update_newsletter_subscribed_lists(),
 * Reader_Data::check_newsletter_subscription() and
 * Reader_Data::get_newsletter_subscribed_lists().
 *
 * @group reader-data
 */
class Newspack_Test_Reader_Data_Newsletter_Lists extends WP_UnitTestCase {
	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Reader email.
	 *
	 * @var string
	 */
	private $email = 'reader@example.com';

	/**
	 * Per-test setup.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Newsletters_Subscription::reset_calls();
		$this->user_id = $this->factory->user->create( [ 'user_email' => $this->email ] );
	}

	/**
	 * Per-test teardown.
	 */
	public function tear_down() {
		Newspack_Newsletters_Subscription::reset_calls();
		parent::tear_down();
	}

	/**
	 * Store a raw item value directly, bypassing update_item(), to seed the
	 * shapes earlier writers left behind.
	 *
	 * @param string $raw_value Raw meta value.
	 */
	private function seed_raw_lists( $raw_value ) {
		update_user_meta( $this->user_id, 'newspack_reader_data_keys', [ 'newsletter_subscribed_lists' ] );
		update_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'newsletter_subscribed_lists' ), $raw_value );
	}

	/**
	 * The stored item, as written.
	 *
	 * @return mixed
	 */
	private function stored_lists() {
		return Reader_Data::get_data( $this->user_id, 'newsletter_subscribed_lists' );
	}

	/**
	 * Fire the handler the way the data events do.
	 *
	 * @param array $data Event payload minus the user ID.
	 */
	private function handle( array $data ) {
		Reader_Data::update_newsletter_subscribed_lists( time(), array_merge( [ 'user_id' => $this->user_id ], $data ) );
	}

	/**
	 * Removing a list that is not the last stored one must still leave a JSON
	 * array: array_diff() keeps keys, and a keyed array encodes as an object.
	 */
	public function test_removing_a_non_trailing_list_stores_a_json_array() {
		Reader_Data::update_item( $this->user_id, 'newsletter_subscribed_lists', [ 'list-1', 'list-2' ] );
		$this->handle( [ 'lists_removed' => [ 'list-1' ] ] );
		$this->assertSame( '["list-2"]', $this->stored_lists() );
	}

	/**
	 * A value an earlier removal left in object shape is repaired by the next
	 * removal instead of being skipped.
	 */
	public function test_removal_repairs_an_object_shaped_stored_value() {
		$this->seed_raw_lists( '{"1":"list-2"}' );
		$this->handle( [ 'lists_removed' => [ 'list-2' ] ] );
		$this->assertSame( '[]', $this->stored_lists() );
		$this->assertSame( 'false', Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * Adding to an object-shaped value keeps the lists it holds instead of
	 * replacing the whole set with the lists just added.
	 */
	public function test_adding_to_an_object_shaped_stored_value_keeps_the_existing_lists() {
		$this->seed_raw_lists( '{"1":"list-2"}' );
		$this->handle( [ 'lists_added' => [ 'list-3' ] ] );
		$this->assertSame( '["list-2","list-3"]', $this->stored_lists() );
	}

	/**
	 * The subscribe() call fires newsletter_subscribed for contacts that already
	 * exist, with only the lists in that call, so the event merges instead of
	 * replacing.
	 */
	public function test_subscribed_event_merges_into_the_stored_lists() {
		Reader_Data::update_item( $this->user_id, 'newsletter_subscribed_lists', [ 'list-1', 'list-2' ] );
		$this->handle( [ 'lists' => [ 'list-3' ] ] );
		$this->assertSame( '["list-1","list-2","list-3"]', $this->stored_lists() );
		$this->assertSame( 'true', Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * Re-subscribing to a stored list does not duplicate it.
	 */
	public function test_subscribed_event_does_not_duplicate_lists() {
		Reader_Data::update_item( $this->user_id, 'newsletter_subscribed_lists', [ 'list-1' ] );
		$this->handle( [ 'lists' => [ 'list-1', 'list-2' ] ] );
		$this->assertSame( '["list-1","list-2"]', $this->stored_lists() );
	}

	/**
	 * A removal for a reader with nothing stored records an empty list, not the
	 * string "false".
	 */
	public function test_removal_without_a_stored_value_stores_an_empty_list() {
		$this->handle( [ 'lists_removed' => [ 'list-1' ] ] );
		$this->assertSame( '[]', $this->stored_lists() );
	}

	/**
	 * The providers answer an empty list for a failed contact lookup too, so an
	 * empty read is only trusted when the contact itself is readable.
	 */
	public function test_login_refresh_keeps_the_stored_lists_when_the_contact_lookup_fails() {
		Reader_Data::update_item( $this->user_id, 'newsletter_subscribed_lists', [ 'list-1' ] );
		// The mock returns [] from get_contact_lists() and a WP_Error from get_contact_data() by default.
		Reader_Data::check_newsletter_subscription(
			time(),
			[
				'user_id' => $this->user_id,
				'email'   => $this->email,
			]
		);
		$this->assertSame( '["list-1"]', $this->stored_lists() );
	}

	/**
	 * A readable contact on no lists is a real state and is stored.
	 */
	public function test_login_refresh_stores_an_empty_list_for_a_readable_contact() {
		Reader_Data::update_item( $this->user_id, 'newsletter_subscribed_lists', [ 'list-1' ] );
		Newspack_Newsletters_Subscription::$contact_data[ $this->email ] = [ 'email' => $this->email ];
		Reader_Data::check_newsletter_subscription(
			time(),
			[
				'user_id' => $this->user_id,
				'email'   => $this->email,
			]
		);
		$this->assertSame( '[]', $this->stored_lists() );
		$this->assertSame( 'false', Reader_Data::get_data( $this->user_id, 'is_newsletter_subscriber' ) );
	}

	/**
	 * The typed accessor returns the stored list as strings.
	 */
	public function test_get_newsletter_subscribed_lists_returns_the_stored_ids() {
		Reader_Data::update_item( $this->user_id, 'newsletter_subscribed_lists', [ 'list-1', 123 ] );
		$this->assertSame( [ 'list-1', '123' ], Reader_Data::get_newsletter_subscribed_lists( $this->user_id ) );
	}

	/**
	 * No stored item means the selection is unknown, not empty.
	 */
	public function test_get_newsletter_subscribed_lists_returns_null_without_a_stored_item() {
		$this->assertNull( Reader_Data::get_newsletter_subscribed_lists( $this->user_id ) );
	}

	/**
	 * A value that is not a plain JSON list may have dropped a removal, so it
	 * is unknown rather than a partial answer.
	 */
	public function test_get_newsletter_subscribed_lists_returns_null_for_an_object_shaped_value() {
		$this->seed_raw_lists( '{"1":"list-2"}' );
		$this->assertNull( Reader_Data::get_newsletter_subscribed_lists( $this->user_id ) );
	}
}
