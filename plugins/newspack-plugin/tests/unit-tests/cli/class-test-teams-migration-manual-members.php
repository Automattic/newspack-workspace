<?php
/**
 * Tests for the migrate-manual-members CLI extensions that make purchase plans
 * safely targetable (NPPD-2055).
 *
 * The comp/legacy parity residual class is "membership active, but no LIVE
 * subscription" — that covers both members with no subscription at all and
 * members whose subscriptions exist only in dead states (cancelled/expired),
 * which on several sites is the larger cohort. These tests pin the member
 * selection: live-status holders (active/on-hold/pending-cancel) are skipped
 * and counted, dead-status and subscription-less members are included, the
 * explicit --user-ids input mode reconciles its list, and the guard rails
 * (purchase-plan refusal, --skip-domains, re-run idempotency) hold.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Teams_Migration;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Test migrate-manual-members' member-selection logic for comp/legacy parity
 * residuals on purchase plans.
 *
 * @group teams-migration
 */
class Test_Teams_Migration_Manual_Members extends WP_UnitTestCase {

	/**
	 * The mock product subscriptions are created against.
	 *
	 * @var int
	 */
	const MIGRATION_PRODUCT_ID = 909001;

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Include the WC and WP-CLI mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
		// Provides the guarded WC_Memberships_User_Membership stub — the class the
		// command's WCM pre-flight checks for.
		require_once dirname( __DIR__, 2 ) . '/mocks/teams-for-memberships-membership-mocks.php';
	}

	/**
	 * Reset the mock stores and stage the migration product.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		WP_CLI::reset();
		Group_Subscription::reset_cache();
		// The membership fixtures use WCM's custom post status; register it so the
		// explicit post_status query in the command resolves it like on a live site.
		register_post_status( 'wcm-active' );
		wc_create_mock_product(
			[
				'id'   => self::MIGRATION_PRODUCT_ID,
				'name' => 'Migration membership product',
			]
		);
	}

	/**
	 * Clean up fixtures.
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
	 * Create a membership plan with the given WCM access method.
	 *
	 * @param string $access_method 'manual-only', 'purchase', or 'signup'.
	 * @return int Plan post ID.
	 */
	private function create_plan( string $access_method ): int {
		$plan_id = wp_insert_post(
			[
				'post_type'   => 'wc_membership_plan',
				'post_status' => 'publish',
				'post_title'  => ucfirst( $access_method ) . ' plan',
			]
		);
		$this->assertNotWPError( $plan_id, 'Fixture plan creation should succeed.' );
		update_post_meta( $plan_id, '_access_method', $access_method );
		return $plan_id;
	}

	/**
	 * Create a subscriber user with an active membership on the given plan.
	 *
	 * @param int         $plan_id Plan post ID.
	 * @param string|null $email   Optional explicit email address.
	 * @return int User ID.
	 */
	private function create_member( int $plan_id, ?string $email = null ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'member-' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $email ?? 'member-' . wp_generate_password( 8, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		$this->user_ids[] = $user_id;
		// Mark the member as a reader so the group data layer (used by the
		// group-membership live check and --as-group mode) accepts them.
		update_user_meta( $user_id, '_newspack_reader', true );
		$this->create_membership( $plan_id, $user_id );
		return $user_id;
	}

	/**
	 * Create an additional active membership on a plan for an existing user.
	 *
	 * @param int $plan_id Plan post ID.
	 * @param int $user_id User ID.
	 * @return int Membership post ID.
	 */
	private function create_membership( int $plan_id, int $user_id ): int {
		$membership_id = wp_insert_post(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_parent' => $plan_id,
				'post_author' => $user_id,
				'post_title'  => 'Membership for user ' . $user_id,
			]
		);
		$this->assertNotWPError( $membership_id, 'Fixture membership creation should succeed.' );
		update_post_meta( $membership_id, '_start_date', gmdate( 'Y-m-d H:i:s', strtotime( '-6 months' ) ) );
		return $membership_id;
	}

	/**
	 * Create a reader user with no membership (e.g. a group owner).
	 *
	 * @return int User ID.
	 */
	private function create_reader_user(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'reader-' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'reader-' . wp_generate_password( 8, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture reader creation should succeed.' );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * Stage a subscription owned by the user in the given status.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  Subscription status (unprefixed).
	 * @return WC_Subscription
	 */
	private function create_subscription_with_status( int $user_id, string $status ) {
		return wcs_create_subscription(
			[
				'customer_id'    => $user_id,
				'status'         => $status,
				'billing_period' => 'month',
			]
		);
	}

	/**
	 * Run the migrate-manual-members command with the given flags and return the
	 * full recorded output.
	 *
	 * @param array $assoc_args Flags, merged over the default --product-id.
	 * @return string
	 */
	private function run_migrate_manual_members( array $assoc_args ): string {
		$command = new Teams_Migration();
		$command->migrate_manual_members( [], array_merge( [ 'product-id' => self::MIGRATION_PRODUCT_ID ], $assoc_args ) );
		return implode( "\n", WP_CLI::$output );
	}

	/**
	 * IDs of migration-created ($0, created_via 'manual migration') subscriptions
	 * owned by the user.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	private function get_migration_subscription_ids_for_user( int $user_id ): array {
		global $subscriptions_database;
		$migration_subscription_ids = [];
		foreach ( $subscriptions_database as $subscription_id => $subscription ) {
			if ( (int) $subscription->get_customer_id() === $user_id && 'manual migration' === $subscription->get_created_via() ) {
				$migration_subscription_ids[] = $subscription_id;
			}
		}
		return $migration_subscription_ids;
	}

	/**
	 * The core residual selection: with --only-without-live-subscription, members
	 * holding a subscription in a live status (active, on-hold, pending-cancel)
	 * are skipped; members whose subscriptions are all dead (cancelled, expired)
	 * and members with no subscription at all get a $0 subscription. The skip
	 * count is reported so the run reconciles against the parity diff.
	 */
	public function test_live_status_holders_are_skipped_dead_status_and_no_sub_members_are_included() {
		$purchase_plan_id       = $this->create_plan( 'purchase' );
		$member_with_active     = $this->create_member( $purchase_plan_id );
		$member_with_on_hold    = $this->create_member( $purchase_plan_id );
		$member_pending_cancel  = $this->create_member( $purchase_plan_id );
		$member_with_cancelled  = $this->create_member( $purchase_plan_id );
		$member_with_expired    = $this->create_member( $purchase_plan_id );
		$member_without_any_sub = $this->create_member( $purchase_plan_id );

		$this->create_subscription_with_status( $member_with_active, 'active' );
		$this->create_subscription_with_status( $member_with_on_hold, 'on-hold' );
		$this->create_subscription_with_status( $member_pending_cancel, 'pending-cancel' );
		$this->create_subscription_with_status( $member_with_cancelled, 'cancelled' );
		$this->create_subscription_with_status( $member_with_expired, 'expired' );

		$output = $this->run_migrate_manual_members(
			[
				'plan-ids'                       => (string) $purchase_plan_id,
				'only-without-live-subscription' => true,
				'live'                           => true,
			]
		);

		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $member_with_active ), 'A member with an active subscription must be skipped.' );
		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $member_with_on_hold ), 'A member with an on-hold subscription must be skipped.' );
		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $member_pending_cancel ), 'A member with a pending-cancel subscription must be skipped.' );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $member_with_cancelled ), 'A member whose only subscription is cancelled must get a $0 subscription.' );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $member_with_expired ), 'A member whose only subscription is expired must get a $0 subscription.' );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $member_without_any_sub ), 'A member with no subscription must get a $0 subscription.' );
		$this->assertStringContainsString( 'Skipped 3 member(s) holding a live (active/on-hold/pending-cancel) subscription.', $output, 'The skipped-because-subscribed count must be reported for reconciliation.' );
	}

	/**
	 * Dry-run (no --live) reports the same decisions — would-create lines and the
	 * live-subscription skip count — but writes nothing.
	 */
	public function test_dry_run_reports_decisions_and_writes_nothing() {
		$purchase_plan_id       = $this->create_plan( 'purchase' );
		$member_with_active     = $this->create_member( $purchase_plan_id );
		$member_with_cancelled  = $this->create_member( $purchase_plan_id );
		$member_without_any_sub = $this->create_member( $purchase_plan_id );

		$this->create_subscription_with_status( $member_with_active, 'active' );
		$this->create_subscription_with_status( $member_with_cancelled, 'cancelled' );

		global $subscriptions_database;
		$staged_subscription_count = count( $subscriptions_database );

		$output = $this->run_migrate_manual_members(
			[
				'plan-ids'                       => (string) $purchase_plan_id,
				'only-without-live-subscription' => true,
			]
		);

		$this->assertCount( $staged_subscription_count, $subscriptions_database, 'A dry-run must not create any subscription.' );
		$this->assertSame( 2, substr_count( $output, '[DRY RUN] Would create subscription' ), 'Both includable members should be reported as would-create.' );
		$this->assertStringContainsString( 'Skipped 1 member(s) holding a live (active/on-hold/pending-cancel) subscription.', $output, 'The dry-run must report the skip count for reconciliation.' );
	}

	/**
	 * Pointing the command at a plan that is not manual-only without one of the
	 * new selection flags is refused — it would create $0 subscriptions for every
	 * active member, including real paying subscribers.
	 */
	public function test_purchase_plan_without_selection_flags_is_refused() {
		$purchase_plan_id = $this->create_plan( 'purchase' );
		$paying_member    = $this->create_member( $purchase_plan_id );
		$this->create_subscription_with_status( $paying_member, 'active' );

		$refused = false;
		try {
			$this->run_migrate_manual_members(
				[
					'plan-ids' => (string) $purchase_plan_id,
					'live'     => true,
				]
			);
		} catch ( WP_CLI_Mock_Exception $abort ) {
			$refused = true;
			$this->assertStringContainsString( 'manual-only', $abort->getMessage(), 'The refusal must explain the manual-only restriction and the safe flags.' );
		}
		$this->assertTrue( $refused, 'Targeting a purchase plan without a selection flag must abort.' );
		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $paying_member ), 'No subscription may be created on a refused run.' );
	}

	/**
	 * The original manual-only flow is unchanged: no new flags needed, members
	 * get their $0 subscriptions.
	 */
	public function test_manual_only_plans_still_migrate_without_new_flags() {
		$manual_plan_id = $this->create_plan( 'manual-only' );
		$manual_member  = $this->create_member( $manual_plan_id );

		$this->run_migrate_manual_members(
			[
				'plan-ids' => (string) $manual_plan_id,
				'live'     => true,
			]
		);

		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $manual_member ), 'A manual-only plan member must still be migrated without the new flags.' );
	}

	/**
	 * With --only-without-live-subscription and no --plan-ids, all published
	 * plans are in scope (not just manual-only ones) — the residuals this flag
	 * targets live on purchase plans.
	 */
	public function test_only_without_live_subscription_defaults_to_all_published_plans() {
		$purchase_plan_id       = $this->create_plan( 'purchase' );
		$member_without_any_sub = $this->create_member( $purchase_plan_id );

		$this->run_migrate_manual_members(
			[
				'only-without-live-subscription' => true,
				'live'                           => true,
			]
		);

		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $member_without_any_sub ), 'A purchase-plan member must be reachable without --plan-ids when the selection flag is set.' );
	}

	/**
	 * Explicit input mode: only members on the reviewed --user-ids list are
	 * processed — including one whose subscription exists in a dead state — and
	 * user IDs that never match an active membership are reported so the list
	 * reconciles.
	 */
	public function test_user_ids_mode_targets_only_listed_members_including_dead_sub_holders() {
		$purchase_plan_id        = $this->create_plan( 'purchase' );
		$listed_member_no_sub    = $this->create_member( $purchase_plan_id );
		$listed_member_cancelled = $this->create_member( $purchase_plan_id );
		$unlisted_member         = $this->create_member( $purchase_plan_id );
		$unknown_user_id         = 99999999;

		$this->create_subscription_with_status( $listed_member_cancelled, 'cancelled' );

		$output = $this->run_migrate_manual_members(
			[
				'plan-ids' => (string) $purchase_plan_id,
				'user-ids' => implode( ',', [ $listed_member_no_sub, $listed_member_cancelled, $unknown_user_id ] ),
				'live'     => true,
			]
		);

		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $listed_member_no_sub ), 'A listed member without a subscription must get a $0 subscription.' );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $listed_member_cancelled ), 'A listed member with only a cancelled subscription must get a $0 subscription.' );
		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $unlisted_member ), 'A member not on the list must be untouched.' );
		$this->assertStringContainsString( 'not found among active members of the processed plan(s)', $output, 'Unmatched user IDs must be reported for reconciliation.' );
		$this->assertStringContainsString( (string) $unknown_user_id, $output, 'The unmatched user ID must be listed.' );
	}

	/**
	 * --skip-domains still applies in user-ids mode: a listed member whose email
	 * domain is on the skip list gets nothing.
	 */
	public function test_user_ids_mode_respects_skip_domains() {
		$purchase_plan_id      = $this->create_plan( 'purchase' );
		$staff_listed_member   = $this->create_member( $purchase_plan_id, 'staffer@skip.org' );
		$regular_listed_member = $this->create_member( $purchase_plan_id );

		$output = $this->run_migrate_manual_members(
			[
				'plan-ids'     => (string) $purchase_plan_id,
				'user-ids'     => implode( ',', [ $staff_listed_member, $regular_listed_member ] ),
				'skip-domains' => 'skip.org',
				'live'         => true,
			]
		);

		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $staff_listed_member ), 'A listed member on a skipped domain must not get a subscription.' );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $regular_listed_member ), 'The other listed member must still be migrated.' );
		$this->assertStringContainsString( 'domain in skip list', $output, 'The domain skip must be reported.' );
	}

	/**
	 * Re-running in user-ids mode is idempotent: the created_via guard skips a
	 * member who already holds an active migration-created subscription for the
	 * product, so no duplicate $0 subscriptions stack.
	 */
	public function test_user_ids_mode_rerun_is_idempotent() {
		$purchase_plan_id     = $this->create_plan( 'purchase' );
		$listed_member_no_sub = $this->create_member( $purchase_plan_id );

		$flags = [
			'plan-ids' => (string) $purchase_plan_id,
			'user-ids' => (string) $listed_member_no_sub,
			'live'     => true,
		];
		$this->run_migrate_manual_members( $flags );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $listed_member_no_sub ), 'The first run must create the subscription.' );

		WP_CLI::reset();
		$second_run_output = $this->run_migrate_manual_members( $flags );

		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $listed_member_no_sub ), 'The second run must not stack a duplicate subscription.' );
		$this->assertStringContainsString( 'already has an active migration subscription', $second_run_output, 'The idempotency skip must be reported.' );
	}

	/**
	 * The live-status classifier: live statuses (active, on-hold, pending-cancel)
	 * are detected, dead statuses (cancelled, expired) and no subscription at all
	 * are not, and one live subscription among dead ones still counts.
	 */
	public function test_member_has_live_subscription_status_matrix() {
		$purchase_plan_id = $this->create_plan( 'purchase' );

		foreach ( [ 'active', 'on-hold', 'pending-cancel' ] as $live_status ) {
			$member_with_live_status = $this->create_member( $purchase_plan_id );
			$this->create_subscription_with_status( $member_with_live_status, $live_status );
			$this->assertTrue( Teams_Migration::member_has_live_subscription( $member_with_live_status ), sprintf( 'A %s subscription is live.', $live_status ) );
		}

		foreach ( [ 'cancelled', 'expired' ] as $dead_status ) {
			$member_with_dead_status = $this->create_member( $purchase_plan_id );
			$this->create_subscription_with_status( $member_with_dead_status, $dead_status );
			$this->assertFalse( Teams_Migration::member_has_live_subscription( $member_with_dead_status ), sprintf( 'A %s subscription is not live.', $dead_status ) );
		}

		$member_without_any_sub = $this->create_member( $purchase_plan_id );
		$this->assertFalse( Teams_Migration::member_has_live_subscription( $member_without_any_sub ), 'No subscription at all is not live.' );

		$member_with_mixed_subs = $this->create_member( $purchase_plan_id );
		$this->create_subscription_with_status( $member_with_mixed_subs, 'cancelled' );
		$this->create_subscription_with_status( $member_with_mixed_subs, 'active' );
		$this->assertTrue( Teams_Migration::member_has_live_subscription( $member_with_mixed_subs ), 'One live subscription among dead ones still counts as live.' );
	}

	/**
	 * The user-ids input parser: accepts a CSV string, a file with mixed
	 * comma/whitespace/newline delimiters, merges and dedupes both sources, and
	 * rejects an unreadable file or a non-numeric token with a WP_Error.
	 */
	public function test_parse_user_ids_accepts_csv_and_file_and_rejects_garbage() {
		$this->assertSame( [ 1, 2, 3 ], Teams_Migration::parse_user_ids( '1, 2,3', '' ), 'A CSV string parses with whitespace tolerated.' );

		$user_ids_file_path = get_temp_dir() . 'nppd2055-user-ids-' . wp_generate_password( 8, false ) . '.txt';
		file_put_contents( $user_ids_file_path, "4\n5,6\n2\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		$this->assertSame( [ 1, 2, 4, 5, 6 ], Teams_Migration::parse_user_ids( '1,2', $user_ids_file_path ), 'CSV and file merge and dedupe.' );
		$this->assertSame( [ 4, 5, 6, 2 ], Teams_Migration::parse_user_ids( '', $user_ids_file_path ), 'A file alone parses across newlines and commas.' );
		unlink( $user_ids_file_path ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

		$unreadable_result = Teams_Migration::parse_user_ids( '', '/nonexistent/user-ids.txt' );
		$this->assertWPError( $unreadable_result, 'An unreadable file must produce a WP_Error.' );

		$garbage_result = Teams_Migration::parse_user_ids( '1,abc,3', '' );
		$this->assertWPError( $garbage_result, 'A non-numeric token must produce a WP_Error, not be silently dropped.' );
	}

	/**
	 * A bare --user-ids / --user-ids-file (no =value) arrives as boolean true; a
	 * string cast would turn it into '1' and silently target user ID 1 while
	 * bypassing the purchase-plan refusal. It must abort instead.
	 */
	public function test_bare_user_ids_flag_is_rejected() {
		$this->assertWPError( Teams_Migration::parse_user_ids( true, '' ), 'A bare --user-ids flag must produce a WP_Error.' );
		$this->assertWPError( Teams_Migration::parse_user_ids( '', true ), 'A bare --user-ids-file flag must produce a WP_Error.' );

		$purchase_plan_id = $this->create_plan( 'purchase' );
		$paying_member    = $this->create_member( $purchase_plan_id );
		$this->create_subscription_with_status( $paying_member, 'active' );

		$refused = false;
		try {
			$this->run_migrate_manual_members(
				[
					'plan-ids' => (string) $purchase_plan_id,
					'user-ids' => true,
					'live'     => true,
				]
			);
		} catch ( WP_CLI_Mock_Exception $abort ) {
			$refused = true;
			$this->assertStringContainsString( 'require a value', $abort->getMessage(), 'The abort must say the flag needs a value.' );
		}
		$this->assertTrue( $refused, 'A bare --user-ids flag must abort the run.' );
		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $paying_member ), 'No subscription may be created on the aborted run.' );
	}

	/**
	 * A --user-ids value that parses to no IDs at all (e.g. only delimiters)
	 * aborts rather than silently degrading into blanket plan processing.
	 */
	public function test_empty_user_ids_input_aborts() {
		$purchase_plan_id = $this->create_plan( 'purchase' );
		$this->create_member( $purchase_plan_id );

		$this->expectException( WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'resolved to no user IDs' );
		$this->run_migrate_manual_members(
			[
				'plan-ids' => (string) $purchase_plan_id,
				'user-ids' => ',,,',
				'live'     => true,
			]
		);
	}

	/**
	 * A member active on several in-scope plans is counted once in dry-run and
	 * granted once on a live run, so both reconcile per user against a parity
	 * diff.
	 */
	public function test_multi_plan_member_is_counted_and_granted_once() {
		$first_plan_id     = $this->create_plan( 'purchase' );
		$second_plan_id    = $this->create_plan( 'purchase' );
		$multi_plan_member = $this->create_member( $first_plan_id );
		$this->create_membership( $second_plan_id, $multi_plan_member );

		$flags = [
			'plan-ids'                       => $first_plan_id . ',' . $second_plan_id,
			'only-without-live-subscription' => true,
		];

		$dry_run_output = $this->run_migrate_manual_members( $flags );
		$this->assertSame( 1, substr_count( $dry_run_output, '[DRY RUN] Would create subscription' ), 'Dry-run must count a multi-plan member once.' );
		$this->assertStringContainsString( 'already planned in this run', $dry_run_output, 'The second membership must be reported as already planned.' );

		WP_CLI::reset();
		$this->run_migrate_manual_members( array_merge( $flags, [ 'live' => true ] ) );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $multi_plan_member ), 'A live run must create exactly one subscription for a multi-plan member.' );
	}

	/**
	 * The live-subscription skip count is per member, not per membership: one
	 * subscribed reader on two plans is one skipped member (though each
	 * membership still gets its own skip line).
	 */
	public function test_live_subscription_skip_count_is_per_member_not_per_membership() {
		$first_plan_id      = $this->create_plan( 'purchase' );
		$second_plan_id     = $this->create_plan( 'purchase' );
		$member_with_active = $this->create_member( $first_plan_id );
		$this->create_membership( $second_plan_id, $member_with_active );
		$this->create_subscription_with_status( $member_with_active, 'active' );

		$output = $this->run_migrate_manual_members(
			[
				'plan-ids'                       => $first_plan_id . ',' . $second_plan_id,
				'only-without-live-subscription' => true,
			]
		);

		$this->assertSame( 2, substr_count( $output, 'holds a live subscription' ), 'Each membership still reports its own skip line.' );
		$this->assertStringContainsString( 'Skipped 1 member(s) holding a live (active/on-hold/pending-cancel) subscription.', $output, 'The reconciliation count must be per member.' );
	}

	/**
	 * On a re-run, members migrated by a previous run are reported through the
	 * idempotency guard ("already migrated"), not folded into the
	 * live-subscription skip count — that count keeps meaning "genuinely
	 * already-subscribed".
	 */
	public function test_rerun_reports_migrated_members_as_already_migrated_not_live_skipped() {
		$purchase_plan_id   = $this->create_plan( 'purchase' );
		$member_without_sub = $this->create_member( $purchase_plan_id );
		$member_with_active = $this->create_member( $purchase_plan_id );
		$this->create_subscription_with_status( $member_with_active, 'active' );

		$flags = [
			'plan-ids'                       => (string) $purchase_plan_id,
			'only-without-live-subscription' => true,
			'live'                           => true,
		];

		$first_run_output = $this->run_migrate_manual_members( $flags );
		$this->assertStringContainsString( 'Skipped 1 member(s) holding a live', $first_run_output, 'The first run skips only the genuinely-subscribed member.' );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $member_without_sub ), 'The first run migrates the residual member.' );

		WP_CLI::reset();
		$second_run_output = $this->run_migrate_manual_members( $flags );
		$this->assertStringContainsString( 'already has an active migration subscription', $second_run_output, 'The migrated member must be reported via the idempotency guard.' );
		$this->assertStringContainsString( 'Skipped 1 member(s) holding a live', $second_run_output, 'The live-skip count must not absorb previously-migrated members.' );
		$this->assertCount( 1, $this->get_migration_subscription_ids_for_user( $member_without_sub ), 'No duplicate subscription on the re-run.' );
	}

	/**
	 * --user-ids combines with --only-without-live-subscription: a listed member
	 * holding a live subscription counts as matched (no unmatched warning) AND is
	 * skipped by the live filter.
	 */
	public function test_user_ids_combined_with_live_filter_reports_matched_and_skipped() {
		$purchase_plan_id   = $this->create_plan( 'purchase' );
		$listed_live_member = $this->create_member( $purchase_plan_id );
		$this->create_subscription_with_status( $listed_live_member, 'active' );

		$output = $this->run_migrate_manual_members(
			[
				'plan-ids'                       => (string) $purchase_plan_id,
				'user-ids'                       => (string) $listed_live_member,
				'only-without-live-subscription' => true,
				'live'                           => true,
			]
		);

		$this->assertEmpty( $this->get_migration_subscription_ids_for_user( $listed_live_member ), 'The listed live member must not get a subscription.' );
		$this->assertStringContainsString( 'All 1 requested user id(s) were found', $output, 'A live-skipped listed member still counts as matched.' );
		$this->assertStringContainsString( 'Skipped 1 member(s) holding a live', $output, 'The live skip must be counted.' );
	}

	/**
	 * A member of a live group-enabled subscription (the Teams-migration outcome)
	 * counts as live — no redundant personal $0 subscription — while a member of
	 * only a cancelled group subscription does not.
	 */
	public function test_member_of_live_group_subscription_counts_as_live() {
		$purchase_plan_id = $this->create_plan( 'purchase' );
		$group_member     = $this->create_member( $purchase_plan_id );
		$group_owner_id   = $this->create_reader_user();

		$group_subscription = wcs_create_subscription(
			[
				'customer_id'    => $group_owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$group_subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		add_user_meta( $group_member, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $group_subscription->get_id() );
		Group_Subscription::reset_cache();

		$this->assertTrue( Teams_Migration::member_has_live_subscription( $group_member ), 'A member of a live group subscription is live.' );

		$group_subscription->set_status( 'cancelled' );
		Group_Subscription::reset_cache();
		$this->assertFalse( Teams_Migration::member_has_live_subscription( $group_member ), 'A member of only a cancelled group subscription is not live.' );
	}

	/**
	 * --as-group with a member selection flag and no explicit --plan-ids is
	 * refused — the widened all-plans default would create an orphan empty group
	 * subscription per plan.
	 */
	public function test_as_group_with_selection_flag_requires_explicit_plan_ids() {
		$group_owner_id = $this->create_reader_user();

		$this->expectException( WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'requires explicit --plan-ids' );
		$this->run_migrate_manual_members(
			[
				'as-group'                       => true,
				'group-owner-id'                 => $group_owner_id,
				'only-without-live-subscription' => true,
				'live'                           => true,
			]
		);
	}

	/**
	 * The new member filters run before the group-mode branch: under --as-group,
	 * a live-subscription member is filtered out before the group add while the
	 * residual member joins the group.
	 */
	public function test_as_group_applies_the_new_member_filters() {
		$purchase_plan_id   = $this->create_plan( 'purchase' );
		$member_with_active = $this->create_member( $purchase_plan_id );
		$member_without_sub = $this->create_member( $purchase_plan_id );
		$this->create_subscription_with_status( $member_with_active, 'active' );
		$group_owner_id = $this->create_reader_user();

		$output = $this->run_migrate_manual_members(
			[
				'plan-ids'                       => (string) $purchase_plan_id,
				'as-group'                       => true,
				'group-owner-id'                 => $group_owner_id,
				'only-without-live-subscription' => true,
				'live'                           => true,
			]
		);

		$owner_group_subscription_ids = $this->get_migration_subscription_ids_for_user( $group_owner_id );
		$this->assertCount( 1, $owner_group_subscription_ids, 'One group subscription is created for the plan.' );
		global $subscriptions_database;
		$group_subscription = $subscriptions_database[ $owner_group_subscription_ids[0] ];
		Group_Subscription::reset_cache();
		$this->assertTrue( (bool) Group_Subscription::user_is_member( $member_without_sub, $group_subscription ), 'The residual member must join the group.' );
		$this->assertFalse( (bool) Group_Subscription::user_is_member( $member_with_active, $group_subscription ), 'The live-subscription member must be filtered out before the group add.' );
		$this->assertStringContainsString( 'Skipped 1 member(s) holding a live', $output, 'The live skip must be reported in group mode too.' );
	}
}
