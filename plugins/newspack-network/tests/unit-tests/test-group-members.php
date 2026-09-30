<?php
/**
 * Class TestGroupMembers
 *
 * @package Newspack_Network
 */

use Newspack_Network\Accepted_Actions;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;
use Newspack_Network\Incoming_Events\Group_Members_Changed;
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
	 * Member lists are sent to the hub only; nodes never pull them.
	 */
	public function test_member_lists_stay_on_the_hub() {
		$this->assertSame( 'Group_Members_Changed', Accepted_Actions::ACTIONS[ Group_Members::ACTION ] ?? null );
		$this->assertNotContains( Group_Members::ACTION, Accepted_Actions::ACTIONS_THAT_NODES_PULL );
	}
}
