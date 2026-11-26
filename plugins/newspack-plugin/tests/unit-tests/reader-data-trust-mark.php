<?php
/**
 * Tests for the server mark on reader data writes.
 *
 * @package Newspack\Tests
 * @group reader-data-trust
 */

use Newspack\Reader_Data;
use Newspack\Reader_Data_Trust;

/**
 * Tests for Reader_Data_Trust marks.
 *
 * @group reader-data-trust
 */
class Newspack_Test_Reader_Data_Trust_Mark extends WP_UnitTestCase {

	/**
	 * User ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->user_id = $this->factory()->user->create();
		wp_set_current_user( $this->user_id );
		Reader_Data::update_item( $this->user_id, 'plan', 'premium' );
	}

	/**
	 * Send a request to the reader-data route as the current user.
	 *
	 * @param string $method HTTP method.
	 * @param array  $params Request params.
	 */
	private function send( $method, $params ) {
		$request = new WP_REST_Request( $method, '/newspack/v1/reader-data' );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * A server write sets a valid mark.
	 */
	public function test_server_write_sets_valid_mark() {
		$this->assertTrue( Reader_Data_Trust::has_valid_mark( $this->user_id, 'plan' ) );
	}

	/**
	 * A write through the endpoint is not marked.
	 */
	public function test_endpoint_write_has_no_mark() {
		$response = $this->send(
			'POST',
			[
				'key'   => 'plan',
				'value' => '"gold"',
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( Reader_Data_Trust::has_valid_mark( $this->user_id, 'plan' ) );
	}

	/**
	 * Changing the stored value outside Reader_Data voids the mark.
	 */
	public function test_direct_meta_write_voids_mark() {
		update_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'plan' ), 'gold' );
		$this->assertFalse( Reader_Data_Trust::has_valid_mark( $this->user_id, 'plan' ) );
	}

	/**
	 * Deleting an item removes its mark.
	 */
	public function test_delete_removes_mark() {
		$this->assertNotSame( '', get_user_meta( $this->user_id, Reader_Data_Trust::MARK_META_PREFIX . 'plan', true ) );
		$response = $this->send( 'DELETE', [ 'key' => 'plan' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_user_meta( $this->user_id, Reader_Data_Trust::MARK_META_PREFIX . 'plan', true ) );
	}

	/**
	 * A value with no mark is unmarked.
	 */
	public function test_value_without_mark_is_unmarked() {
		update_user_meta( $this->user_id, Reader_Data::get_meta_key_name( 'other' ), 'premium' );
		$this->assertFalse( Reader_Data_Trust::has_valid_mark( $this->user_id, 'other' ) );
	}

	/**
	 * A value that sanitization changes still matches its mark.
	 */
	public function test_sanitized_value_still_matches() {
		Reader_Data::update_item( $this->user_id, 'plan', '<b>premium</b>' );
		$this->assertTrue( Reader_Data_Trust::has_valid_mark( $this->user_id, 'plan' ) );
	}
}
