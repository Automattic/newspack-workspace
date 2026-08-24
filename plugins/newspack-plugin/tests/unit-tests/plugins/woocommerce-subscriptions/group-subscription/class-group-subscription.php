<?php
/**
 * Tests for Group_Subscription member-count helpers.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\Group_Subscription_Invite;

/**
 * Test Group_Subscription member counting (managers/owner are counted as members).
 */
class Test_Group_Subscription extends WP_UnitTestCase {

	/**
	 * Track created user IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		// Include WC mocks.
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Set up: reset subscriptions and products databases.
	 */
	public function set_up() {
		parent::set_up();
		// The metabox render is gated behind the Access Control feature flag.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		$this->user_ids         = [];
	}

	/**
	 * Tear down: reset subscriptions and products databases and delete users.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		parent::tear_down();
	}

	/**
	 * Create a reader user and track it for cleanup.
	 *
	 * @return int The new user ID.
	 */
	private function create_reader_user(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'reader-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'reader-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		if ( ! is_wp_error( $user_id ) ) {
			update_user_meta( $user_id, '_newspack_reader', true );
			$this->user_ids[] = $user_id;
		}
		return $user_id;
	}

	/**
	 * Create an enabled group subscription owned by $customer_id, optionally with a member limit.
	 *
	 * @param int      $customer_id The owner user ID.
	 * @param int|null $limit       Optional member limit to set on the subscription.
	 *
	 * @return WC_Subscription
	 */
	private function create_group_subscription( int $customer_id, $limit = null ): WC_Subscription {
		$sub = wcs_create_subscription(
			[
				'customer_id'    => $customer_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		if ( null !== $limit ) {
			$sub->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', (string) $limit );
		}
		return $sub;
	}

	/**
	 * Add $member_id as a member of $subscription.
	 *
	 * @param int             $member_id    The user ID to add as a member.
	 * @param WC_Subscription $subscription The group subscription.
	 */
	private function add_member( int $member_id, WC_Subscription $subscription ): void {
		add_user_meta( $member_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
	}

	/**
	 * Seed an email invite on $subscription.
	 *
	 * @param string          $email        The invited email address.
	 * @param WC_Subscription $subscription The group subscription.
	 * @param bool            $expired      Whether the invite should already be expired (defaults to pending).
	 */
	private function add_invite( string $email, WC_Subscription $subscription, bool $expired = false ): void {
		$invites = $subscription->get_meta( Group_Subscription_Invite::META, true );
		if ( ! is_array( $invites ) ) {
			$invites = [];
		}
		// Mirror production storage: keyed by a random invite key, value carries the email + expiry.
		$invites[ 'test-key-' . $email ] = [
			'added_by'   => 0,
			'email'      => $email,
			'expiration' => $expired ? time() - HOUR_IN_SECONDS : time() + HOUR_IN_SECONDS,
		];
		$subscription->update_meta_data( Group_Subscription_Invite::META, $invites );
		// Persist, so a test that re-fetches the subscription by ID still sees the invite rather
		// than relying on the same in-memory instance being threaded through every call.
		$subscription->save();
	}

	/**
	 * The owner counts as a member even when there are no other members.
	 */
	public function test_member_count_includes_owner_when_only_member() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id );

		$this->assertSame(
			1,
			Group_Subscription::get_member_count( $sub ),
			'A group whose only member is the owner should report a count of 1, not 0.'
		);
	}

	/**
	 * The owner is counted alongside added members.
	 */
	public function test_member_count_includes_owner_and_members() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id );
		$this->add_member( $this->create_reader_user(), $sub );
		$this->add_member( $this->create_reader_user(), $sub );

		$this->assertSame(
			3,
			Group_Subscription::get_member_count( $sub ),
			'Two added members plus the owner should report a count of 3.'
		);
	}

	/**
	 * The get_all_members() helper returns owner + members, de-duplicated, without empty IDs.
	 */
	public function test_get_all_members_returns_owner_and_members() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$sub       = $this->create_group_subscription( $owner_id );
		$this->add_member( $member_id, $sub );

		$all = Group_Subscription::get_all_members( $sub );
		sort( $all );
		$expected = [ $owner_id, $member_id ];
		sort( $expected );

		$this->assertSame( $expected, $all, 'get_all_members should return the owner and member IDs.' );
	}

	/**
	 * A user who is both the owner and carries member meta is only counted once.
	 */
	public function test_member_count_dedupes_owner_with_member_meta() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id );
		// Owner also carries member meta (edge case).
		$this->add_member( $owner_id, $sub );
		$this->add_member( $this->create_reader_user(), $sub );

		$this->assertSame(
			2,
			Group_Subscription::get_member_count( $sub ),
			'The owner should be counted once even if they also carry member meta.'
		);
	}

	/**
	 * Capacity is the configured limit, which now counts the owner: the owner sits
	 * inside the limit rather than being a free seat on top of it.
	 */
	public function test_member_capacity_is_the_configured_limit() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 10 );

		$this->assertSame(
			10,
			Group_Subscription::get_member_capacity( $sub ),
			'A limit of 10 is the total capacity including the owner.'
		);
	}

	/**
	 * Capacity is null when there is no limit (unlimited).
	 */
	public function test_member_capacity_null_when_unlimited() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 0 );

		$this->assertNull(
			Group_Subscription::get_member_capacity( $sub ),
			'A limit of 0 (unlimited) should yield a null capacity.'
		);
	}

	/**
	 * The subscriptions list table counts seats, not members: the owner alone fills
	 * one of the group's seats without being a member, so a limit-of-4 group with no
	 * other people reads "1 of 4 seats".
	 */
	public function test_admin_column_label_counts_seats_not_members() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 4 );

		$this->assertStringContainsString(
			'1 of 4 seats',
			Group_Subscription_Settings::filter_subscription_column_content( '', $sub, 'order_title' ),
			'An owner-only group with a limit of 4 should read "1 of 4 seats".'
		);
	}

	/**
	 * An unlimited group has no seat total to count against, so the capacity reads
	 * "unlimited" in the same seat phrasing.
	 */
	public function test_admin_column_label_reads_unlimited_seats_without_a_limit() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 0 );
		$this->add_member( $this->create_reader_user(), $sub );

		$this->assertStringContainsString(
			'2 of unlimited seats',
			Group_Subscription_Settings::filter_subscription_column_content( '', $sub, 'order_title' ),
			'An unlimited group holding the owner and one member should read "2 of unlimited seats".'
		);
	}

	/**
	 * Capacity is the limit whether or not the group has an owner: the owner is one
	 * of the limited seats, not an extra one, so an ownerless group reads "0 of limit"
	 * exactly as an owned one would.
	 */
	public function test_member_capacity_is_the_limit_when_ownerless() {
		// customer_id 0 -> get_managers() returns [0], an empty/phantom owner.
		$sub = $this->create_group_subscription( 0, 10 );

		$this->assertSame(
			0,
			Group_Subscription::get_member_count( $sub ),
			'An ownerless group with no members should report a count of 0.'
		);
		$this->assertSame(
			10,
			Group_Subscription::get_member_capacity( $sub ),
			'Capacity is the limit (10), independent of whether an owner occupies a seat.'
		);
	}

	/**
	 * A pending (non-expired) invite reserves a spot, so a direct member add that would push
	 * members + pending invites past the limit is rejected -- matching the invite path's own check.
	 */
	public function test_update_members_counts_pending_invites_toward_limit() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$sub       = $this->create_group_subscription( $owner_id, 2 ); // Owner + one member seat.
		$this->add_invite( 'pending@test.com', $sub );

		// The pending invite already fills the single member seat, so a direct add must be rejected.
		$result = Group_Subscription::update_members( $sub, [ $member_id ] );
		$this->assertWPError( $result, 'A direct add should be blocked when a pending invite already fills the limit.' );
		$this->assertSame( 409, $result->get_error_data()['status'], 'The limit rejection should carry a 409 status.' );
		$this->assertNotContains( $member_id, Group_Subscription::get_members( $sub ), 'No member should have been added.' );
	}

	/**
	 * Adding a user who holds a pending invite must not double-count their own invite against the
	 * limit -- the add fulfils that invite, so it is excluded from the count. This is what lets an
	 * invite be accepted (or the invited user be added directly) when the group is at its limit.
	 */
	public function test_update_members_adding_invited_user_does_not_double_count_their_invite() {
		$owner_id = $this->create_reader_user();
		$invitee  = $this->create_reader_user();
		$email    = get_userdata( $invitee )->user_email;
		$sub      = $this->create_group_subscription( $owner_id, 2 );
		$this->add_invite( $email, $sub );

		// The single member seat is "reserved" by the invitee's own pending invite; adding the invitee
		// fulfils it, so the add must succeed rather than double-count them.
		$result = Group_Subscription::update_members( $sub, [ $invitee ] );
		$this->assertNotWPError( $result, 'Adding a user who holds the last pending invite should succeed.' );
		// get_members() may return IDs as strings (raw from meta), so compare after casting.
		$this->assertContains( (int) $invitee, array_map( 'intval', Group_Subscription::get_members( $sub ) ), 'The invitee should now be a member.' );
	}

	/**
	 * A direct add fulfils any matching pending invite, so the invite is cancelled -- otherwise the
	 * now-member and their stale invite would both count toward the limit (the invite-acceptance path
	 * cancels on add; a direct add via the /members endpoint must do the same).
	 */
	public function test_update_members_direct_add_cancels_matching_invite() {
		$owner_id = $this->create_reader_user();
		$invitee  = $this->create_reader_user();
		$email    = get_userdata( $invitee )->user_email;
		$sub      = $this->create_group_subscription( $owner_id, 5 );
		$this->add_invite( $email, $sub );

		$result = Group_Subscription::update_members( $sub, [ $invitee ] );
		$this->assertNotWPError( $result, 'Adding an invited user directly should succeed.' );
		$this->assertContains( (int) $invitee, array_map( 'intval', Group_Subscription::get_members( $sub ) ), 'The invitee should now be a member.' );
		$this->assertEmpty(
			Group_Subscription_Invite::get_invites( $sub ),
			'The fulfilled invite should be cancelled once the invitee is added directly.'
		);
	}

	/**
	 * The owner occupies one of the limited seats, so a limit of N leaves N-1 member
	 * seats. Filling them is allowed; the next add is rejected with a 409.
	 */
	public function test_owner_counts_against_the_member_limit() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 2 ); // Owner + one member seat.

		$this->assertSame(
			1,
			Group_Subscription::get_member_seat_limit( $sub ),
			'A limit of 2 leaves a single member seat once the owner is reserved.'
		);

		$member_id = $this->create_reader_user();
		$first     = Group_Subscription::update_members( $sub, [ $member_id ] );
		$this->assertFalse( is_wp_error( $first ), 'The single non-owner seat can be filled.' );
		$this->assertSame(
			2,
			Group_Subscription::get_member_count( $sub ),
			'Owner + one member exactly fills the two-seat group.'
		);

		$overflow = Group_Subscription::update_members( $sub, [ $this->create_reader_user() ] );
		$this->assertTrue( is_wp_error( $overflow ), 'Adding past the owner-inclusive limit is rejected.' );
		$this->assertSame( 409, $overflow->get_error_data()['status'] );
	}

	/**
	 * A positive limit is floored to the 2-seat minimum on save, so a group always
	 * has room for at least one member besides the owner. Unlimited (0) is preserved.
	 */
	public function test_positive_limit_is_floored_to_two_on_save() {
		$owner_id = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id );

		Group_Subscription_Settings::update_subscription_settings( $sub, [ 'limit' => 1 ] );
		$this->assertSame(
			2,
			Group_Subscription_Settings::get_subscription_settings( $sub )['limit'],
			'A limit of 1 is raised to the two-seat minimum.'
		);

		Group_Subscription_Settings::update_subscription_settings( $sub, [ 'limit' => 0 ] );
		$this->assertSame(
			0,
			Group_Subscription_Settings::get_subscription_settings( $sub )['limit'],
			'Unlimited (0) is left untouched by the floor.'
		);
	}

	/**
	 * The limit is projected from the IDs that would genuinely become members, not from the raw
	 * batch: an ID the add would skip anyway (an existing member, a non-reader) takes no seat, so
	 * counting it would reject an add that in fact fits.
	 */
	public function test_update_members_ignores_non_addable_ids_when_projecting_the_limit() {
		$owner_id         = $this->create_reader_user();
		$existing_member  = $this->create_reader_user();
		$new_member       = $this->create_reader_user();
		// An editor is not a reader: reader status falls back to the reader roles (subscriber,
		// customer) when the reader meta is absent, so a plain no-meta subscriber would still pass.
		$non_reader       = wp_insert_user(
			[
				'user_login' => 'not-a-reader-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'not-a-reader-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'editor',
			]
		);
		$this->user_ids[] = $non_reader;

		$sub = $this->create_group_subscription( $owner_id, 3 ); // Owner + two member seats.
		$this->add_member( $existing_member, $sub );

		// Only $new_member takes a seat, so the batch fits the one remaining seat -- even though its
		// three IDs would overshoot a limit projected from the unfiltered count.
		$result = Group_Subscription::update_members( $sub, [ $existing_member, $non_reader, $new_member ] );
		$this->assertNotWPError( $result, 'A batch whose only real addition fits the limit must not be rejected.' );
		$this->assertSame(
			[ $new_member ],
			array_keys( $result['members_added'] ),
			'Only the genuinely-addable reader should be reported as added.'
		);
		$this->assertContains( (int) $new_member, array_map( 'intval', Group_Subscription::get_members( $sub ) ), 'The new member should have been added.' );
	}

	/**
	 * The limit check only bounds additions, so a removal-only call must succeed even on a group that
	 * is already over its limit (e.g. after the limit was lowered) -- a removal can never push a group
	 * further over capacity, and rejecting it would strand the already-persisted removal.
	 */
	public function test_update_members_removal_only_succeeds_on_over_limit_group() {
		$owner_id = $this->create_reader_user();
		$member_a = $this->create_reader_user();
		$member_b = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 2 ); // Limit of 2 (one member seat), but seed two members (over capacity).
		$this->add_member( $member_a, $sub );
		$this->add_member( $member_b, $sub );

		$result = Group_Subscription::update_members( $sub, [], [ $member_a ] );
		$this->assertNotWPError( $result, 'A removal-only call must succeed even on an over-limit group.' );
		$this->assertArrayHasKey( $member_a, $result['members_removed'], 'The removed member should be reported.' );
		$this->assertNotContains( (int) $member_a, array_map( 'intval', Group_Subscription::get_members( $sub ) ), 'The removed member should no longer be a member.' );
	}

	/**
	 * Expired invites are excluded from the count via get_invites( $sub, false ), so an expired invite
	 * does not reserve a spot -- a direct add succeeds when only expired invites stand between the
	 * group and its limit.
	 */
	public function test_update_members_expired_invite_does_not_consume_a_spot() {
		$owner_id  = $this->create_reader_user();
		$member_id = $this->create_reader_user();
		$sub       = $this->create_group_subscription( $owner_id, 2 ); // Owner + one member seat.
		$this->add_invite( 'expired@test.com', $sub, true ); // Expired -- must not reserve the spot.

		$result = Group_Subscription::update_members( $sub, [ $member_id ] );
		$this->assertNotWPError( $result, 'An expired invite must not reserve a spot, so the direct add should succeed.' );
		$this->assertContains( (int) $member_id, array_map( 'intval', Group_Subscription::get_members( $sub ) ), 'The member should have been added.' );
	}

	/**
	 * The floor also applies on read, so a limit of 1 already stored under the earlier
	 * "members in addition to the owner" meaning still leaves one usable member seat
	 * instead of zero. Without this, such a group silently rejects its first member.
	 */
	public function test_stored_limit_of_one_is_floored_on_read() {
		$owner_id = $this->create_reader_user();
		// Writes the meta directly, as a group saved before the limit became owner-inclusive would carry it.
		$sub = $this->create_group_subscription( $owner_id, 1 );

		$this->assertSame(
			2,
			Group_Subscription_Settings::get_subscription_settings( $sub )['limit'],
			'A stored limit of 1 reads as the two-seat minimum without a re-save.'
		);
		$this->assertSame(
			1,
			Group_Subscription::get_member_seat_limit( $sub ),
			'The floored limit leaves one member seat, not zero.'
		);

		$added = Group_Subscription::update_members( $sub, [ $this->create_reader_user() ] );
		$this->assertFalse( is_wp_error( $added ), 'The first member of an otherwise-empty group is not rejected as over limit.' );
	}

	/**
	 * An invite whose stored address differs in case from the member's account email is still
	 * cancelled by a direct add. sanitize_email() preserves case and wp_insert_user() does not
	 * lowercase user_email, so the two can legitimately differ -- and a case-sensitive match would
	 * leave a phantom invite consuming a seat for a reader who is already a member.
	 */
	public function test_update_members_cancels_a_case_mismatched_invite() {
		$owner_id = $this->create_reader_user();
		$invitee  = $this->create_reader_user();
		$sub      = $this->create_group_subscription( $owner_id, 5 );
		$this->add_invite( strtoupper( get_userdata( $invitee )->user_email ), $sub );

		$result = Group_Subscription::update_members( $sub, [ $invitee ] );
		$this->assertNotWPError( $result, 'Adding an invited user directly should succeed.' );
		$this->assertEmpty(
			Group_Subscription_Invite::get_invites( $sub ),
			'A case-mismatched invite must be cancelled too, or it keeps consuming a seat.'
		);
		$this->assertCount( 1, $result['invites_cancelled'], 'The cancelled invite should be reported back to the caller.' );
	}

	/**
	 * The admin metabox must gate on the same threshold the server enforces, or an admin is shown an
	 * add form whose submission update_members() then rejects with a 409 -- the bug this whole
	 * feature exists to remove. Rendering it is also the only coverage of the callback itself.
	 *
	 * @param int    $limit           The configured (owner-inclusive) member limit.
	 * @param int    $members_to_seed How many members to seed besides the owner.
	 * @param bool   $expect_at_limit Whether the metabox should render as at-limit.
	 * @param string $expected_limit  The expected data-member-limit attribute value.
	 *
	 * @dataProvider metabox_limit_states
	 */
	public function test_metabox_gates_on_the_member_seat_limit( $limit, $members_to_seed, $expect_at_limit, $expected_limit ) {
		$sub = $this->create_group_subscription( $this->create_reader_user(), $limit );
		for ( $i = 0; $i < $members_to_seed; $i++ ) {
			$this->add_member( $this->create_reader_user(), $sub );
		}

		ob_start();
		Group_Subscription_Settings::add_group_subscription_options( $sub );
		$markup = ob_get_clean();

		$this->assertNotEmpty( $markup, 'The metabox callback must render (a fatal here takes down the whole edit screen).' );
		$this->assertStringContainsString(
			'data-member-limit="' . $expected_limit . '"',
			$markup,
			'data-member-limit carries the member-seat limit, which is what the admin JS re-tallies against.'
		);
		$this->assertSame(
			$expect_at_limit,
			false !== strpos( $markup, 'newspack-group-subscription__container is-at-limit' ),
			'The at-limit state must match what update_members() would do with the same group.'
		);
		// The add form is gated by the `hidden` attribute rather than by a stylesheet rule, so the
		// initial state holds even if the CSS never loads.
		$this->assertSame(
			$expect_at_limit,
			false !== strpos( $markup, 'newspack-group-subscription__add-member show_if_newspack_group_subscription_enabled form-row" hidden' ),
			'The add-member form is hidden server-side exactly when the group is at its limit.'
		);
	}

	/**
	 * Metabox limit states: a limit of 2 leaves one member seat, so one member fills the group.
	 *
	 * @return array[] limit, members to seed, expected at-limit, expected data-member-limit.
	 */
	public function metabox_limit_states() {
		return [
			'empty two-seat group'  => [ 2, 0, false, '1' ],
			'full two-seat group'   => [ 2, 1, true, '1' ],
			'roomy five-seat group' => [ 5, 1, false, '4' ],
			'unlimited group'       => [ 0, 3, false, '' ],
		];
	}
}
