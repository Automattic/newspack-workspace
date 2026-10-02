<?php
/**
 * Class TestSubscriptionChangedAccount
 *
 * @package Newspack_Network
 */

use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Utils\Users;

/**
 * How a site records a subscription from another site when it has no account for the reader yet.
 */
class TestSubscriptionChangedAccount extends WP_UnitTestCase {

	/**
	 * A subscription event from another site.
	 *
	 * @param string $email Subscriber email.
	 * @return Subscription_Changed
	 */
	private function subscription( $email ) {
		return new Subscription_Changed(
			'https://a.example.test',
			[
				'email'        => $email,
				'user_id'      => 3,
				'id'           => 42,
				'status_after' => 'active',
				'products'     => [
					7 => [
						'id'   => 7,
						'name' => 'Premium',
						'slug' => 'premium',
					],
				],
			],
			time()
		);
	}

	/**
	 * The event creates the reader's account here, as a membership event does, and records the subscription on it.
	 */
	public function test_event_for_unknown_email_creates_the_account() {
		$this->subscription( 'newcomer@example.test' )->process_in_node();

		$user = get_user_by( 'email', 'newcomer@example.test' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertContains( NEWSPACK_NETWORK_READER_ROLE, $user->roles );
		$this->assertSame( 'https://a.example.test', get_user_meta( $user->ID, Users::USER_META_REMOTE_SITE, true ) );
		$this->assertSame( 'active', get_user_meta( $user->ID, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true )['https://a.example.test'][42]['status'] );
	}

	/**
	 * An existing account is used as is.
	 */
	public function test_event_for_known_email_uses_the_existing_account() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'regular@example.test',
				'role'       => 'subscriber',
			] 
		);
		$before  = count_users()['total_users'];

		$this->subscription( 'regular@example.test' )->process_in_node();

		$this->assertSame( $before, count_users()['total_users'] );
		$this->assertContains( 'subscriber', get_userdata( $user_id )->roles );
		$this->assertSame( 'active', get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true )['https://a.example.test'][42]['status'] );
	}
}
