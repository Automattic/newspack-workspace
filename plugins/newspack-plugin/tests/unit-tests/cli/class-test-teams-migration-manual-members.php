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
		$membership_id    = wp_insert_post(
			[
				'post_type'   => 'wc_user_membership',
				'post_status' => 'wcm-active',
				'post_parent' => $plan_id,
				'post_author' => $user_id,
				'post_title'  => 'Membership for user ' . $user_id,
			]
		);
		$this->assertNotWPError( $membership_id, 'Fixture membership creation should succeed.' );
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
}
