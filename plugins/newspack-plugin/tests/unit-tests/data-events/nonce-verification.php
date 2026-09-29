<?php
/**
 * Tests nonce verification for the data events dispatch endpoint.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events;

/**
 * Nonce verification guards wp_ajax_nopriv_newspack_data_event, whose handlers write
 * reader data for a caller-supplied user_id. The nonce is the only credential on that
 * endpoint, so it has to fail closed when there is no stored nonce to compare against --
 * otherwise a site that has not yet dispatched an event (the option is created lazily on
 * first dispatch) accepts an empty nonce from anyone.
 *
 * @group data-events
 * @group nonce-verification
 */
class Test_Data_Events_Nonce_Verification extends WP_UnitTestCase {

	/**
	 * Set up. Start from a site that has never dispatched an event.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Data_Events::NONCE_OPTION );
		delete_option( Data_Events::NONCE_EXPIRATION_OPTION );
		delete_option( Data_Events::PREVIOUS_NONCE_OPTION );
		delete_option( Data_Events::PREVIOUS_NONCE_EXPIRATION_OPTION );
	}

	/**
	 * An empty nonce must not authenticate against an unset stored nonce.
	 */
	public function test_empty_nonce_is_rejected_when_no_nonce_is_stored() {
		$this->assertFalse(
			Data_Events::verify_nonce( '' ),
			'An empty nonce authenticated against an unset stored nonce -- anyone can dispatch.'
		);
	}

	/**
	 * The same, via the stored option explicitly set to an empty string.
	 */
	public function test_empty_nonce_is_rejected_when_stored_nonce_is_empty() {
		update_option( Data_Events::NONCE_OPTION, '' );

		$this->assertFalse(
			Data_Events::verify_nonce( '' ),
			'An empty nonce authenticated against an empty stored nonce.'
		);
	}

	/**
	 * An empty nonce must not slip through the grace-period branch either.
	 */
	public function test_empty_nonce_is_rejected_during_grace_period() {
		update_option( Data_Events::NONCE_OPTION, 'a-real-nonce' );
		update_option( Data_Events::PREVIOUS_NONCE_OPTION, '' );
		update_option( Data_Events::PREVIOUS_NONCE_EXPIRATION_OPTION, time() + 3600 );

		$this->assertFalse(
			Data_Events::verify_nonce( '' ),
			'An empty nonce authenticated via the previous-nonce grace period.'
		);
	}

	/**
	 * A non-string nonce must be rejected rather than raising a TypeError.
	 */
	public function test_non_string_nonce_is_rejected() {
		update_option( Data_Events::NONCE_OPTION, 'a-real-nonce' );

		$this->assertFalse( Data_Events::verify_nonce( null ), 'A null nonce was not rejected.' );
		$this->assertFalse( Data_Events::verify_nonce( [] ), 'An array nonce was not rejected.' );
	}

	/**
	 * The legitimate path must keep working: the current nonce verifies.
	 */
	public function test_current_nonce_verifies() {
		$nonce = Data_Events::get_nonce();

		$this->assertTrue( Data_Events::verify_nonce( $nonce ), 'The current nonce should verify.' );
	}

	/**
	 * A wrong nonce is rejected.
	 */
	public function test_wrong_nonce_is_rejected() {
		Data_Events::get_nonce();

		$this->assertFalse( Data_Events::verify_nonce( 'not-the-nonce' ), 'A wrong nonce should be rejected.' );
	}

	/**
	 * The previous nonce must still verify inside the grace period, so events dispatched
	 * just before a rotation are not dropped.
	 */
	public function test_previous_nonce_verifies_during_grace_period() {
		$initial_nonce = Data_Events::get_nonce();

		// Force a rotation; the initial nonce becomes the previous one.
		update_option( Data_Events::NONCE_EXPIRATION_OPTION, time() - 1 );
		$new_nonce = Data_Events::get_nonce();

		$this->assertNotSame( $initial_nonce, $new_nonce, 'The nonce should have rotated.' );
		$this->assertTrue(
			Data_Events::verify_nonce( $initial_nonce ),
			'The previous nonce should still verify during the grace period.'
		);
	}
}
