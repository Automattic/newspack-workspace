<?php
/**
 * Tests for batched reader data writes and browser-only keys.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;

/**
 * The browser store sends every pending key in one request, so each key in a
 * batch must succeed or fail on its own.
 *
 * @group reader-data
 */
class Newspack_Test_Reader_Data_Batch extends WP_UnitTestCase {

	/**
	 * Reader writing the batch.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->reader_id );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Dispatch a batch write as the current user.
	 *
	 * @param mixed $items Items parameter.
	 *
	 * @return WP_REST_Response
	 */
	private function batch_request( $items ) {
		$request = new WP_REST_Request( 'POST', '/' . NEWSPACK_API_NAMESPACE . '/reader-data' );
		$request->set_param( 'items', $items );
		return rest_do_request( $request );
	}

	/**
	 * Every key in a batch is stored.
	 */
	public function test_batch_stores_every_key() {
		$response = $this->batch_request(
			[
				'articles_read' => '3',
				'last_active'   => '1793046441403',
			]
		);
		self::assertSame( 200, $response->get_status() );
		self::assertEmpty( (array) $response->get_data()['errors'] );
		self::assertSame( '3', Reader_Data::get_data( $this->reader_id, 'articles_read' ) );
		self::assertSame( '1793046441403', Reader_Data::get_data( $this->reader_id, 'last_active' ) );
	}

	/**
	 * A null value deletes its key, as the single-key DELETE route does.
	 */
	public function test_null_value_deletes_the_key() {
		Reader_Data::update_item( $this->reader_id, 'favorite_topic', '"sports"' );
		$response = $this->batch_request( [ 'favorite_topic' => null ] );
		self::assertSame( 200, $response->get_status() );
		self::assertFalse( Reader_Data::get_data( $this->reader_id, 'favorite_topic' ) );
	}

	/**
	 * A rejected key is reported on its own and doesn't stop the others.
	 */
	public function test_rejected_keys_are_reported_without_blocking_the_rest() {
		$response = $this->batch_request(
			[
				'active_subscriptions' => '[1]',
				'broken'               => 'not json',
				'articles_read'        => '3',
			]
		);
		self::assertSame( 200, $response->get_status() );
		self::assertEquals(
			[
				'active_subscriptions' => 'read_only_key',
				'broken'               => 'invalid_value',
			],
			(array) $response->get_data()['errors']
		);
		self::assertSame( '3', Reader_Data::get_data( $this->reader_id, 'articles_read' ) );
		self::assertFalse( Reader_Data::get_data( $this->reader_id, 'active_subscriptions' ) );
		self::assertFalse( Reader_Data::get_data( $this->reader_id, 'broken' ) );
	}

	/**
	 * The browser store is told which keys stay in the browser.
	 */
	public function test_config_lists_browser_only_keys() {
		$config = Reader_Data::get_config();
		self::assertContains( 'pageviews', $config['local_keys'] );
		self::assertContains( 'referrer', $config['local_keys'] );
	}
}
