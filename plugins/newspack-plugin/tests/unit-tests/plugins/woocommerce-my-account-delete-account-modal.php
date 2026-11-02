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

	/**
	 * The request and the email-sent result are steps of one dialog, so the request
	 * can turn into its result.
	 */
	public function test_request_and_email_sent_are_steps_of_one_dialog() {
		$modal = $this->render_delete_account_modal();

		$this->assertSame( 1, substr_count( $modal, '<dialog' ) );
		$this->assertStringContainsString( 'data-step="request"', $modal );
		$this->assertMatchesRegularExpression( '/class="newspack-ui__modal__step[^"]*" data-step="email-sent" inert>/', $modal );
		$this->assertStringContainsString( '&quot;next&quot;:&quot;email-sent&quot;', $modal );
	}

	/**
	 * The request only sends an email, so Delete account takes the outline red after
	 * Cancel and nothing claims focus over the alternatives.
	 */
	public function test_request_leads_with_cancel_and_outline_delete() {
		$modal   = $this->render_delete_account_modal();
		$request = substr( $modal, 0, strpos( $modal, 'data-step="email-sent"' ) );

		$this->assertStringNotContainsString( 'data-autofocus', $modal );
		$this->assertStringContainsString( 'newspack-ui__button--destructive newspack-ui__button--outline', $request );
		$this->assertLessThan( strpos( $request, '<span>Delete account</span>' ), strpos( $request, '<span>Cancel</span>' ) );
	}

	/**
	 * The request lays its actions out in a row when it is medium, while the small
	 * email-sent step keeps its Continue button full width.
	 */
	public function test_actions_follow_each_step_size() {
		$continue = '/class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--primary newspack-ui__modal__close"/';

		Reader_Data::set_is_donor( time(), [ 'user_id' => $this->reader_id ] );
		$modal      = $this->render_delete_account_modal();
		$split      = strpos( $modal, 'data-step="email-sent"' );
		$request    = substr( $modal, 0, $split );
		$email_sent = substr( $modal, $split );
		$this->assertStringContainsString( 'newspack-ui__modal--medium" data-step="request"', $modal );
		$this->assertStringContainsString( 'newspack-ui__stack--justify-end', $request );
		$this->assertStringContainsString( 'newspack-ui__modal--small" data-step="email-sent"', $modal );
		$this->assertStringNotContainsString( 'newspack-ui__stack--justify-end', $email_sent );
		$this->assertMatchesRegularExpression( $continue, $email_sent );

		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $this->reader_id ] );
		$modal   = $this->render_delete_account_modal();
		$request = substr( $modal, 0, strpos( $modal, 'data-step="email-sent"' ) );
		$this->assertStringContainsString( 'newspack-ui__modal--small" data-step="request"', $modal );
		$this->assertStringNotContainsString( 'newspack-ui__stack--justify-end', $request );
	}
}
