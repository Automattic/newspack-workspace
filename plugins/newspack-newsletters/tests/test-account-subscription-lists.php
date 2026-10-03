<?php
/**
 * Class Test Account Subscription Lists
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_Lists;

/**
 * Lists a reader can join from the My Account newsletter form.
 */
class Account_Subscription_Lists_Test extends WP_UnitTestCase {
	/**
	 * Lists passed to `newspack_newsletters_pre_add_contact`, or null if it never fired.
	 *
	 * @var string[]|false|null
	 */
	private $captured_lists;

	/**
	 * Public ID of a list offered to readers.
	 *
	 * @var string
	 */
	private $offered_list;

	/**
	 * Public ID of a list the publisher keeps inactive.
	 *
	 * @var string
	 */
	private $inactive_list;

	/**
	 * Set up a provider, one active and one inactive list, and a verified reader.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );

		$offered = Subscription_Lists::get_or_create_remote_list(
			[
				'id'    => 'offered-list',
				'title' => 'Offered',
			]
		);
		$offered->update( [ 'active' => true ] );
		$inactive = Subscription_Lists::get_or_create_remote_list(
			[
				'id'    => 'inactive-list',
				'title' => 'Inactive',
			]
		);
		$inactive->update( [ 'active' => false ] );
		$this->offered_list  = $offered->get_public_id();
		$this->inactive_list = $inactive->get_public_id();
		Newspack_Newsletters_Subscription::reset_lists_config_cache();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		add_filter( 'newspack_newsletters_is_email_verified', '__return_true' );
		add_action( 'newspack_newsletters_pre_add_contact', [ $this, 'capture_lists' ] );
	}

	/**
	 * Remove the filters and the submitted form.
	 */
	public function tear_down() {
		remove_filter( 'newspack_newsletters_is_email_verified', '__return_true' );
		remove_action( 'newspack_newsletters_pre_add_contact', [ $this, 'capture_lists' ] );
		unset( $_POST['lists'], $_POST[ Newspack_Newsletters_Subscription::SUBSCRIPTION_UPDATE ] );
		parent::tear_down();
	}

	/**
	 * Stop at `newspack_newsletters_pre_add_contact` and report the lists it received.
	 *
	 * @param string[]|false $lists Lists passed to the hook.
	 * @throws RuntimeException Always, so no provider call or redirect follows.
	 */
	public function capture_lists( $lists ) {
		$this->captured_lists = $lists;
		throw new RuntimeException( 'captured' );
	}

	/**
	 * Submit the My Account form with the given lists and return what reached the hook.
	 *
	 * @param string[] $lists Submitted list IDs.
	 * @return string[]|false|null Lists passed on, or null if nothing reached the hook.
	 */
	private function submit( array $lists ) {
		$_POST['lists'] = $lists;
		$_POST[ Newspack_Newsletters_Subscription::SUBSCRIPTION_UPDATE ] = wp_create_nonce( Newspack_Newsletters_Subscription::SUBSCRIPTION_UPDATE );
		$this->captured_lists = null;
		try {
			Newspack_Newsletters_Subscription::process_subscription_update();
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		return $this->captured_lists;
	}

	/**
	 * A first subscription joins only the lists the reader is offered.
	 */
	public function test_first_subscription_joins_only_offered_lists() {
		// This case covers the first-subscription branch, so the reader must not already be a contact.
		$this->assertFalse( Newspack_Newsletters_Subscription::is_newsletter_subscriber( wp_get_current_user()->user_email ) );
		$lists = $this->submit( [ $this->offered_list, $this->inactive_list ] );
		$this->assertSame( [ $this->offered_list ], $lists );
	}
}
