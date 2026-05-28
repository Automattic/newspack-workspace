<?php
/**
 * Class TestMembershipTransfer
 *
 * @package Newspack_Network
 */

use Newspack_Network\Incoming_Events\Woocommerce_Membership_Updated;
use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;
use Newspack_Network\Woocommerce_Memberships\Events as Memberships_Events;

require_once __DIR__ . '/mock-wc-memberships.php';

/**
 * Test membership ownership transfer handling.
 *
 * @group membership-transfer
 */
class TestMembershipTransfer extends WP_UnitTestCase {

	/**
	 * Test that the transfer path reassigns membership post_author when found by remote_id.
	 *
	 * The WC Memberships functions are stubbed to return null, so the method will
	 * successfully do the wp_update_post (pure WP) but exit after that since the
	 * stub returns null for wc_memberships_get_user_membership.
	 */
	public function test_transfer_reassigns_post_author_by_remote_id() {
		$old_user_id = $this->factory->user->create( [ 'user_email' => 'oldowner@example.com' ] );
		$new_user_id = $this->factory->user->create( [ 'user_email' => 'newowner@example.com' ] );

		$plan_id = $this->factory->post->create(
			[
				'post_type'   => Memberships_Admin::MEMBERSHIP_PLANS_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Test Plan',
			]
		);
		update_post_meta( $plan_id, Memberships_Admin::NETWORK_ID_META_KEY, 'test-plan' );

		$membership_id = $this->factory->post->create(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $old_user_id,
				'post_parent' => $plan_id,
			]
		);
		update_post_meta( $membership_id, Memberships_Admin::NETWORK_MANAGED_META_KEY, true );
		update_post_meta( $membership_id, Memberships_Admin::REMOTE_ID_META_KEY, 999 );
		update_post_meta( $membership_id, Memberships_Admin::SITE_URL_META_KEY, 'https://hub.example.com' );

		// Verify initial ownership.
		$this->assertEquals( $old_user_id, (int) get_post( $membership_id )->post_author );

		$transfer_event = new Woocommerce_Membership_Updated(
			'https://hub.example.com',
			[
				'email'           => 'newowner@example.com',
				'user_id'         => $new_user_id,
				'plan_network_id' => 'test-plan',
				'membership_id'   => 999,
				'new_status'      => 'active',
				'end_date'        => null,
				'previous_email'  => 'oldowner@example.com',
			],
			time()
		);

		$transfer_method = new ReflectionMethod( Woocommerce_Membership_Updated::class, 'transfer_membership' );
		$transfer_method->setAccessible( true );

		$new_user = get_user_by( 'id', $new_user_id );
		$transfer_method->invoke( $transfer_event, $new_user, $plan_id, 'oldowner@example.com' );

		// Verify ownership was transferred via wp_update_post.
		$this->assertEquals( $new_user_id, (int) get_post( $membership_id )->post_author );
	}

	/**
	 * Test that transfer does not affect memberships with a different remote_id.
	 */
	public function test_transfer_does_not_touch_unrelated_membership() {
		$old_user_id = $this->factory->user->create( [ 'user_email' => 'oldowner2@example.com' ] );
		$new_user_id = $this->factory->user->create( [ 'user_email' => 'newowner2@example.com' ] );
		$other_user_id = $this->factory->user->create( [ 'user_email' => 'other@example.com' ] );

		$plan_id = $this->factory->post->create(
			[
				'post_type'   => Memberships_Admin::MEMBERSHIP_PLANS_CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $plan_id, Memberships_Admin::NETWORK_ID_META_KEY, 'test-plan' );

		// Target membership (remote_id = 999).
		$target_membership_id = $this->factory->post->create(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $old_user_id,
				'post_parent' => $plan_id,
			]
		);
		update_post_meta( $target_membership_id, Memberships_Admin::NETWORK_MANAGED_META_KEY, true );
		update_post_meta( $target_membership_id, Memberships_Admin::REMOTE_ID_META_KEY, 999 );
		update_post_meta( $target_membership_id, Memberships_Admin::SITE_URL_META_KEY, 'https://hub.example.com' );

		// Unrelated membership (remote_id = 888).
		$unrelated_membership_id = $this->factory->post->create(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $other_user_id,
				'post_parent' => $plan_id,
			]
		);
		update_post_meta( $unrelated_membership_id, Memberships_Admin::NETWORK_MANAGED_META_KEY, true );
		update_post_meta( $unrelated_membership_id, Memberships_Admin::REMOTE_ID_META_KEY, 888 );
		update_post_meta( $unrelated_membership_id, Memberships_Admin::SITE_URL_META_KEY, 'https://hub.example.com' );

		$transfer_event = new Woocommerce_Membership_Updated(
			'https://hub.example.com',
			[
				'email'           => 'newowner2@example.com',
				'user_id'         => $new_user_id,
				'plan_network_id' => 'test-plan',
				'membership_id'   => 999,
				'new_status'      => 'active',
				'end_date'        => null,
				'previous_email'  => 'oldowner2@example.com',
			],
			time()
		);

		$transfer_method = new ReflectionMethod( Woocommerce_Membership_Updated::class, 'transfer_membership' );
		$transfer_method->setAccessible( true );

		$new_user = get_user_by( 'id', $new_user_id );
		$transfer_method->invoke( $transfer_event, $new_user, $plan_id, 'oldowner2@example.com' );

		// Target should be transferred.
		$this->assertEquals( $new_user_id, (int) get_post( $target_membership_id )->post_author );

		// Unrelated should not be touched.
		$this->assertEquals( $other_user_id, (int) get_post( $unrelated_membership_id )->post_author );
	}

	/**
	 * Test that Events::membership_transferred returns null when events are paused.
	 */
	public function test_events_listener_paused() {
		$previous_pause_events = Memberships_Events::$pause_events;

		try {
			Memberships_Events::$pause_events = true;
			$result = Memberships_Events::membership_transferred( null, null, null );
			$this->assertNull( $result );
		} finally {
			Memberships_Events::$pause_events = $previous_pause_events;
		}
	}

	/**
	 * Test the full update_membership flow routes to transfer when previous_email is set.
	 *
	 * With stubbed WC functions (returning null), the method will find the plan via DB,
	 * enter the transfer path, do the wp_update_post, then gracefully exit when
	 * wc_memberships_get_user_membership returns null.
	 */
	public function test_update_membership_routes_to_transfer() {
		$old_user_id = $this->factory->user->create( [ 'user_email' => 'oldowner3@example.com' ] );
		$new_user_id = $this->factory->user->create( [ 'user_email' => 'newowner3@example.com' ] );

		$plan_id = $this->factory->post->create(
			[
				'post_type'   => Memberships_Admin::MEMBERSHIP_PLANS_CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $plan_id, Memberships_Admin::NETWORK_ID_META_KEY, 'test-plan-full' );

		$membership_id = $this->factory->post->create(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $old_user_id,
				'post_parent' => $plan_id,
			]
		);
		update_post_meta( $membership_id, Memberships_Admin::NETWORK_MANAGED_META_KEY, true );
		update_post_meta( $membership_id, Memberships_Admin::REMOTE_ID_META_KEY, 777 );
		update_post_meta( $membership_id, Memberships_Admin::SITE_URL_META_KEY, 'https://hub.example.com' );

		$transfer_event = new Woocommerce_Membership_Updated(
			'https://hub.example.com',
			[
				'email'           => 'newowner3@example.com',
				'user_id'         => $new_user_id,
				'plan_network_id' => 'test-plan-full',
				'membership_id'   => 777,
				'new_status'      => 'active',
				'end_date'        => null,
				'previous_email'  => 'oldowner3@example.com',
			],
			time()
		);

		// Call the full update_membership flow (which now has the WC function stubs).
		$transfer_event->update_membership();

		// Verify the membership was transferred.
		$this->assertEquals( $new_user_id, (int) get_post( $membership_id )->post_author );
	}

	/**
	 * Team-owned memberships (those with a `_team_id` meta) must not be transferred via
	 * post_author rewrite. Their ownership lives on the team's `_member_id`, the linked
	 * subscription's `_customer_user`, and team_member user meta. See NPPM-2741.
	 */
	public function test_transfer_skips_team_owned_membership() {
		$old_user_id = $this->factory->user->create( [ 'user_email' => 'teamold@example.com' ] );
		$new_user_id = $this->factory->user->create( [ 'user_email' => 'teamnew@example.com' ] );

		$plan_id = $this->factory->post->create(
			[
				'post_type'   => Memberships_Admin::MEMBERSHIP_PLANS_CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $plan_id, Memberships_Admin::NETWORK_ID_META_KEY, 'test-team-plan' );

		$membership_id = $this->factory->post->create(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_author' => $old_user_id,
				'post_parent' => $plan_id,
			]
		);
		update_post_meta( $membership_id, Memberships_Admin::NETWORK_MANAGED_META_KEY, true );
		update_post_meta( $membership_id, Memberships_Admin::REMOTE_ID_META_KEY, 555 );
		update_post_meta( $membership_id, Memberships_Admin::SITE_URL_META_KEY, 'https://hub.example.com' );
		// Mark as team-owned.
		update_post_meta( $membership_id, '_team_id', 42 );

		$transfer_event = new Woocommerce_Membership_Updated(
			'https://hub.example.com',
			[
				'email'           => 'teamnew@example.com',
				'user_id'         => $new_user_id,
				'plan_network_id' => 'test-team-plan',
				'membership_id'   => 555,
				'new_status'      => 'active',
				'end_date'        => null,
				'previous_email'  => 'teamold@example.com',
			],
			time()
		);

		$transfer_method = new ReflectionMethod( Woocommerce_Membership_Updated::class, 'transfer_membership' );
		$transfer_method->setAccessible( true );

		$new_user = get_user_by( 'id', $new_user_id );
		$transfer_method->invoke( $transfer_event, $new_user, $plan_id, 'teamold@example.com' );

		// post_author must remain unchanged for team-owned memberships.
		$this->assertEquals( $old_user_id, (int) get_post( $membership_id )->post_author );
	}
}
