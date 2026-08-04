<?php
/**
 * Tests for the Teams → group subscription migration CLI (NPPD-1753).
 *
 * Covers the parts of the migration that are new in the plugin port and not just
 * carried over from the drop-in: member adds routed through the Group_Subscription
 * data layer (joined-at is recorded, non-readers are skipped), manager promotion
 * from WooCommerce Teams roles, and the manager backfill (subscription resolution
 * and idempotency). The subscription-creation machinery is exercised end-to-end on
 * a real site by the CLI, not here — the WC mocks don't model line items.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\CLI\Teams_Migration;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Test the migration data-layer helpers and the manager backfill.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Teams_Migration extends WP_UnitTestCase {

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Team post IDs to clean up.
	 *
	 * @var int[]
	 */
	private $team_ids = [];

	/**
	 * Include the WC mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the mock subscription store and the per-request cache between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		Group_Subscription::reset_cache();
	}

	/**
	 * Clean up fixtures.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		foreach ( $this->team_ids as $team_id ) {
			wp_delete_post( $team_id, true );
		}
		$this->user_ids = [];
		$this->team_ids = [];
		parent::tear_down();
	}

	/**
	 * Create a reader user (a valid group member).
	 *
	 * @return int User ID.
	 */
	private function create_reader(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'user-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'user-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * Create an editor (a non-reader — is_user_reader() excludes editors/admins).
	 *
	 * @return int User ID.
	 */
	private function create_editor(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'editor-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'editor-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'editor',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture editor creation should succeed.' );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Create an active, group-enabled subscription owned by $owner_id.
	 *
	 * @param int $owner_id Owner user ID.
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $owner_id ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		return $subscription;
	}

	/**
	 * Create a wc_memberships_team post with the given owner, members, and optional
	 * linked subscription ID.
	 *
	 * @param int      $owner_id   Team owner user ID.
	 * @param int[]    $member_ids Team member user IDs (repeatable _member_id meta).
	 * @param int|null $sub_id     Optional linked subscription ID.
	 * @return int Team post ID.
	 */
	private function create_team( int $owner_id, array $member_ids, ?int $sub_id = null ): int {
		$team_id = wp_insert_post(
			[
				'post_type'   => 'wc_memberships_team',
				'post_status' => 'publish',
				'post_title'  => 'Team ' . wp_generate_password( 4, false ),
				'post_author' => $owner_id,
			]
		);
		$this->assertNotWPError( $team_id, 'Fixture team creation should succeed.' );
		$this->team_ids[] = $team_id;
		foreach ( $member_ids as $member_id ) {
			add_post_meta( $team_id, '_member_id', $member_id );
		}
		if ( $sub_id ) {
			update_post_meta( $team_id, '_subscription_id', $sub_id );
		}
		return $team_id;
	}

	/**
	 * Set a member's WooCommerce Teams role for a team.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $team_id Team post ID.
	 * @param string $role    'owner', 'manager', or 'member'.
	 */
	private function set_team_role( int $user_id, int $team_id, string $role ): void {
		update_user_meta( $user_id, sprintf( Teams_Migration::TEAM_ROLE_META_KEY_TEMPLATE, $team_id ), $role );
	}

	/**
	 * The add_group_member() helper records membership and the joined-at timestamp,
	 * and is idempotent on a second call.
	 */
	public function test_add_group_member_records_joined_at_and_is_idempotent() {
		$owner        = $this->create_reader();
		$member       = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner );

		$status = Teams_Migration::add_group_member( $subscription, $member );
		$this->assertSame( 'added', $status, 'A new reader should be added.' );
		$this->assertTrue( Group_Subscription::user_is_member( $member, $subscription ), 'The member should now hold group membership.' );

		$joined_key = Group_Subscription::get_member_joined_meta_key( $subscription->get_id() );
		$this->assertNotEmpty( get_user_meta( $member, $joined_key, true ), 'The joined-at timestamp should be recorded (the drop-in omitted it).' );

		$this->assertSame( 'already', Teams_Migration::add_group_member( $subscription, $member ), 'A repeat add should report already-a-member, not duplicate.' );
	}

	/**
	 * The add_group_member() helper propagates the data layer's member-limit gate:
	 * once the group is at its configured seat limit, further adds return a WP_Error
	 * rather than silently over-filling. migrate-teams sidesteps this by deferring the
	 * limit write until after members are added, but the gate still governs re-runs
	 * and reused subscriptions, so its behavior is pinned here.
	 */
	public function test_add_group_member_respects_member_limit() {
		$owner        = $this->create_reader();
		$first        = $this->create_reader();
		$second       = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner );
		// A limit of 2 is the owner plus one member seat, so the first add fills the lone
		// non-owner seat and the second is over capacity.
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', 2 );

		$this->assertSame( 'added', Teams_Migration::add_group_member( $subscription, $first ), 'The first member fills the single non-owner seat.' );
		$at_capacity = Teams_Migration::add_group_member( $subscription, $second );
		$this->assertWPError( $at_capacity, 'Adding past the limit should return a WP_Error, not silently over-fill.' );
		$this->assertFalse( (bool) Group_Subscription::user_is_member( $second, $subscription ), 'The over-capacity member should not have been added.' );
	}

	/**
	 * The seat-count → group-limit mapping counts the owner within the limit: a team
	 * whose owner already holds a seat maps across unchanged; one whose owner takes no
	 * seat gains a seat for the owner; the result is floored to the 2-seat minimum
	 * (owner + one member); and 0 (unlimited) passes through unchanged.
	 */
	public function test_map_team_seats_to_group_limit() {
		// Owner already occupies a team seat (counted in _seat_count): maps straight across.
		$this->assertSame( 10, Teams_Migration::map_team_seats_to_group_limit( 10, true ) );
		// Owner takes no team seat, so is uncounted: add one for their group seat.
		$this->assertSame( 11, Teams_Migration::map_team_seats_to_group_limit( 10, false ) );
		// Floor at the 2-seat minimum whether or not the owner holds a seat.
		$this->assertSame( 2, Teams_Migration::map_team_seats_to_group_limit( 1, true ) );
		$this->assertSame( 2, Teams_Migration::map_team_seats_to_group_limit( 1, false ) );
		// 0 = unlimited passes through unchanged, regardless of the owner's seat.
		$this->assertSame( 0, Teams_Migration::map_team_seats_to_group_limit( 0, true ) );
		$this->assertSame( 0, Teams_Migration::map_team_seats_to_group_limit( 0, false ) );
	}

	/**
	 * The add_group_member() helper skips editors/admins — they are not readers and
	 * already have full access, so they should not be recorded as group members.
	 */
	public function test_add_group_member_skips_non_readers() {
		$owner        = $this->create_reader();
		$editor       = $this->create_editor();
		$subscription = $this->create_group_subscription( $owner );

		$this->assertSame( 'not_reader', Teams_Migration::add_group_member( $subscription, $editor ), 'A non-reader (editor) should be skipped.' );
		$this->assertFalse( (bool) Group_Subscription::user_is_member( $editor, $subscription ), 'The editor should not become a group member.' );
	}

	/**
	 * A team member with the Teams `manager` role who is a group member is promoted
	 * to a group manager; a plain member is left alone.
	 */
	public function test_promotes_manager_role_members_only() {
		$owner            = $this->create_reader();
		$manager_member   = $this->create_reader();
		$plain_member     = $this->create_reader();
		$subscription     = $this->create_group_subscription( $owner );
		$subscription_id  = $subscription->get_id();

		Teams_Migration::add_group_member( $subscription, $manager_member );
		Teams_Migration::add_group_member( $subscription, $plain_member );

		$team_id = $this->create_team( $owner, [ $manager_member, $plain_member ], $subscription_id );
		$this->set_team_role( $manager_member, $team_id, 'manager' );
		$this->set_team_role( $plain_member, $team_id, 'member' );

		$result = Teams_Migration::promote_managers_from_team_roles( $subscription, $team_id, [ $manager_member, $plain_member ], false );

		$this->assertSame( [ $manager_member ], $result['promoted'], 'Only the manager-role member should be promoted.' );
		$managers = array_map( 'intval', Group_Subscription::get_managers( $subscription ) );
		$this->assertContains( $manager_member, $managers, 'The promoted member should now be a manager.' );
		$this->assertNotContains( $plain_member, $managers, 'The plain member should not be a manager.' );
	}

	/**
	 * Promotion is idempotent — a member already managing is reported as already,
	 * not promoted again.
	 */
	public function test_promotion_is_idempotent() {
		$owner        = $this->create_reader();
		$member       = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner );
		Teams_Migration::add_group_member( $subscription, $member );
		$team_id = $this->create_team( $owner, [ $member ], $subscription->get_id() );
		$this->set_team_role( $member, $team_id, 'manager' );

		Teams_Migration::promote_managers_from_team_roles( $subscription, $team_id, [ $member ], false );
		Group_Subscription::reset_cache();
		$second = Teams_Migration::promote_managers_from_team_roles( $subscription, $team_id, [ $member ], false );

		$this->assertSame( [], $second['promoted'], 'A second pass should promote no one.' );
		$this->assertSame( [ $member ], $second['already'], 'The member should be reported as already managing.' );
	}

	/**
	 * A manager-role user who is not a group member is not promoted (add_manager
	 * requires membership); they are reported as not-a-member.
	 */
	public function test_manager_role_non_member_is_not_promoted() {
		$owner        = $this->create_reader();
		$outsider     = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner );
		$team_id      = $this->create_team( $owner, [ $outsider ], $subscription->get_id() );
		$this->set_team_role( $outsider, $team_id, 'manager' );

		$result = Teams_Migration::promote_managers_from_team_roles( $subscription, $team_id, [ $outsider ], false );

		$this->assertSame( [], $result['promoted'], 'A non-member should not be promoted.' );
		$this->assertSame( [ $outsider ], $result['not_member'], 'The non-member should be reported as not-a-member.' );
	}

	/**
	 * The owner is never treated as a promotable manager even if their Teams role
	 * meta says `manager` — ownership already implies management.
	 */
	public function test_owner_is_never_promoted() {
		$owner        = $this->create_reader();
		$subscription = $this->create_group_subscription( $owner );
		$team_id      = $this->create_team( $owner, [ $owner ], $subscription->get_id() );
		$this->set_team_role( $owner, $team_id, 'manager' );

		$result = Teams_Migration::promote_managers_from_team_roles( $subscription, $team_id, [ $owner ], false );

		$this->assertSame( [], $result['promoted'], 'The owner should not be promoted.' );
		$this->assertSame( [], $result['already'], 'The owner should not be tallied as a promoted/already manager here.' );
	}

	/**
	 * The backfill resolves the team's linked active group subscription and promotes
	 * its manager-role members.
	 */
	public function test_backfill_resolves_linked_subscription() {
		$owner          = $this->create_reader();
		$manager_member = $this->create_reader();
		$subscription   = $this->create_group_subscription( $owner );
		Teams_Migration::add_group_member( $subscription, $manager_member );
		$team_id = $this->create_team( $owner, [ $manager_member ], $subscription->get_id() );
		$this->set_team_role( $manager_member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertTrue( $result['resolved'], 'The linked subscription should resolve.' );
		$this->assertSame( $subscription->get_id(), $result['subscription_id'], 'The resolved subscription should be the linked one.' );
		$this->assertSame( [ $manager_member ], $result['promoted'], 'The manager-role member should be promoted.' );
		$this->assertContains( $manager_member, array_map( 'intval', Group_Subscription::get_managers( $subscription ) ), 'The member should now be a manager.' );
	}

	/**
	 * When a team has no linked subscription, the backfill falls back to an active
	 * group subscription owned by the team owner.
	 */
	public function test_backfill_resolves_owner_subscription_when_unlinked() {
		$owner          = $this->create_reader();
		$manager_member = $this->create_reader();
		$subscription   = $this->create_group_subscription( $owner );
		Teams_Migration::add_group_member( $subscription, $manager_member );
		$team_id = $this->create_team( $owner, [ $manager_member ], null ); // No linked subscription.
		$this->set_team_role( $manager_member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertTrue( $result['resolved'], 'The owner-owned subscription should resolve.' );
		$this->assertSame( $subscription->get_id(), $result['subscription_id'], 'The resolved subscription should be the owner-owned one.' );
		$this->assertSame( [ $manager_member ], $result['promoted'], 'The manager-role member should be promoted.' );
	}

	/**
	 * A team with no linked subscription and no owner-owned group subscription is
	 * reported as unresolved and promotes no one.
	 */
	public function test_backfill_unresolved_without_subscription() {
		$owner   = $this->create_reader();
		$member  = $this->create_reader();
		$team_id = $this->create_team( $owner, [ $member ], null );
		$this->set_team_role( $member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertFalse( $result['resolved'], 'With no subscription the team should be unresolved.' );
		$this->assertSame( [], $result['promoted'], 'Nothing should be promoted for an unresolved team.' );
	}

	/**
	 * A dry-run backfill reports the members it would promote but writes nothing.
	 */
	public function test_backfill_dry_run_writes_nothing() {
		$owner          = $this->create_reader();
		$manager_member = $this->create_reader();
		$subscription   = $this->create_group_subscription( $owner );
		Teams_Migration::add_group_member( $subscription, $manager_member );
		$team_id = $this->create_team( $owner, [ $manager_member ], $subscription->get_id() );
		$this->set_team_role( $manager_member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, false );

		$this->assertSame( [ $manager_member ], $result['promoted'], 'The dry-run should report the would-be promotion.' );
		Group_Subscription::reset_cache();
		$this->assertNotContains( $manager_member, array_map( 'intval', Group_Subscription::get_managers( $subscription ) ), 'A dry-run must not actually promote the member.' );
	}

	/**
	 * The owner-owned subscription fallback ignores an inactive (e.g. cancelled) group
	 * subscription — only an active one qualifies, so an unlinked team owning only a
	 * cancelled group subscription is reported unresolved.
	 */
	public function test_backfill_skips_inactive_owner_subscription() {
		$owner  = $this->create_reader();
		$member = $this->create_reader();
		// An owner-owned group subscription that is cancelled, not active.
		$cancelled = wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'cancelled',
				'billing_period' => 'month',
			]
		);
		$cancelled->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$team_id = $this->create_team( $owner, [ $member ], null );
		$this->set_team_role( $member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertFalse( $result['resolved'], 'An inactive owner-owned subscription must not be resolved.' );
		$this->assertSame( [], $result['promoted'], 'Nothing should be promoted for an unresolved team.' );
	}

	/**
	 * The owner-owned subscription fallback ignores an active subscription that is not
	 * group-enabled — the migration must never repurpose a member's ordinary
	 * subscription as their group.
	 */
	public function test_backfill_skips_non_group_owner_subscription() {
		$owner  = $this->create_reader();
		$member = $this->create_reader();
		// An active but plain (non-group) subscription owned by the team owner.
		wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$team_id = $this->create_team( $owner, [ $member ], null );
		$this->set_team_role( $member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertFalse( $result['resolved'], 'An active non-group owner-owned subscription must not be resolved.' );
		$this->assertSame( [], $result['promoted'], 'Nothing should be promoted for an unresolved team.' );
	}

	/**
	 * Among a mix of the owner's subscriptions, the fallback selects the active
	 * group-enabled one, skipping cancelled and non-group subscriptions — this is the
	 * idempotency guarantee that re-running the migration reuses the right group.
	 */
	public function test_backfill_selects_active_group_subscription_among_mixed() {
		$owner  = $this->create_reader();
		$member = $this->create_reader();
		// Noise the fallback must skip: a cancelled group sub and an active non-group sub.
		$cancelled = wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'cancelled',
				'billing_period' => 'month',
			]
		);
		$cancelled->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		// The real target: an active, group-enabled subscription.
		$active_group = $this->create_group_subscription( $owner );
		Teams_Migration::add_group_member( $active_group, $member );

		$team_id = $this->create_team( $owner, [ $member ], null );
		$this->set_team_role( $member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertTrue( $result['resolved'], 'The active group subscription should resolve.' );
		$this->assertSame( $active_group->get_id(), $result['subscription_id'], 'The active group subscription should be selected over the cancelled/non-group ones.' );
		$this->assertSame( [ $member ], $result['promoted'], 'The manager-role member should be promoted into the resolved group.' );
	}
}
