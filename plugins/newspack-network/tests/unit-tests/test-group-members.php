<?php
/**
 * Class TestGroupMembers
 *
 * @package Newspack_Network
 */

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;
use Newspack_Network\Incoming_Events\Group_Members_Changed;
use Newspack_Network\Incoming_Events\Subscription_Changed;
use Newspack_Network\Woocommerce_Subscriptions\Group_Members;

/**
 * How group subscription seats reach the hub.
 */
class TestGroupMembers extends WP_UnitTestCase {

	/**
	 * Group subscription IDs whose members were reported.
	 *
	 * @var int[]
	 */
	private $reported = [];

	/**
	 * Start from an empty queue and record reports.
	 */
	public function set_up() {
		parent::set_up();
		Group_Members::dispatch_queued();
		$this->reported = [];
		add_action(
			Group_Members::HOOK,
			function ( $subscription_id ) {
				$this->reported[] = $subscription_id;
			}
		);
	}

	/**
	 * A group members event from a node.
	 *
	 * @param string[] $members Member emails.
	 * @param bool     $enabled Whether the group is enabled.
	 * @return Group_Members_Changed
	 */
	private function event( $members, $enabled = true ) {
		return new Group_Members_Changed(
			get_bloginfo( 'url' ),
			[
				'id'            => 900,
				'email'         => 'owner@example.test',
				'status_after'  => 'active',
				'products'      => [],
				'group_enabled' => $enabled,
				'group_members' => $members,
			],
			time()
		);
	}

	/**
	 * Member emails on the hub's copy of group subscription #900.
	 *
	 * @return string[]
	 */
	private function hub_members() {
		$copies = get_posts(
			[
				'post_type'   => Subscriptions_DB::POST_TYPE_SLUG,
				'post_status' => 'any',
				'meta_key'    => 'remote_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 900, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'      => 'ids',
			]
		);
		$this->assertCount( 1, $copies );
		$members = get_post_meta( $copies[0], Group_Members::HUB_MEMBER_META_KEY, false );
		sort( $members );
		return $members;
	}

	/**
	 * Adding members reports each changed group once, at the end of the request.
	 */
	public function test_added_members_report_their_group_once() {
		$first  = self::factory()->user->create();
		$second = self::factory()->user->create();
		add_user_meta( $first, Group_Members::MEMBER_META_KEY, 123 );
		add_user_meta( $second, Group_Members::MEMBER_META_KEY, 123 );
		add_user_meta( $second, Group_Members::MEMBER_META_KEY, 456 );
		add_user_meta( $first, 'unrelated_meta', 789 );

		$this->assertSame( [], $this->reported );
		Group_Members::dispatch_queued();
		$this->assertEqualsCanonicalizing( [ 123, 456 ], $this->reported );

		Group_Members::dispatch_queued();
		$this->assertCount( 2, $this->reported );
	}

	/**
	 * Removing a member reports their groups, including when every membership is
	 * removed at once without naming the groups (as deleting the user does).
	 */
	public function test_removed_members_report_their_groups() {
		$member = self::factory()->user->create();
		add_user_meta( $member, Group_Members::MEMBER_META_KEY, 123 );
		add_user_meta( $member, Group_Members::MEMBER_META_KEY, 456 );
		Group_Members::dispatch_queued();
		$this->reported = [];

		delete_user_meta( $member, Group_Members::MEMBER_META_KEY );
		Group_Members::dispatch_queued();

		$this->assertEqualsCanonicalizing( [ 123, 456 ], $this->reported );
	}

	/**
	 * Turning a group on or off changes who has access, so it is reported; other
	 * setting changes aren't.
	 */
	public function test_enabling_or_disabling_a_group_reports_its_members() {
		do_action( 'newspack_group_subscription_settings_updated', 321, [ 'limit' ] );
		Group_Members::dispatch_queued();
		$this->assertSame( [], $this->reported );

		do_action( 'newspack_group_subscription_settings_updated', 321, [ 'enabled' ] );
		Group_Members::dispatch_queued();
		$this->assertSame( [ 321 ], $this->reported );
	}

	/**
	 * The hub keeps the current member list on its copy of the group subscription,
	 * replacing the previous one, and none while the group is off.
	 */
	public function test_hub_keeps_current_members() {
		$this->event( [ 'A@example.test', 'b@example.test' ] )->always_process_in_hub();
		$this->assertSame( [ 'a@example.test', 'b@example.test' ], $this->hub_members() );

		$this->event( [ 'b@example.test' ] )->always_process_in_hub();
		$this->assertSame( [ 'b@example.test' ], $this->hub_members() );

		$this->event( [ 'b@example.test' ], false )->always_process_in_hub();
		$this->assertSame( [], $this->hub_members() );
	}

	/**
	 * A members event carries the subscription's status as of when it was sent. It
	 * can arrive after a later status change (webhooks retry), so it never overwrites
	 * the status of a copy the hub already has.
	 */
	public function test_members_event_leaves_existing_status_alone() {
		( new Subscription_Changed(
			get_bloginfo( 'url' ),
			[
				'id'           => 900,
				'email'        => 'owner@example.test',
				'status_after' => 'cancelled',
				'products'     => [],
			],
			time()
		) )->always_process_in_hub();

		$this->event( [ 'a@example.test' ] )->always_process_in_hub();

		$copies = get_posts(
			[
				'post_type'   => Subscriptions_DB::POST_TYPE_SLUG,
				'post_status' => 'any',
				'fields'      => 'ids',
			]
		);
		$this->assertSame( Subscriptions_DB::POST_STATUS_PREFIX . 'cancelled', get_post_status( $copies[0] ) );
		$this->assertSame( [ 'a@example.test' ], $this->hub_members() );
	}

	/**
	 * A copy written before copies were kept per site may hold another site's
	 * subscription, so a members event rewrites it from its own data before its
	 * members can be answered.
	 */
	public function test_members_event_rewrites_a_legacy_copy() {
		$legacy_copy = self::factory()->post->create(
			[
				'post_type'   => Subscriptions_DB::POST_TYPE_SLUG,
				'post_status' => Subscriptions_DB::POST_STATUS_PREFIX . 'cancelled',
			]
		);
		update_post_meta( $legacy_copy, 'remote_id', 900 );
		update_post_meta( $legacy_copy, 'node_id', 0 );
		update_post_meta( $legacy_copy, 'user_email', 'owner@example.test' );
		add_post_meta( $legacy_copy, 'products', [ 'id' => 7 ] );

		$this->event( [ 'a@example.test' ] )->always_process_in_hub();

		$this->assertSame( Subscriptions_DB::POST_STATUS_PREFIX . 'active', get_post_status( $legacy_copy ) );
		$this->assertSame( [], get_post_meta( $legacy_copy, 'products', false ) );
	}

	/**
	 * Group settings can be set on a variation as well as on its product.
	 */
	public function test_variation_group_setting_reports_groups_with_members() {
		$member = self::factory()->user->create();
		add_user_meta( $member, Group_Members::MEMBER_META_KEY, 123 );
		Group_Members::dispatch_queued();
		$this->reported = [];

		update_post_meta( self::factory()->post->create( [ 'post_type' => 'product_variation' ] ), Group_Members::PRODUCT_ENABLED_META_KEY, 'no' );
		Group_Members::dispatch_queued();

		$this->assertSame( [ 123 ], $this->reported );
	}

	/**
	 * Members who stay in the group keep their rows; only joins and departures are written.
	 */
	public function test_unchanged_members_are_not_rewritten() {
		global $wpdb;
		$this->event( [ 'a@example.test', 'b@example.test' ] )->always_process_in_hub();
		$row_for_a = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", Group_Members::HUB_MEMBER_META_KEY, 'a@example.test' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->event( [ 'a@example.test', 'c@example.test' ] )->always_process_in_hub();

		$this->assertSame( [ 'a@example.test', 'c@example.test' ], $this->hub_members() );
		$this->assertSame( $row_for_a, $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", Group_Members::HUB_MEMBER_META_KEY, 'a@example.test' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A role change can make a member eligible or ineligible for their seat, so their groups are reported.
	 */
	public function test_role_change_reports_the_members_groups() {
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		add_user_meta( $member, Group_Members::MEMBER_META_KEY, 123 );
		Group_Members::dispatch_queued();
		$this->reported = [];

		( new WP_User( $member ) )->set_role( 'editor' );
		Group_Members::dispatch_queued();

		$this->assertSame( [ 123 ], $this->reported );
	}

	/**
	 * Turning groups on or off for a product changes every subscription that inherits
	 * the product's setting, so every group with members is reported.
	 */
	public function test_product_group_setting_reports_groups_with_members() {
		$member  = self::factory()->user->create();
		$product = self::factory()->post->create( [ 'post_type' => 'product' ] );
		add_user_meta( $member, Group_Members::MEMBER_META_KEY, 123 );
		add_user_meta( $member, Group_Members::MEMBER_META_KEY, 456 );
		Group_Members::dispatch_queued();
		$this->reported = [];

		update_post_meta( self::factory()->post->create(), Group_Members::PRODUCT_ENABLED_META_KEY, 'no' );
		Group_Members::dispatch_queued();
		$this->assertSame( [], $this->reported, 'The same meta on a post that is not a product changes nothing.' );

		update_post_meta( $product, Group_Members::PRODUCT_ENABLED_META_KEY, 'no' );
		Group_Members::dispatch_queued();
		$this->assertEqualsCanonicalizing( [ 123, 456 ], $this->reported );
	}

	/**
	 * Member lists are sent to the hub only; nodes never pull them.
	 */
	public function test_member_lists_stay_on_the_hub() {
		$this->assertSame( 'Group_Members_Changed', Accepted_Actions::ACTIONS[ Group_Members::ACTION ] ?? null );
		$this->assertNotContains( Group_Members::ACTION, Accepted_Actions::ACTIONS_THAT_NODES_PULL );
	}
}
