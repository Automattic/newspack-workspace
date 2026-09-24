<?php
/**
 * Tests for the deletion listeners in listeners.php.
 *
 * @package Newspack\Tests\Data_Events
 */

namespace Newspack\Tests\Data_Events;

/**
 * Tests for the reader_deleted and reader_delete_sync Data Events listeners.
 *
 * Verifies that the deletion listeners emit `email` as a top-level primitive
 * in their payloads, matching peer listeners (reader_logged_in, reader_verified)
 * and consumable by the ESP-sync handlers in class-contact-sync-connector.php.
 */
class Newspack_Test_Data_Events_Listeners_Deletion extends \WP_UnitTestCase {

	/**
	 * Captured dispatch payloads keyed by action name.
	 *
	 * @var array<string,array>
	 */
	private $captured = [];

	/**
	 * The dispatch-capture callback. Stored so tear_down can remove the listener
	 * (WP hooks persist for the whole PHPUnit process; without removal, every
	 * set_up would stack another closure that runs in later data-event tests).
	 *
	 * @var callable|null
	 */
	private $capture_callback = null;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->captured         = [];
		$this->capture_callback = function( $action_name, $timestamp, $data, $client_id ) {
			$this->captured[ $action_name ] = $data;
		};
		add_action( 'newspack_data_event_dispatch', $this->capture_callback, 10, 4 );
	}

	/**
	 * Tear down: remove the listener so it doesn't leak into later tests.
	 */
	public function tear_down() {
		if ( $this->capture_callback ) {
			remove_action( 'newspack_data_event_dispatch', $this->capture_callback, 10 );
			$this->capture_callback = null;
		}
		parent::tear_down();
	}

	/**
	 * Test that the reader_deleted payload includes email as a top-level string.
	 */
	public function test_reader_deleted_payload_has_email_as_primitive() {
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@example.com' ] );
		// Mark as reader so the listener doesn't return early.
		update_user_meta( $user_id, \Newspack\Reader_Activation::READER, true );

		wp_delete_user( $user_id );

		$this->assertArrayHasKey( 'reader_deleted', $this->captured );
		$this->assertSame( 'reader@example.com', $this->captured['reader_deleted']['email'] ?? null );
		$this->assertArrayNotHasKey( 'user', $this->captured['reader_deleted'] );
	}

	/**
	 * Test that the reader_delete_sync payload includes email as a top-level string.
	 */
	public function test_reader_delete_sync_payload_has_email_as_primitive() {
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader2@example.com' ] );
		update_user_meta( $user_id, \Newspack\Reader_Activation::READER, true );

		wp_delete_user( $user_id );

		$this->assertArrayHasKey( 'reader_delete_sync', $this->captured );
		$this->assertSame( 'reader2@example.com', $this->captured['reader_delete_sync']['email'] ?? null );
		$this->assertArrayNotHasKey( 'user', $this->captured['reader_delete_sync'] );
	}
}
