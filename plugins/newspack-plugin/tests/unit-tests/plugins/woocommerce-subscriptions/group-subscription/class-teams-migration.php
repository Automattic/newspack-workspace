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
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		// Products registered by one test are visible to every later one, and the
		// mock's set_product_id() guard now reads this store to decide whether an ID
		// is a variation — so a leaked registration could change another test's
		// outcome. Every sibling test class resets it here for the same reason.
		$products_database = [];
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
		$subscription->save();
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

	/**
	 * Backfill resolution keys on the team marker for a multi-team owner: a team resolves
	 * to its own migrated group, never a sibling team's, so managers are never promoted
	 * across teams. This pins the sibling-command half of the NPPD-2060 fix — the merge
	 * the migration avoids must not resurface in the manager backfill.
	 *
	 * Backfills team B, whose group is the owner's *second* group, so an owner-first
	 * resolver (the deleted pre-fix behaviour) would wrongly land on team A's group — the
	 * marker-keyed resolver must land on team B's.
	 */
	public function test_backfill_resolves_team_marked_group_for_multi_team_owner() {
		$owner          = $this->create_reader();
		$team_a_manager = $this->create_reader();
		$team_b_manager = $this->create_reader();
		// Two unlinked teams of the same owner, each already migrated to its own marked group.
		$team_a_id    = $this->create_team( $owner, [ $team_a_manager ], null );
		$team_b_id    = $this->create_team( $owner, [ $team_b_manager ], null );
		$team_a_group = $this->create_migrated_group_subscription( $owner, $team_a_id );
		$team_b_group = $this->create_migrated_group_subscription( $owner, $team_b_id );
		Teams_Migration::add_group_member( $team_a_group, $team_a_manager );
		Teams_Migration::add_group_member( $team_b_group, $team_b_manager );
		$this->set_team_role( $team_a_manager, $team_a_id, 'manager' );
		$this->set_team_role( $team_b_manager, $team_b_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_b_id, true );

		$this->assertTrue( $result['resolved'], 'Team B should resolve to its own marked group.' );
		$this->assertSame( $team_b_group->get_id(), $result['subscription_id'], 'Backfill must resolve team B\'s marked group (its second group), never team A\'s.' );
		$this->assertSame( [ $team_b_manager ], $result['promoted'], 'Only team B\'s manager should be promoted, into team B\'s group.' );
		$this->assertNotContains( $team_b_manager, array_map( 'intval', Group_Subscription::get_managers( $team_a_group ) ), 'Team B\'s manager must not land in team A\'s group.' );
	}

	/**
	 * Backfill for a team with no linked and no marked group falls back to an unmarked
	 * (legacy pre-marker) group owned by the team owner — the unified resolver keeps the
	 * legacy path that predates per-team marking.
	 */
	public function test_backfill_falls_back_to_unmarked_owner_group() {
		$owner          = $this->create_reader();
		$manager_member = $this->create_reader();
		$legacy_group   = $this->create_group_subscription( $owner ); // Unmarked.
		Teams_Migration::add_group_member( $legacy_group, $manager_member );
		$team_id = $this->create_team( $owner, [ $manager_member ], null );
		$this->set_team_role( $manager_member, $team_id, 'manager' );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertTrue( $result['resolved'], 'An unmarked owner group should still resolve as the legacy fallback.' );
		$this->assertSame( $legacy_group->get_id(), $result['subscription_id'], 'The unmarked legacy group should be resolved.' );
		$this->assertSame( [ $manager_member ], $result['promoted'], 'The manager-role member should be promoted into the legacy group.' );
	}

	/**
	 * Backfill reports a team whose marked group has group subscriptions disabled as
	 * unresolved rather than promoting managers into it — group manager meta on a group
	 * the publisher switched off would be inert. The reason names the group, since
	 * "nothing found" would be false twice over: a group was found, and it is active.
	 */
	public function test_backfill_does_not_resolve_a_disabled_marked_group() {
		$owner          = $this->create_reader();
		$manager_member = $this->create_reader();
		$team_id        = $this->create_team( $owner, [ $manager_member ], null );
		$disabled       = $this->create_migrated_group_subscription( $owner, $team_id );
		Teams_Migration::add_group_member( $disabled, $manager_member );
		$this->set_team_role( $manager_member, $team_id, 'manager' );
		$this->disable_group_subscriptions_on( $disabled );

		$result = Teams_Migration::backfill_team_managers_for_team( $team_id, true );

		$this->assertFalse( $result['resolved'], 'A group with group subscriptions disabled must not be resolved for a manager backfill.' );
		$this->assertNotContains( $manager_member, array_map( 'intval', Group_Subscription::get_managers( $disabled ) ), 'No manager should be promoted into the disabled group.' );
		$this->assertStringContainsString( (string) $disabled->get_id(), $result['reason'], 'The reason must name the disabled group so the operator knows what to re-enable.' );
	}

	/**
	 * Create an active group subscription owned by $owner_id and marked as migrated
	 * from $team_id, mirroring what migrate-teams stamps for a team.
	 *
	 * @param int $owner_id Owner user ID.
	 * @param int $team_id  Source team post ID to stamp on the subscription.
	 * @return WC_Subscription
	 */
	private function create_migrated_group_subscription( int $owner_id, int $team_id ) {
		$subscription = $this->create_group_subscription( $owner_id );
		$subscription->update_meta_data( Teams_Migration::MIGRATED_TEAM_ID_META_KEY, $team_id );
		$subscription->save();
		return $subscription;
	}

	/**
	 * Turn group subscriptions off on a subscription, as a publisher would from its
	 * settings — the subscription's own meta overrides the product-level setting.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @return WC_Subscription The same subscription, for chaining.
	 */
	private function disable_group_subscriptions_on( $subscription ) {
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'no' );
		$subscription->save();
		return $subscription;
	}

	/**
	 * Reuse keys on the source team: each team resolves back to the group subscription
	 * stamped with its own team ID, even when one owner owns both — so re-running the
	 * migration updates each team's group in place rather than duplicating it.
	 */
	public function test_reuse_keys_on_team_marker_not_owner() {
		$owner            = $this->create_reader();
		$first_team_id    = $this->create_team( $owner, [] );
		$second_team_id   = $this->create_team( $owner, [] );
		$first_team_group  = $this->create_migrated_group_subscription( $owner, $first_team_id );
		$second_team_group = $this->create_migrated_group_subscription( $owner, $second_team_id );

		$first_reuse = Teams_Migration::find_reusable_group_subscription( $first_team_id, $owner );
		$this->assertSame(
			$first_team_group->get_id(),
			$first_reuse['subscription']->get_id(),
			'The first team should resolve to the subscription marked with its own team ID.'
		);
		$this->assertFalse( $first_reuse['used_owner_fallback'], 'A marker match is not an owner fallback.' );

		$second_reuse = Teams_Migration::find_reusable_group_subscription( $second_team_id, $owner );
		$this->assertSame(
			$second_team_group->get_id(),
			$second_reuse['subscription']->get_id(),
			'The second team should resolve to its own marked subscription, not the first team\'s.'
		);
		$this->assertFalse( $second_reuse['used_owner_fallback'], 'A marker match is not an owner fallback.' );
	}

	/**
	 * A marker match wins over an unmarked (fallback-eligible) group of the same owner
	 * even when the unmarked one is iterated first. This is the load-bearing ordering
	 * property of the resolver: the fallback is only ever *remembered*, never returned
	 * early, so a "return the first eligible subscription" refactor cannot creep back in.
	 */
	public function test_marked_group_wins_over_earlier_iterated_unmarked_group() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, [] );
		// wcs_get_users_subscriptions() iterates in creation order, so the unmarked group
		// is seen before the team's own marked one.
		$unmarked_group = $this->create_group_subscription( $owner );
		$marked_group   = $this->create_migrated_group_subscription( $owner, $team_id );

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertSame( $marked_group->get_id(), $reuse['subscription']->get_id(), 'The group marked for this team must win over the earlier-iterated unmarked group ' . $unmarked_group->get_id() . '.' );
		$this->assertFalse( $reuse['used_owner_fallback'], 'A marker match is not an owner fallback.' );
	}

	/**
	 * The team's own marked group is reused even once it is no longer active — its end
	 * date passing (migrate-teams sets one from the team's membership end) must not make
	 * it invisible, or a re-run would create a second group for the same team and split
	 * its members across the two.
	 */
	public function test_inactive_marked_group_is_reused_rather_than_duplicated() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, [] );
		$expired = $this->create_migrated_group_subscription( $owner, $team_id );
		$expired->set_status( 'expired' );
		$expired->save();

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertSame( $expired->get_id(), $reuse['subscription']->get_id(), 'An expired group marked for this team should still be reused.' );
		$this->assertTrue( $reuse['reused_without_access'], 'Reusing a group whose status withholds access must be flagged so the caller warns.' );
		$this->assertFalse( $reuse['used_owner_fallback'], 'A marker match is not an owner fallback.' );
	}

	/**
	 * A publisher who turns group subscriptions off on the group this team was migrated
	 * to is making a deliberate choice, and the marker still says the group belongs to
	 * this team. Neither reusing it (migrate-teams would re-enable the flag) nor ignoring
	 * it (a re-run would create a second group stamped with the same team ID) is right, so
	 * the resolver reports it instead and the caller refuses the team.
	 */
	public function test_disabled_marked_group_is_reported_rather_than_reused_or_duplicated() {
		$owner    = $this->create_reader();
		$team_id  = $this->create_team( $owner, [] );
		$disabled = $this->disable_group_subscriptions_on( $this->create_migrated_group_subscription( $owner, $team_id ) );

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertNull( $reuse['subscription'], 'A group with group subscriptions disabled must not be offered for reuse — migrate-teams would re-enable the flag.' );
		$this->assertSame( [ $disabled->get_id() ], $reuse['disabled_marked_group_ids'], "The disabled group's ID must be reported so the caller can name it and refuse the team." );
	}

	/**
	 * Every disabled group of the team's is reported at once, so an operator recovering a
	 * team that has more than one is not sent back for a run per group.
	 */
	public function test_all_disabled_marked_groups_are_reported_together() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner, [] );
		$first        = $this->disable_group_subscriptions_on( $this->create_migrated_group_subscription( $owner, $team_id ) );
		$second       = $this->disable_group_subscriptions_on( $this->create_migrated_group_subscription( $owner, $team_id ) );

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertNull( $reuse['subscription'], 'Neither disabled group may be offered for reuse.' );
		$this->assertSame( [ $first->get_id(), $second->get_id() ], $reuse['disabled_marked_group_ids'], 'Both disabled groups of this team must be reported in one pass.' );
	}

	/**
	 * The disabled marked group outranks the unmarked owner fallback: this team's own
	 * group exists, so adopting a different group of the owner's would merge the team's
	 * members into a group that is not theirs on the strength of a flag the publisher set.
	 */
	public function test_disabled_marked_group_outranks_the_unmarked_owner_fallback() {
		$owner    = $this->create_reader();
		$team_id  = $this->create_team( $owner, [] );
		$disabled = $this->disable_group_subscriptions_on( $this->create_migrated_group_subscription( $owner, $team_id ) );
		$unmarked = $this->create_group_subscription( $owner );

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertNull( $reuse['subscription'], 'The unmarked group ' . $unmarked->get_id() . ' must not be adopted while this team has a group of its own.' );
		$this->assertFalse( $reuse['used_owner_fallback'], 'Reporting a disabled marked group is not an owner fallback.' );
		$this->assertSame( [ $disabled->get_id() ], $reuse['disabled_marked_group_ids'], 'The disabled marked group must be reported in preference to the fallback.' );
	}

	/**
	 * A usable group of the team's own outranks a disabled one, whatever the iteration
	 * order: refusing a team whose group is sitting there to be updated would block a
	 * migration that has somewhere valid to go. The disabled group is created first so it
	 * is iterated first, which is the case that would break if the resolver returned on
	 * the first disabled group it saw instead of holding it and scanning on.
	 */
	public function test_usable_marked_group_wins_over_a_disabled_marked_group() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, [] );
		$this->disable_group_subscriptions_on( $this->create_migrated_group_subscription( $owner, $team_id ) );
		$active = $this->create_migrated_group_subscription( $owner, $team_id );

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertSame( $active->get_id(), $reuse['subscription']->get_id(), "The team's active group must be reused even though a disabled one is iterated first." );
		$this->assertSame( [], $reuse['disabled_marked_group_ids'], 'A team with a resolvable group must not report a refusal.' );
	}

	/**
	 * Same precedence one rung down: an expired group of the team's is still reusable, so
	 * it wins over a disabled one rather than the team being refused.
	 */
	public function test_expired_marked_group_wins_over_a_disabled_marked_group() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, [] );
		$this->disable_group_subscriptions_on( $this->create_migrated_group_subscription( $owner, $team_id ) );
		$expired = $this->create_migrated_group_subscription( $owner, $team_id );
		$expired->set_status( 'expired' );
		$expired->save();

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertSame( $expired->get_id(), $reuse['subscription']->get_id(), "The team's expired group is still reusable and must win over a disabled one." );
		$this->assertTrue( $reuse['reused_without_access'], 'Reusing the expired group must still be flagged as access-less.' );
		$this->assertSame( [], $reuse['disabled_marked_group_ids'], 'A team with a resolvable group must not report a refusal.' );
	}

	/**
	 * `pending-cancel` is not `active`, but group membership reads through
	 * WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES, so its members still have
	 * access until the paid-up period ends. Reusing such a group must therefore not be
	 * reported as access-less — the warning would push an operator to reactivate a
	 * subscription the reader deliberately cancelled.
	 */
	public function test_pending_cancel_marked_group_is_not_reported_as_access_less() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, [] );
		$pending_cancel = $this->create_migrated_group_subscription( $owner, $team_id );
		$pending_cancel->set_status( 'pending-cancel' );
		$pending_cancel->save();

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertSame( $pending_cancel->get_id(), $reuse['subscription']->get_id(), 'A pending-cancel group marked for this team should still be reused.' );
		$this->assertFalse( $reuse['reused_without_access'], 'pending-cancel still grants group access, so it must not be flagged as access-less.' );
	}

	/**
	 * The owner fallback requires the subscription's own group meta, not just
	 * Group_Subscription::is_group_subscription() — that reads through to the product,
	 * and migrate-team-products enables groups on every team product. Without this,
	 * a sibling team's live linked subscription would look like an adoptable legacy
	 * group and this team's members would be merged into it.
	 */
	public function test_fallback_ignores_group_enabled_only_via_product() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, [] );
		$product_level_group = wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		// Stand in for the product-level `_newspack_group_subscription_enabled` meta that
		// migrate-team-products stamps: group-enabled with no subscription-level meta.
		$enable_via_product = function ( $settings, $subscription ) use ( $product_level_group ) {
			if ( $subscription->get_id() === $product_level_group->get_id() ) {
				$settings['enabled'] = true;
			}
			return $settings;
		};
		add_filter( 'newspack_group_subscription_settings', $enable_via_product, 10, 2 );

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		remove_filter( 'newspack_group_subscription_settings', $enable_via_product, 10 );

		$this->assertNull( $reuse['subscription'], 'A subscription group-enabled only through its product must not be adopted as a legacy group.' );
		$this->assertFalse( $reuse['used_owner_fallback'], 'No eligible fallback exists here.' );
	}

	/**
	 * The multi-team-owner merge regression (NPPD-2060): an owner who owns a second team
	 * must not have it resolve into the first team's already-migrated group subscription.
	 * With reuse keyed on the owner, the second team merged into the first's group and
	 * inherited its name and seat limit; keyed on the team marker, the second team finds
	 * no reusable group and a fresh one is created for it instead.
	 */
	public function test_second_team_of_owner_does_not_merge_into_first_teams_group() {
		$owner          = $this->create_reader();
		$first_team_id  = $this->create_team( $owner, [] );
		$second_team_id = $this->create_team( $owner, [] );
		// Only the first team has been migrated so far.
		$this->create_migrated_group_subscription( $owner, $first_team_id );

		$reuse = Teams_Migration::find_reusable_group_subscription( $second_team_id, $owner );

		$this->assertNull( $reuse['subscription'], 'The second team must not reuse the first team\'s group subscription — a new group is created for it.' );
		$this->assertFalse( $reuse['used_owner_fallback'], 'A subscription already claimed by another team is not an eligible owner fallback.' );
	}

	/**
	 * Legacy fallback: a group subscription created by a migrator run predating per-team
	 * marking carries no marker, so it is adopted for the team by owner lookup (flagged so
	 * the caller can warn). It is then marked, so a second team of the same owner no longer
	 * matches it.
	 */
	public function test_unmarked_owner_group_is_adopted_as_legacy_fallback() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, [] );
		// A pre-existing, unmarked group subscription (no MIGRATED_TEAM_ID_META_KEY).
		$legacy_group = $this->create_group_subscription( $owner );

		$reuse = Teams_Migration::find_reusable_group_subscription( $team_id, $owner );

		$this->assertSame( $legacy_group->get_id(), $reuse['subscription']->get_id(), 'An unmarked owner-owned group subscription should be adopted.' );
		$this->assertTrue( $reuse['used_owner_fallback'], 'Adopting an unmarked owner subscription must flag the owner fallback so the caller warns.' );
	}

	/**
	 * Reuse ignores subscriptions that carry another team's marker, are inactive, or are
	 * not group-enabled: an owner whose only subscriptions are a different team's marked
	 * group, a cancelled group, and an active non-group sub has no reusable group for a
	 * fresh team.
	 */
	public function test_reuse_ignores_other_team_inactive_and_non_group_subscriptions() {
		$owner            = $this->create_reader();
		$other_team_id    = $this->create_team( $owner, [] );
		$fresh_team_id    = $this->create_team( $owner, [] );
		$this->create_migrated_group_subscription( $owner, $other_team_id );
		$cancelled = wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'cancelled',
				'billing_period' => 'month',
			]
		);
		$cancelled->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		$cancelled->save();
		wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);

		$reuse = Teams_Migration::find_reusable_group_subscription( $fresh_team_id, $owner );

		$this->assertNull(
			$reuse['subscription'],
			'A fresh team should find no reusable group among another team\'s marked group, a cancelled group, and a non-group subscription.'
		);
		$this->assertFalse( $reuse['used_owner_fallback'], 'No eligible fallback exists here.' );
	}

	/**
	 * Register a variable subscription parent and one variation, as a publisher
	 * selling seat tiers would have (NPPD-1876).
	 *
	 * @return array{parent: WC_Product, variation: WC_Product}
	 */
	private function create_variable_subscription_product() {
		$parent = wc_create_mock_product(
			[
				'id'   => 109742,
				'name' => 'Corporate Self Checkout',
				'type' => 'variable-subscription',
			]
		);
		$variation = wc_create_mock_product(
			[
				'id'        => 109751,
				'name'      => 'Corporate Self Checkout - Unlimited',
				'type'      => 'subscription_variation',
				'parent_id' => 109742,
			]
		);
		return [
			'parent'    => $parent,
			'variation' => $variation,
		];
	}

	/**
	 * Call one of the migration's private static creators.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 *
	 * @return mixed
	 */
	private function invoke_private( $method, $args ) {
		$reflection = new ReflectionMethod( Teams_Migration::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * A team migrated against a variation must end up linked to that variation.
	 *
	 * Publishers selling seat tiers hold them as variations of one variable
	 * subscription product, so `--product-id` is routinely given a variation ID.
	 * Assigning that ID straight to the line item's `product_id` is rejected by
	 * WooCommerce and silently dropped, leaving an item linked to nothing — which
	 * then makes WC Subscriptions refuse to activate the subscription (NPPD-1876).
	 */
	public function test_create_migration_subscription_links_a_variation() {
		$owner    = $this->create_reader();
		$products = $this->create_variable_subscription_product();
		$errors   = [];

		$subscription = $this->invoke_private(
			'create_migration_subscription',
			[ $owner, $products['variation'], 'month', 1, '2026-01-01 00:00:00', '', &$errors, 94782 ]
		);

		$this->assertNotNull( $subscription, 'The subscription should be created.' );
		$items = $subscription->get_items();
		$this->assertCount( 1, $items, 'The subscription should carry one line item.' );
		$item = array_shift( $items );

		$this->assertSame( 109742, $item->get_product_id(), 'A variation must be recorded against its parent product ID.' );
		$this->assertSame( 109751, $item->get_variation_id(), 'The variation ID must be recorded on the line item.' );
		$this->assertNotFalse( $item->get_product(), 'The line item must resolve to a product; an unresolvable item blocks activation.' );
		// The name is no longer set explicitly — set_product() carries it over — so
		// hold that behavior in place rather than leaving it implicit.
		$this->assertSame( 'Corporate Self Checkout - Unlimited', $item->get_name(), 'The line item should take its name from the linked product.' );
		$this->assertSame( [], $errors, 'Linking a variation should not record an error.' );
	}

	/**
	 * The same linkage is required when re-using a team's existing subscription.
	 *
	 * This path never calls update_status(), so a dropped product ID raises no
	 * exception — the subscription stays active and reports as migrated while
	 * granting access to nobody, because access is matched by product ID.
	 */
	public function test_replace_subscription_product_links_a_variation() {
		$owner        = $this->create_reader();
		$products     = $this->create_variable_subscription_product();
		$errors       = [];
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);

		// Seed the team's original line item. This method leads with a removal loop,
		// so without a pre-existing item the count assertion below would prove only
		// that one item was added, not that the old one was cleared — and clearing
		// it is the half that decides whether the group grants access.
		$old_product = wc_create_mock_product(
			[
				'id'   => 500,
				'name' => 'Legacy Teams product',
				'type' => 'subscription',
			]
		);
		$old_item = new WC_Order_Item_Product( [ 'id' => 4242 ] );
		$old_item->set_product( $old_product );
		$subscription->add_item( $old_item );
		$this->assertCount( 1, $subscription->get_items(), 'Fixture check: the subscription starts with the legacy item.' );

		$this->invoke_private(
			'replace_subscription_product',
			[ $subscription, $products['variation'], 'month', 1, '2026-01-01 00:00:00', '', &$errors, 94782 ]
		);

		$items = $subscription->get_items();
		$this->assertCount( 1, $items, 'The reused subscription should carry exactly the migration line item.' );
		$item = array_shift( $items );

		$this->assertSame( 109742, $item->get_product_id(), 'A variation must be recorded against its parent product ID.' );
		$this->assertSame( 109751, $item->get_variation_id(), 'The variation ID must be recorded on the line item.' );
		$this->assertNotFalse( $item->get_product(), 'The line item must resolve to a product, or the group grants no access.' );
		$this->assertNotSame( 500, $item->get_product_id(), 'The legacy line item should have been removed, not kept alongside.' );
	}

	/**
	 * A migration product's availability is decided by the parent's status.
	 *
	 * An unpublished product fails activation the same way an unlinked line item
	 * does, so the pre-flight has to resolve a variation to its parent before
	 * judging it.
	 */
	public function test_product_is_published_resolves_a_variation_to_its_parent() {
		$products = $this->create_variable_subscription_product();

		$this->assertTrue(
			$this->invoke_private( 'product_is_published', [ $products['variation'] ] ),
			'A variation under a published parent counts as published.'
		);
		$this->assertTrue(
			$this->invoke_private( 'product_is_published', [ $products['parent'] ] ),
			'A published parent counts as published.'
		);

		$draft_parent = wc_create_mock_product(
			[
				'id'     => 700,
				'name'   => 'Draft parent',
				'type'   => 'variable-subscription',
				'status' => 'draft',
			]
		);
		$draft_child  = wc_create_mock_product(
			[
				'id'        => 701,
				'name'      => 'Variation of a draft parent',
				'type'      => 'subscription_variation',
				'parent_id' => 700,
			]
		);

		$this->assertFalse(
			$this->invoke_private( 'product_is_published', [ $draft_child ] ),
			'A variation whose parent is unpublished must not pass pre-flight — it cannot be activated.'
		);
		$this->assertFalse(
			$this->invoke_private( 'product_is_published', [ $draft_parent ] ),
			'An unpublished product must not pass pre-flight.'
		);
	}

	/**
	 * Gate matching follows has_product(), which accepts a product or its parent.
	 *
	 * A flat comparison against the product's own ID would refuse a seat-tier
	 * variation on a site whose gate names the parent variable product — a
	 * configuration that works perfectly at runtime.
	 */
	public function test_product_grants_gate_access_accepts_a_parent_rule() {
		$products = $this->create_variable_subscription_product();

		$this->assertTrue(
			$this->invoke_private( 'product_grants_gate_access', [ $products['variation'], [ 109742 ] ] ),
			'A gate naming the parent product accepts any of its variations.'
		);
		$this->assertTrue(
			$this->invoke_private( 'product_grants_gate_access', [ $products['variation'], [ 109751 ] ] ),
			'A gate naming the variation itself accepts it.'
		);
		$this->assertTrue(
			$this->invoke_private( 'product_grants_gate_access', [ $products['parent'], [ 109742 ] ] ),
			'A gate naming a plain product accepts it.'
		);
		$this->assertFalse(
			$this->invoke_private( 'product_grants_gate_access', [ $products['variation'], [ 109999 ] ] ),
			'A gate naming only a sibling variation accepts nothing.'
		);
		$this->assertFalse(
			$this->invoke_private( 'product_grants_gate_access', [ $products['parent'], [ 109751 ] ] ),
			'A gate naming a variation does not accept the bare parent product.'
		);
	}

	/**
	 * The re-run guard must recognise a subscription linked to a variation.
	 *
	 * The migrate-manual-members command skips members who already hold a
	 * migration subscription. Since a variation is stored as parent product ID plus
	 * variation ID, matching on the product ID alone never recognised the
	 * command's own output — so each re-run granted every member another $0
	 * subscription instead of skipping them.
	 */
	public function test_member_has_migration_subscription_recognises_a_variation() {
		$user         = $this->create_reader();
		$products     = $this->create_variable_subscription_product();
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $user,
				'status'         => 'active',
				'billing_period' => 'month',
				'created_via'    => 'manual migration',
			]
		);
		$item = new WC_Order_Item_Product();
		$item->set_product( $products['variation'] );
		$subscription->add_item( $item );

		$this->assertTrue(
			$this->invoke_private( 'member_has_migration_subscription', [ $user, 109751 ] ),
			'A member holding a subscription linked to this variation must be recognised, or a re-run duplicates it.'
		);
		$this->assertTrue(
			$this->invoke_private( 'member_has_migration_subscription', [ $user, 109742 ] ),
			'The parent product ID must match too, since the line item records it.'
		);
		$this->assertFalse(
			$this->invoke_private( 'member_has_migration_subscription', [ $user, 109999 ] ),
			'An unrelated product must not match.'
		);
	}

	/**
	 * Whether a reused subscription counts as one the publisher bills.
	 *
	 * This predicate decides whether a team's subscription keeps its commercial
	 * terms or is rewritten to $0 on the migration product, so a false negative
	 * deletes the product line the publisher sells. It errs toward "paid".
	 */
	public function test_subscription_is_paid_errs_toward_paid() {
		$paid = wcs_create_subscription(
			[
				'customer_id'    => $this->create_reader(),
				'status'         => 'active',
				'billing_period' => 'month',
				'total'          => 599,
			]
		);
		$this->assertTrue(
			$this->invoke_private( 'subscription_is_paid', [ $paid ] ),
			'A subscription with a recurring total is one the publisher bills.'
		);

		// A fully-discounted subscription stores a total of 0 but is still sold —
		// the pre-discount subtotal is what distinguishes it from a $0 migration
		// subscription, which has neither.
		$fully_discounted = wcs_create_subscription(
			[
				'customer_id'    => $this->create_reader(),
				'status'         => 'active',
				'billing_period' => 'month',
				'total'          => 0,
				'subtotal'       => 599,
			]
		);
		$this->assertTrue(
			$this->invoke_private( 'subscription_is_paid', [ $fully_discounted ] ),
			'A 100% recurring coupon must not read as free, or migration deletes the product being sold.'
		);

		$migration_created = wcs_create_subscription(
			[
				'customer_id'    => $this->create_reader(),
				'status'         => 'active',
				'billing_period' => 'month',
				'total'          => 0,
				'subtotal'       => 0,
			]
		);
		$this->assertFalse(
			$this->invoke_private( 'subscription_is_paid', [ $migration_created ] ),
			'A $0 subscription with no pre-discount value is a migration subscription and stays re-alignable.'
		);

		$no_total_set = wcs_create_subscription(
			[
				'customer_id'    => $this->create_reader(),
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$this->assertFalse(
			$this->invoke_private( 'subscription_is_paid', [ $no_total_set ] ),
			'A subscription with no total recorded is not treated as paid.'
		);
	}

	/**
	 * Gate coverage for a paid team's own subscription follows has_product().
	 *
	 * A team the publisher bills keeps its own product rather than being rewritten
	 * onto --product-id, so whether its access survives the migration is decided
	 * by whether a gate accepts the product it already holds. A subscription
	 * linked to a seat-tier variation is covered by a gate naming either that
	 * variation or its parent.
	 */
	public function test_subscription_covers_access_products_matches_parent_or_variation() {
		$owner        = $this->create_reader();
		$products     = $this->create_variable_subscription_product();
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$item = new WC_Order_Item_Product();
		$item->set_product( $products['variation'] );
		$subscription->add_item( $item );

		$this->assertTrue(
			$this->invoke_private( 'subscription_covers_access_products', [ $subscription, [ 109742 ] ] ),
			'A gate naming the parent product covers a subscription linked to one of its variations.'
		);
		$this->assertTrue(
			$this->invoke_private( 'subscription_covers_access_products', [ $subscription, [ 109751 ] ] ),
			'A gate naming the variation covers it.'
		);
		$this->assertFalse(
			$this->invoke_private( 'subscription_covers_access_products', [ $subscription, [ 109999 ] ] ),
			'A gate naming an unrelated product covers nothing — this is what makes a paid team skip rather than migrate.'
		);
		$this->assertTrue(
			$this->invoke_private( 'subscription_covers_access_products', [ $subscription, [] ] ),
			'With no gate products configured there is nothing to check against.'
		);
	}

	/**
	 * A plain (non-variation) product still links as before.
	 */
	public function test_create_migration_subscription_links_a_simple_product() {
		$owner   = $this->create_reader();
		$product = wc_create_mock_product(
			[
				'id'   => 500,
				'name' => 'Group Subscription',
				'type' => 'subscription',
			]
		);
		$errors = [];

		$subscription = $this->invoke_private(
			'create_migration_subscription',
			[ $owner, $product, 'month', 1, '2026-01-01 00:00:00', '', &$errors, 94783 ]
		);

		$this->assertNotNull( $subscription, 'The subscription should be created.' );
		$this->assertSame( [], $errors, 'Linking a plain product should not record an error.' );

		$items = $subscription->get_items();
		$this->assertCount( 1, $items, 'The subscription should carry one line item.' );
		$item = array_shift( $items );

		$this->assertSame( 500, $item->get_product_id(), 'A non-variation product links by product ID.' );
		$this->assertSame( 0, $item->get_variation_id(), 'A non-variation product has no variation ID.' );
		$this->assertNotFalse( $item->get_product(), 'The line item must resolve to a product.' );
	}
}
