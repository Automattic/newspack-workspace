<?php
/**
 * Tests for Group_Subscription_Invite link-invite acceptance.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Settings;

/**
 * Test the link-invite acceptance flow. See NPPD-1593 (B2): a non-fatal failure
 * (current user is not a Reader Activation reader, so update_members() returns an
 * array with an empty members_added) must not fatal on get_error_message().
 */
class Test_Group_Subscription_Invite extends WP_UnitTestCase {

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Include WC mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset state between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
	}

	/**
	 * Reset state between tests.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		wp_set_current_user( 0 );
		unset( $_GET['action'], $_GET['subscription'], $_GET['manager'], $_GET['key'] );
		parent::tear_down();
	}

	/**
	 * Create a user, optionally flagged as a Reader Activation reader.
	 *
	 * @param bool $is_reader Whether to mark the user as a reader.
	 * @return int User ID.
	 */
	private function create_user( bool $is_reader ): int {
		// A reader is a subscriber flagged with the reader meta. The non-reader is an editor:
		// is_user_reader() (non-strict) treats subscribers/customers as readers via reader roles,
		// so a genuinely non-reader user must hold a non-reader role.
		$user_id = wp_insert_user(
			[
				'user_login' => 'user-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'user-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => $is_reader ? 'subscriber' : 'editor',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		$this->user_ids[] = $user_id;
		if ( $is_reader ) {
			update_user_meta( $user_id, '_newspack_reader', true );
		}
		return $user_id;
	}

	/**
	 * Drive process_link_invite_request() while capturing the newspack_log events it emits, and
	 * unwinding at the wp_safe_redirect() so the handler's exit does not stop the test. Asserting on
	 * the logged event (rather than the redirect URL) is deterministic: the redirect target depends
	 * on wc_get_account_endpoint_url()/host validation, which varies by environment.
	 *
	 * @return string[] The newspack_log event codes emitted during the request.
	 */
	private function capture_link_invite_log_events(): array {
		$events   = [];
		$log      = function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		$redirect = function ( $location ) {
			// Unwind before the handler's exit, mimicking a completed redirect.
			throw new \RuntimeException( 'redirected' );
		};
		add_action( 'newspack_log', $log, 1 );
		add_filter( 'wp_redirect', $redirect, 1 );
		try {
			Group_Subscription_Invite::process_link_invite_request();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage(), 'Only the redirect-capture unwind is expected here.' );
		} finally {
			remove_action( 'newspack_log', $log, 1 );
			remove_filter( 'wp_redirect', $redirect, 1 );
		}
		return $events;
	}

	/**
	 * A logged-in non-reader following a valid link invite should be redirected with a
	 * link_failed notice, not trigger a PHP fatal on get_error_message().
	 */
	public function test_non_reader_link_acceptance_does_not_fatal() {
		$owner_id     = $this->create_user( true );
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );

		$invite = Group_Subscription_Invite::generate_link_invite( $subscription, $owner_id );
		$this->assertIsArray( $invite, 'The fixture should generate a valid link invite.' );

		// The current user is logged in but NOT a reader, so update_members() returns an array
		// with an empty members_added — the exact branch that fataled before the fix.
		$non_reader_id = $this->create_user( false );
		wp_set_current_user( $non_reader_id );

		$_GET['action']       = Group_Subscription_Invite::LINK_QUERY_ARG;
		$_GET['subscription'] = (string) $subscription->get_id();
		$_GET['manager']      = (string) $owner_id;
		$_GET['key']          = $invite['key'];

		$log_events = $this->capture_link_invite_log_events();

		// Reaching the link-failed log (instead of fataling on get_error_message()) is the fix.
		$this->assertContains(
			'newspack_group_subscription_invite_link_failed',
			$log_events,
			'A non-reader accepting a valid link should hit the link-failed path without a fatal.'
		);
		$this->assertNotContains(
			$subscription->get_id(),
			Group_Subscription::get_group_subscriptions_for_user( $non_reader_id, true ),
			'The non-reader should not have been added to the group.'
		);
	}

	/**
	 * Accepting an EMAIL invite when the account is not a reader must fail and leave the
	 * invitation intact, rather than silently consuming it and reporting success.
	 */
	public function test_email_invite_acceptance_fails_for_non_reader_without_consuming_invite() {
		$owner_id     = $this->create_user( true );
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );

		// Invite an email that has no account yet (so generate_invite's non-reader guard passes).
		$email  = 'invitee-' . wp_generate_password( 6, false ) . '@test.com';
		$invite = Group_Subscription_Invite::generate_invite( $subscription, $email );
		$this->assertIsArray( $invite, 'The fixture should create an email invite.' );
		$key = array_key_first( Group_Subscription_Invite::get_invites( $subscription ) );

		// By accept time an account exists for the email, but it is not a reader.
		$invitee_id = $this->create_user( false );
		wp_update_user(
			[
				'ID'         => $invitee_id,
				'user_email' => $email,
			]
		);

		$result = Group_Subscription_Invite::accept_invite( $subscription, $key, $email );

		$this->assertWPError( $result, 'Accepting as a non-reader should fail rather than silently succeed.' );
		$this->assertSame( 'newspack_group_subscription_invite_not_added', $result->get_error_code(), 'The failure should be the not-added error, not a success.' );
		$this->assertNotNull(
			Group_Subscription_Invite::get_invite_by_key( $subscription, $key ),
			'The invitation should remain intact for retry, not be consumed.'
		);
	}

	/**
	 * If the invitee was already added to the group before accepting (so update_members() returns
	 * an empty members_added because there is nothing to add), acceptance must still succeed and
	 * cancel the now-stale invite rather than reporting a failure.
	 */
	public function test_email_invite_acceptance_succeeds_for_existing_member() {
		$owner_id     = $this->create_user( true );
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );

		// Invite a reader, then add them to the group before they accept (e.g. a manual add).
		$member_id = $this->create_user( true );
		$email     = get_userdata( $member_id )->user_email;
		$invite    = Group_Subscription_Invite::generate_invite( $subscription, $email );
		$this->assertIsArray( $invite, 'The fixture should create an email invite.' );
		$key = array_key_first( Group_Subscription_Invite::get_invites( $subscription ) );
		Group_Subscription::update_members( $subscription, [ $member_id ] );

		$result = Group_Subscription_Invite::accept_invite( $subscription, $key, $email );

		$this->assertTrue( $result, 'Accepting when already a member should succeed.' );
		$this->assertNull(
			Group_Subscription_Invite::get_invite_by_key( $subscription, $key ),
			'The now-stale invite should be cancelled so it stops counting toward the member limit.'
		);
	}
}
