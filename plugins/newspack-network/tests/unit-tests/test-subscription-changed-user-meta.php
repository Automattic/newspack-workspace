<?php
/**
 * Class TestSubscriptionChangedUserMeta
 *
 * @package Newspack_Network
 */

use Newspack_Network\Incoming_Events\Subscription_Changed;

/**
 * How a subscription event updates the reader's record of their subscriptions on other sites.
 */
class TestSubscriptionChangedUserMeta extends WP_UnitTestCase {

	/**
	 * A status change for a pulled subscription keeps the Network IDs the pull recorded,
	 * since the event itself carries only product IDs.
	 */
	public function test_event_keeps_pulled_network_ids() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		update_user_meta(
			$user_id,
			Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY,
			[
				'https://other.test' => [
					42 => [
						'id'       => 42,
						'status'   => 'active',
						'products' => [
							7 => [
								'id'         => 7,
								'name'       => 'Premium',
								'network_id' => 'premium',
							],
						],
					],
				],
			]
		);

		$event = new Subscription_Changed(
			'https://other.test',
			[
				'email'        => 'reader@example.test',
				'id'           => 42,
				'status_after' => 'pending-cancel',
				'products'     => [
					7 => [
						'id'   => 7,
						'name' => 'Premium',
					],
				],
			],
			time()
		);
		$event->maybe_update_user_meta();

		$record = get_user_meta( $user_id, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true )['https://other.test'][42];
		$this->assertSame( 'pending-cancel', $record['status'] );
		$this->assertSame( 'premium', $record['products'][7]['network_id'] );
	}
}
