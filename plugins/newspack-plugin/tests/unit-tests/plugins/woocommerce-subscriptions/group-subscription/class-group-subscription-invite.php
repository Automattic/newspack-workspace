<?php
/**
 * Tests for Group_Subscription_Invite request handling.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription_Invite;

/**
 * Tests the group subscription invite request handler.
 */
class Test_Group_Subscription_Invite extends WP_UnitTestCase {

	const REDIRECTED = 'group-invite-test-redirected'; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	public static function set_up_before_class() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		wp_set_current_user( 0 );
	}

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		unset( $_GET['action'], $_GET['key'], $_GET['email'], $_GET['subscription'] );
		parent::tear_down();
	}

	/**
	 * Run process_invite_request(), converting its terminal redirect into a
	 * catchable signal so the test can continue.
	 *
	 * Only the redirect is treated as expected; any other exception propagates,
	 * and the absence of a redirect fails the test.
	 *
	 * @throws \RuntimeException If the request fails for a reason other than the redirect.
	 */
	private function run_invite_request() {
		$redirect = function () {
			throw new \RuntimeException( self::REDIRECTED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_redirect', $redirect, 1 );
		try {
			Group_Subscription_Invite::process_invite_request();
			$this->fail( 'Expected the invite request to end in a redirect.' );
		} catch ( \RuntimeException $e ) {
			if ( self::REDIRECTED !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			remove_filter( 'wp_redirect', $redirect, 1 );
		}
	}

	/**
	 * An invalid invite key must not create a reader account for a new email.
	 */
	public function test_invalid_key_does_not_create_account() {
		$email = 'nppm2966-invitee@example.test';
		self::assertFalse( get_user_by( 'email', $email ), 'Precondition: no account for the email.' );

		$_GET['action']       = Group_Subscription_Invite::QUERY_ARG;
		$_GET['key']          = 'this-key-is-not-valid';
		$_GET['email']        = $email;
		$_GET['subscription'] = '999';

		$this->run_invite_request();

		self::assertFalse(
			get_user_by( 'email', $email ),
			'An invalid invite key must not create a reader account.'
		);
	}

	/**
	 * A valid invite still creates a reader account for a new invitee.
	 */
	public function test_valid_key_creates_account() {
		$email        = 'nppm2966-valid@example.test';
		$key          = 'valid-invite-key';
		$subscription = wcs_create_subscription(
			[
				'id'     => 10,
				'status' => 'active',
				'meta'   => [
					'newspack_group_subscription_invites' => [
						$key => [
							'email'      => $email,
							'expiration' => time() + HOUR_IN_SECONDS,
						],
					],
				],
			]
		);
		self::assertFalse( get_user_by( 'email', $email ), 'Precondition: no account for the email.' );

		$_GET['action']       = Group_Subscription_Invite::QUERY_ARG;
		$_GET['key']          = $key;
		$_GET['email']        = $email;
		$_GET['subscription'] = (string) $subscription->get_id();

		$this->run_invite_request();

		self::assertInstanceOf(
			WP_User::class,
			get_user_by( 'email', $email ),
			'A valid invite creates a reader account for a new invitee.'
		);
	}

	/**
	 * A wrong key against a real active subscription is rejected at the invite-key
	 * lookup (past the subscription check) and creates no account.
	 */
	public function test_wrong_key_with_active_subscription_does_not_create_account() {
		$email        = 'nppm2966-wrongkey@example.test';
		$subscription = wcs_create_subscription(
			[
				'id'     => 20,
				'status' => 'active',
				'meta'   => [
					'newspack_group_subscription_invites' => [
						'the-real-key' => [
							'email'      => $email,
							'expiration' => time() + HOUR_IN_SECONDS,
						],
					],
				],
			]
		);
		self::assertFalse( get_user_by( 'email', $email ), 'Precondition: no account for the email.' );

		$_GET['action']       = Group_Subscription_Invite::QUERY_ARG;
		$_GET['key']          = 'not-the-real-key';
		$_GET['email']        = $email;
		$_GET['subscription'] = (string) $subscription->get_id();

		$this->run_invite_request();

		self::assertFalse(
			get_user_by( 'email', $email ),
			'A wrong key against an active subscription must not create an account.'
		);
	}

	/**
	 * A valid key whose invite is for a different email is rejected at the email-match
	 * check and creates no account for the requesting address.
	 */
	public function test_mismatched_email_with_valid_key_does_not_create_account() {
		$invited_email  = 'nppm2966-invited@example.test';
		$attacker_email = 'nppm2966-attacker@example.test';
		$key            = 'the-real-key';
		$subscription   = wcs_create_subscription(
			[
				'id'     => 21,
				'status' => 'active',
				'meta'   => [
					'newspack_group_subscription_invites' => [
						$key => [
							'email'      => $invited_email,
							'expiration' => time() + HOUR_IN_SECONDS,
						],
					],
				],
			]
		);
		self::assertFalse( get_user_by( 'email', $attacker_email ), 'Precondition: no account for the email.' );

		$_GET['action']       = Group_Subscription_Invite::QUERY_ARG;
		$_GET['key']          = $key;
		$_GET['email']        = $attacker_email;
		$_GET['subscription'] = (string) $subscription->get_id();

		$this->run_invite_request();

		self::assertFalse(
			get_user_by( 'email', $attacker_email ),
			'A valid key with a mismatched email must not create an account for the requesting address.'
		);
	}
}
