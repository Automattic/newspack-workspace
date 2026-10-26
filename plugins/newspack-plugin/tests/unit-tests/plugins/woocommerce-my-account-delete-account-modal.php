<?php
/**
 * Tests for the My Account delete-account modal.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account_UI_V1;
use Newspack\Reader_Activation;
use Newspack\Reader_Data;

/**
 * Test what the delete-account modal tells a reader about their donations.
 *
 * @group My_Account_Delete_Account_Modal
 */
class Newspack_Test_My_Account_Delete_Account_Modal extends WP_UnitTestCase {

	/**
	 * The signed-in reader.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Sign in a reader for each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $this->reader_id, Reader_Activation::READER, true );
		wp_set_current_user( $this->reader_id );
	}

	/**
	 * Sign the reader out.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The modal markup as the signed-in reader sees it.
	 *
	 * @return string Modal markup.
	 */
	private function render_delete_account_modal() {
		ob_start();
		My_Account_UI_V1::delete_account_modal();
		return ob_get_clean();
	}

	/**
	 * A former donor has no recurring donation left, so the modal neither warns
	 * about cancelling one nor points them at their subscriptions.
	 */
	public function test_former_donor_is_not_warned_about_recurring_payments() {
		Reader_Data::set_is_donor( time(), [ 'user_id' => $this->reader_id ] );
		$donor_modal = $this->render_delete_account_modal();
		$this->assertStringContainsString( 'recurring payments will be cancelled', $donor_modal );
		$this->assertStringContainsString( 'Manage subscriptions', $donor_modal );

		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $this->reader_id ] );
		$former_donor_modal = $this->render_delete_account_modal();
		$this->assertStringNotContainsString( 'recurring payments will be cancelled', $former_donor_modal );
		$this->assertStringNotContainsString( 'Manage subscriptions', $former_donor_modal );
	}
}
