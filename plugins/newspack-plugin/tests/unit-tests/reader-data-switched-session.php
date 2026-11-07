<?php
/**
 * Tests for the switched-session guard in Reader_Data.
 *
 * @package Newspack\Tests
 * @group reader-data
 */

use Newspack\Active_Subscriptions_Reader_Data;
use Newspack\Reader_Data;

require_once __DIR__ . '/../mocks/user-switching-mock.php';
require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * A session an admin opened by switching into a reader's account must read the
 * reader's stored data but never write to it.
 *
 * @group reader-data
 */
class Newspack_Test_Reader_Data_Switched_Session extends WP_UnitTestCase {

	/**
	 * Reader whose data the switched session must not touch.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		$this->reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->reader_id );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		unset( $GLOBALS['newspack_test_switched_from_user'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Mark the current session as one an admin switched into.
	 */
	private function switch_from_admin() {
		$admin_id                                     = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$GLOBALS['newspack_test_switched_from_user'] = get_user_by( 'id', $admin_id );
	}

	/**
	 * Dispatch a reader-data REST request as the current user.
	 *
	 * @param string $method HTTP method.
	 * @param array  $params Request parameters.
	 *
	 * @return WP_REST_Response
	 */
	private function reader_data_request( $method, $params ) {
		$request = new WP_REST_Request( $method, '/' . NEWSPACK_API_NAMESPACE . '/reader-data' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * Control: a reader's own session writes through the REST route.
	 */
	public function test_direct_session_writes_reader_data() {
		$response = $this->reader_data_request(
			'POST',
			[
				'key'   => 'favorite_topic',
				'value' => '"sports"',
			]
		);
		self::assertSame( 200, $response->get_status() );
		self::assertSame( '"sports"', Reader_Data::get_data( $this->reader_id, 'favorite_topic' ) );
	}

	/**
	 * A switched session is refused at the route, and nothing is stored.
	 */
	public function test_switched_session_cannot_write_reader_data() {
		$this->switch_from_admin();
		$response = $this->reader_data_request(
			'POST',
			[
				'key'   => 'matched_segments',
				'value' => '["1"]',
			]
		);
		self::assertSame( 403, $response->get_status() );
		self::assertFalse( Reader_Data::get_data( $this->reader_id, 'matched_segments' ) );
	}

	/**
	 * A switched session cannot delete what the reader's own sessions stored.
	 */
	public function test_switched_session_cannot_delete_reader_data() {
		Reader_Data::update_item( $this->reader_id, 'matched_segments', '["7"]' );
		$this->switch_from_admin();
		$response = $this->reader_data_request( 'DELETE', [ 'key' => 'matched_segments' ] );
		self::assertSame( 403, $response->get_status() );
		self::assertSame( [ '7' ], Reader_Data::get_matched_segments( $this->reader_id ) );
	}

	/**
	 * The browser store is told which session it is, so it can hydrate the
	 * reader's stored data without syncing anything back and the popups script
	 * can show the stored segment. It is not the preview-style temporary mode,
	 * which skips hydration altogether.
	 */
	public function test_switched_session_config_is_flagged_but_not_temporary() {
		$this->switch_from_admin();
		$config = Reader_Data::get_config();
		self::assertFalse( $config['is_temporary'] );
		self::assertTrue( $config['is_switched_session'] );
	}

	/**
	 * Control: a direct login keeps the persistent store and is not flagged.
	 */
	public function test_direct_session_config_is_persistent_and_unflagged() {
		$config = Reader_Data::get_config();
		self::assertFalse( $config['is_temporary'] );
		self::assertFalse( $config['is_switched_session'] );
	}

	/**
	 * Give the reader a subscription, so recomputing their `active_subscriptions`
	 * list would store a product.
	 */
	private function give_reader_a_subscription() {
		\wcs_create_subscription(
			[
				'customer_id' => $this->reader_id,
				'status'      => 'active',
				'products'    => [ 101 ],
				'items'       => [ new \WC_Order_Item_Product( [ 'product_id' => 101 ] ) ],
			]
		);
	}

	/**
	 * Control: a reader's own page view stores their `active_subscriptions` list.
	 */
	public function test_direct_session_backfills_active_subscriptions() {
		$this->give_reader_a_subscription();

		Active_Subscriptions_Reader_Data::maybe_backfill_current_reader();

		self::assertSame( '[101]', Reader_Data::get_data( $this->reader_id, 'active_subscriptions' ) );
		self::assertFalse( Active_Subscriptions_Reader_Data::is_stale( $this->reader_id ) );
	}

	/**
	 * A switched session recomputes nothing on a page view: it would write into
	 * the reader's data, which a session an admin borrowed must never do.
	 */
	public function test_switched_session_page_view_does_not_backfill_active_subscriptions() {
		$this->give_reader_a_subscription();
		$this->switch_from_admin();

		Active_Subscriptions_Reader_Data::maybe_backfill_current_reader();

		self::assertFalse( Reader_Data::get_data( $this->reader_id, 'active_subscriptions' ) );
		self::assertEmpty( get_user_meta( $this->reader_id, Active_Subscriptions_Reader_Data::STAMP_META_KEY, true ) );
	}

	/**
	 * Nor does the hydration response of a switched session, and it still hands
	 * back what it was given.
	 */
	public function test_switched_session_hydration_does_not_backfill_active_subscriptions() {
		$this->give_reader_a_subscription();
		$this->switch_from_admin();

		$data = Active_Subscriptions_Reader_Data::maybe_backfill_hydrated_reader( [ 'nonce' => 'abc' ], $this->reader_id );

		self::assertSame( [ 'nonce' => 'abc' ], $data );
		self::assertFalse( Reader_Data::get_data( $this->reader_id, 'active_subscriptions' ) );
		self::assertEmpty( get_user_meta( $this->reader_id, Active_Subscriptions_Reader_Data::STAMP_META_KEY, true ) );
	}
}
