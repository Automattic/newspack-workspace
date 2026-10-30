<?php
/**
 * Tests for the `newspack_group_subscription_invite_created` action.
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_API;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_MyAccount;
use Newspack\Group_Subscription_Settings;
use Newspack\Group_Subscription_Teams_Invite;

require_once dirname( __DIR__, 4 ) . '/mocks/newsletters-mocks.php';

/**
 * Code that reacted to new WooCommerce Teams invitations needs a hook to move to
 * once Teams is gone. These tests pin down when it fires, and what it carries.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Group_Subscription_Invite_Created_Action extends WP_UnitTestCase {

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Post IDs to clean up.
	 *
	 * @var int[]
	 */
	private $post_ids = [];

	/**
	 * The invitation email config callback, when a test registered one.
	 *
	 * @var callable|null
	 */
	private $email_config_filter = null;

	/**
	 * Every firing of the action, with the state it fired into.
	 *
	 * @var array[]
	 */
	private $fired = [];

	/**
	 * Load the WooCommerce mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
		// The My Account form handler reads POST data through Newspack\filter_input(),
		// which is empty under PHPUnit without this shim.
		require_once dirname( __DIR__, 4 ) . '/mocks/filter-input-mock.php';
	}

	/**
	 * Reset state and start recording the action.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		Group_Subscription::reset_cache();
		reset_phpmailer_instance();
		wp_set_current_user( 0 );
		$this->fired = [];
		add_action(
			'newspack_group_subscription_invite_created',
			function ( $subscription, $email, $inviter_id, $source, $email_sent ) {
				$this->fired[] = [
					'args'          => [ $subscription->get_id(), $email, $inviter_id, $source, $email_sent ],
					'raw_args'      => func_get_args(),
					'stored_emails' => array_column( Group_Subscription_Invite::get_invites( $subscription ), 'email' ),
					'mail_count'    => count( tests_retrieve_phpmailer_instance()->mock_sent ),
				];
			},
			10,
			5
		);
	}

	/**
	 * Clean up fixtures.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		if ( $this->email_config_filter ) {
			remove_filter( 'newspack_email_configs', $this->email_config_filter );
			$this->email_config_filter = null;
		}
		Emails::reset_email_configs_cache();
		remove_filter( 'newspack_guest_author_mail_guard_active', '__return_false' );
		reset_phpmailer_instance();
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->user_ids = [];
		$this->post_ids = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a reader user.
	 *
	 * @param string $role Role to give the user.
	 *
	 * @return int User ID.
	 */
	private function create_user( string $role = 'subscriber' ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'user-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'user-' . wp_generate_password( 6, false ) . '@example.test',
				'role'       => $role,
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		$this->user_ids[] = $user_id;
		if ( 'subscriber' === $role ) {
			update_user_meta( $user_id, '_newspack_reader', true );
		}
		return $user_id;
	}

	/**
	 * Create a group subscription.
	 *
	 * @param int    $owner_id Owner user ID.
	 * @param string $status   Subscription status.
	 * @param int    $limit    Seat limit; 0 for unlimited.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id, string $status = 'active', int $limit = 0 ): WC_Subscription {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => $status,
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		if ( $limit ) {
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', $limit );
		}
		$subscription->save();
		return $subscription;
	}

	/**
	 * Register and publish the invitation email, so a send reaches wp_mail().
	 * Without it every send returns false before mailing anything.
	 */
	private function make_invite_email_sendable(): void {
		add_filter( 'newspack_guest_author_mail_guard_active', '__return_false' );
		$this->email_config_filter = function ( $configs ) {
			return Group_Subscription_Invite::add_email_config( $configs );
		};
		add_filter( 'newspack_email_configs', $this->email_config_filter );
		Emails::reset_email_configs_cache();
		$this->post_ids[] = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Group subscription invitation (test)',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META         => Group_Subscription_Invite::EMAIL_TYPE,
					\Newspack_Newsletters::EMAIL_HTML_META => '<p>*INVITE_URL*</p>',
				],
			]
		);
	}

	/**
	 * Submit the group's My Account invite form for $email, stopping at the redirect
	 * that ends the request.
	 *
	 * @param int    $subscription_id The group subscription.
	 * @param string $email           The address typed into the form.
	 *
	 * @throws \Exception Re-throws anything other than the redirect interception.
	 */
	private function submit_my_account_invite_form( int $subscription_id, string $email ): void {
		$original_request_method = $_SERVER['REQUEST_METHOD'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$_POST                     = [
			'subscription_id'                          => (string) $subscription_id,
			'newspack-group-subscription-invite-email' => $email,
			'_wpnonce'                                 => wp_create_nonce( Group_Subscription_MyAccount::INVITE_NONCE_ACTION ),
		];
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$redirect = function () {
			throw new \Exception( 'redirect_intercepted' );
		};
		add_filter( 'wp_redirect', $redirect, 1 );
		try {
			Group_Subscription_MyAccount::handle_invite_member();
		} catch ( \Exception $e ) {
			if ( 'redirect_intercepted' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $redirect, 1 );
			$_POST    = [];
			$_REQUEST = [];
			if ( null === $original_request_method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $original_request_method;
			}
		}
	}

	/**
	 * The action fires once, after the invite is stored and the email has gone out,
	 * and names who sent it and from where.
	 */
	public function test_fires_once_after_the_invite_is_stored_and_emailed() {
		$this->make_invite_email_sendable();
		$owner_id     = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );

		$invite = Group_Subscription_Invite::generate_invite( $subscription, 'invitee@example.test', true, 'my_account' );

		$this->assertIsArray( $invite );
		$this->assertCount( 1, $this->fired, 'One invite should fire the action once.' );
		$this->assertSame( [ $subscription->get_id(), 'invitee@example.test', $owner_id, 'my_account', true ], $this->fired[0]['args'] );
		$this->assertInstanceOf( WC_Subscription::class, $this->fired[0]['raw_args'][0], 'Listeners should receive the subscription object.' );
		$this->assertSame( [ 'invitee@example.test' ], $this->fired[0]['stored_emails'], 'The invite should already be stored when the action fires.' );
		$this->assertSame( 1, $this->fired[0]['mail_count'], 'The email should already have been sent when the action fires.' );
	}

	/**
	 * The invite key is a bearer credential: with the email it is enough to accept
	 * the invite, so it must never reach listeners, whole or inside another value.
	 */
	public function test_never_passes_the_invite_key() {
		$owner_id     = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );
		// A listener that accepts more arguments than the action passes, so a key
		// added as an extra argument reaches it rather than being cut off.
		$received = null;
		add_action(
			'newspack_group_subscription_invite_created',
			function () use ( &$received ) {
				$received = func_get_args();
			},
			10,
			10
		);

		Group_Subscription_Invite::generate_invite( $subscription, 'invitee@example.test', true, 'my_account' );

		$keys = array_keys( Group_Subscription_Invite::get_invites( $subscription ) );
		$this->assertCount( 1, $keys );
		$this->assertCount( 5, $received, 'The action should pass exactly five arguments.' );
		// The subscription (argument 0) carries the invites meta, keys included, by design.
		$this->assertStringNotContainsString( $keys[0], wp_json_encode( array_slice( $received, 1 ) ), 'No argument after the subscription should contain the invite key.' );
	}

	/**
	 * An invite whose email fails is still stored and can still be accepted or
	 * resent, so the action fires and reports the failure.
	 */
	public function test_fires_when_the_email_fails() {
		$owner_id     = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );

		// The invitation email is not registered here, so the send returns false.
		$invite = Group_Subscription_Invite::generate_invite( $subscription, 'invitee@example.test', true, 'my_account' );

		$this->assertFalse( $invite['email_sent'] );
		$this->assertCount( 1, $this->fired );
		$this->assertFalse( $this->fired[0]['args'][4], 'The action should report that the email did not go out.' );
	}

	/**
	 * Resending replaces the invite with a new one, so it fires again.
	 */
	public function test_fires_again_on_resend() {
		$owner_id     = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );

		Group_Subscription_Invite::generate_invite( $subscription, 'invitee@example.test', true, 'my_account' );
		Group_Subscription_Invite::generate_invite( $subscription, 'invitee@example.test', true, 'my_account' );

		$this->assertCount( 2, $this->fired );
		$this->assertCount( 1, Group_Subscription_Invite::get_invites( $subscription ), 'The resend should replace the first invite.' );
	}

	/**
	 * A caller that doesn't name a source is reported as `other`.
	 */
	public function test_source_defaults_to_other() {
		$owner_id     = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );

		Group_Subscription_Invite::generate_invite( $subscription, 'invitee@example.test' );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 'other', $this->fired[0]['args'][3] );
	}

	/**
	 * An invite sent from the group's My Account page fires once, as `my_account`,
	 * attributed to the manager who sent it.
	 */
	public function test_my_account_invite_fires_once() {
		$owner_id     = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );

		$this->submit_my_account_invite_form( $subscription->get_id(), 'form-invitee@example.test' );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( [ $subscription->get_id(), 'form-invitee@example.test', $owner_id, 'my_account', false ], $this->fired[0]['args'] );
	}

	/**
	 * An invite the API issues fires once, as `api`, attributed to the current user.
	 */
	public function test_api_invite_fires_once() {
		$owner_id     = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		wp_set_current_user( $owner_id );
		$request = new WP_REST_Request( 'POST', '/newspack-group-subscription/v1/invite' );
		$request->set_param( 'subscription_id', $subscription->get_id() );
		$request->set_param( 'email', 'api-invitee@example.test' );

		Group_Subscription_API::api_invite( $request );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( [ $subscription->get_id(), 'api-invitee@example.test', $owner_id, 'api', false ], $this->fired[0]['args'] );
	}

	/**
	 * A Teams invitation that predates the migration becomes an invite when its link
	 * is followed. It fires as `migration`, unemailed and with no inviter, since Teams'
	 * own hook already reported it with its sender. A second click reuses the stored
	 * invite and stays quiet.
	 */
	public function test_migrated_teams_invitation_fires_once() {
		$this->make_invite_email_sendable();
		$owner_id     = $this->create_user();
		$team_id      = wp_insert_post(
			[
				'post_type'   => 'wc_memberships_team',
				'post_status' => 'publish',
				'post_title'  => 'Team',
				'post_author' => $owner_id,
			]
		);
		$subscription = $this->create_group_subscription( $owner_id );
		$subscription->update_meta_data( Group_Subscription::MIGRATED_TEAM_ID_META_KEY, $team_id );
		$subscription->save();
		$this->post_ids[] = $team_id;
		$this->post_ids[] = wp_insert_post(
			[
				'post_type'     => 'wc_team_invitation',
				'post_status'   => 'wcmti-pending',
				'post_title'    => 'teams-invitee@example.test',
				'post_parent'   => $team_id,
				'post_password' => 'tok-pending',
			]
		);
		// Whoever follows the link is not the inviter, even when signed in.
		wp_set_current_user( $this->create_user() );

		$first  = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-pending' );
		$second = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-pending' );

		$this->assertIsString( $first );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->fired, 'Only the click that created the invite should fire.' );
		$this->assertSame( [ $subscription->get_id(), 'teams-invitee@example.test', 0, 'migration', false ], $this->fired[0]['args'] );
		$this->assertSame( 0, $this->fired[0]['mail_count'], 'A migrated invite is not emailed.' );
		$stored = array_values( Group_Subscription_Invite::get_invites( $subscription ) );
		$this->assertSame( 0, (int) $stored[0]['added_by'], 'The stored invite should record no sender either.' );
	}

	/**
	 * Invites that are refused never existed, so nothing fires.
	 */
	public function test_refused_invites_do_not_fire() {
		$owner_id = $this->create_user();
		wp_set_current_user( $owner_id );

		// Already a member.
		$member_id    = $this->create_user();
		$subscription = $this->create_group_subscription( $owner_id );
		Group_Subscription::update_members( $subscription, [ $member_id ] );
		$this->assertWPError( Group_Subscription_Invite::generate_invite( $subscription, get_userdata( $member_id )->user_email ) );

		// An account that can't join a group.
		$editor_id = $this->create_user( 'editor' );
		$this->assertWPError( Group_Subscription_Invite::generate_invite( $subscription, get_userdata( $editor_id )->user_email ) );

		// A malformed address, as the My Account form passes it through.
		$this->assertWPError( Group_Subscription_Invite::generate_invite( $subscription, 'not-an-email' ) );

		// No seats left: the owner and one member fill a two-seat group.
		$full = $this->create_group_subscription( $owner_id, 'active', 2 );
		Group_Subscription::update_members( $full, [ $member_id ] );
		$this->assertWPError( Group_Subscription_Invite::generate_invite( $full, 'one-too-many@example.test' ) );

		// A group that is no longer active.
		$cancelled = $this->create_group_subscription( $owner_id, 'cancelled' );
		$this->assertWPError( Group_Subscription_Invite::generate_invite( $cancelled, 'late@example.test' ) );

		$this->assertSame( [], $this->fired, 'A refused invite should not fire the action.' );
	}
}
