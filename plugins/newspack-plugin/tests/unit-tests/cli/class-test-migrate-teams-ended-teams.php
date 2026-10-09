<?php
/**
 * Tests for `wp newspack migrate-teams` on teams whose subscription has ended (NPPD-2340).
 *
 * A team with nothing to reuse gets a new $0 group subscription only while someone
 * in it still has access through the team. Recreating a group for a team that
 * lapsed would re-add every member and hand them free access they no longer have.
 *
 * @package Newspack\Tests
 * @group teams-migration
 */

use Newspack\CLI\Teams_Migration;
use Newspack\Group_Subscription;

/**
 * Test migrate-teams with teams on dead subscriptions.
 *
 * @group teams-migration
 */
class Test_Migrate_Teams_Ended_Teams extends WP_UnitTestCase {

	/**
	 * Migration product ID used by every test.
	 */
	const PRODUCT_ID = 7300;

	/**
	 * Post IDs (teams, memberships) to clean up.
	 *
	 * @var int[]
	 */
	private $post_ids = [];

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Load the WooCommerce and WP-CLI mocks the command depends on.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
	}

	/**
	 * Reset the mock stores and recorded CLI output, and register the migration product.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		WP_CLI::reset();
		Group_Subscription::reset_cache();
		wc_create_mock_product(
			[
				'id'   => self::PRODUCT_ID,
				'name' => 'Group migration',
				'type' => 'subscription',
			]
		);
	}

	/**
	 * Tear down fixtures.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->post_ids = [];
		$this->user_ids = [];
		parent::tear_down();
	}

	/**
	 * Create a reader (an eligible group member).
	 *
	 * @return int User ID.
	 */
	private function create_reader(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'reader-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'reader-' . wp_generate_password( 6, false ) . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		update_user_meta( $user_id, '_newspack_reader', true );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Create a team with one member whose team membership is in the given status.
	 *
	 * @param string|null $subscription_status Status of the team's linked subscription, or null for a team never linked to one.
	 * @param string|null $membership_status   WCM post status of the member's team membership, or null for a member who has not accepted yet (no membership).
	 * @param string      $end_date            The team's own end date (GMT), empty for none.
	 * @return array{team_id: int, member_id: int, subscription_id: int, membership_id: int}
	 */
	private function create_team( ?string $subscription_status, ?string $membership_status, string $end_date = '' ): array {
		$owner_id  = $this->create_reader();
		$member_id = $this->create_reader();
		$team_id   = wp_insert_post(
			[
				'post_type'   => 'wc_memberships_team',
				'post_status' => 'publish',
				'post_title'  => 'Example team',
				'post_author' => $owner_id,
			]
		);
		$this->assertNotWPError( $team_id, 'Fixture team creation should succeed.' );
		$this->post_ids[] = $team_id;
		if ( '' !== $end_date ) {
			update_post_meta( $team_id, '_membership_end_date', $end_date );
		}

		$subscription_id = 0;
		$membership_id   = 0;
		if ( null !== $subscription_status ) {
			$subscription_id = wcs_create_subscription(
				[
					'customer_id' => $owner_id,
					'status'      => $subscription_status,
				]
			)->get_id();
			update_post_meta( $team_id, '_subscription_id', $subscription_id );
		}

		if ( null !== $membership_status ) {
			add_post_meta( $team_id, '_member_id', $member_id );
			// Teams links each seat's user membership back to the team with _team_id.
			$membership_id = wp_insert_post(
				[
					'post_type'   => 'wc_user_membership',
					'post_status' => $membership_status,
					'post_author' => $member_id,
					'post_title'  => 'Team membership',
				]
			);
			$this->assertNotWPError( $membership_id, 'Fixture membership creation should succeed.' );
			$this->post_ids[] = $membership_id;
			update_post_meta( $membership_id, '_team_id', $team_id );
		}

		return [
			'team_id'         => $team_id,
			'member_id'       => $member_id,
			'subscription_id' => $subscription_id,
			'membership_id'   => $membership_id,
		];
	}

	/**
	 * Run the command and return the subscriptions it created.
	 *
	 * @param array $flags Flags on top of --product-id.
	 * @return WC_Subscription[]
	 */
	private function migrate( array $flags = [] ): array {
		global $subscriptions_database;
		$existing_ids = array_keys( $subscriptions_database );
		WP_CLI::reset();
		( new Teams_Migration() )->migrate_teams( [], array_merge( [ 'product-id' => self::PRODUCT_ID ], $flags ) );
		return array_diff_key( $subscriptions_database, array_flip( $existing_ids ) );
	}

	/**
	 * The Sub column of a team's row in the end-of-run summary table.
	 *
	 * @param int $team_id Team post ID.
	 * @return string|int|null
	 */
	private function summary_sub_for( int $team_id ) {
		foreach ( WP_CLI::$tables[0]['items'] as $row ) {
			if ( $row['Team'] === $team_id ) {
				return $row['Sub'];
			}
		}
		return null;
	}

	/**
	 * Teams in which nobody has access today, per run mode.
	 *
	 * @return array[]
	 */
	public function teams_without_access() {
		$past = gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) );
		return [
			'cancelled subscription, expired membership, dry run' => [ [], 'cancelled', 'wcm-expired', '' ],
			'cancelled subscription, expired membership, live' => [ [ 'live' => true ], 'cancelled', 'wcm-expired', '' ],
			'free on-hold subscription, paused membership' => [ [ 'live' => true ], 'on-hold', 'wcm-paused', '' ],
			'never linked, past its end date'              => [ [ 'live' => true ], null, 'wcm-expired', $past ],
			'never linked, past its end date, membership lagging' => [ [ 'live' => true ], null, 'wcm-active', $past ],
		];
	}

	/**
	 * A team with nothing to reuse and no access to carry over gets no new
	 * subscription, and is reported as SKIPPED rather than as an error. A team on
	 * an active subscription is reused as before, though its only membership has
	 * expired too: the check gates creation only.
	 *
	 * @dataProvider teams_without_access
	 * @param array       $flags               Run-mode flags.
	 * @param string|null $subscription_status Linked subscription status, null for none.
	 * @param string      $membership_status   The member's team membership status.
	 * @param string      $end_date            The team's own end date.
	 */
	public function test_team_with_nobody_holding_access_is_skipped( array $flags, ?string $subscription_status, string $membership_status, string $end_date ) {
		$skipped_team = $this->create_team( $subscription_status, $membership_status, $end_date );
		$active_team  = $this->create_team( 'active', 'wcm-expired' );

		$created = $this->migrate( $flags );

		$this->assertSame( 'SKIPPED', $this->summary_sub_for( $skipped_team['team_id'] ), 'The team should show as SKIPPED in the summary.' );
		$this->assertSame( $active_team['subscription_id'], $this->summary_sub_for( $active_team['team_id'] ), 'The active team should reuse its own subscription.' );
		if ( ! empty( $flags['live'] ) ) {
			$this->assertEmpty( $created, 'A live run should create no subscription for the skipped team.' );
		}
		$done_line = end( WP_CLI::$successes );
		$this->assertStringContainsString( '1 skipped with no access to carry over', $done_line, 'The end-of-run line should count the skipped team.' );
		$this->assertStringContainsString( '0 had error(s)', $done_line, 'A skipped team is not an error.' );
	}

	/**
	 * Teams in which someone still has access, or that are still within their term.
	 *
	 * @return array[]
	 */
	public function teams_with_access() {
		return [
			'cancelled subscription, active membership' => [ 'cancelled', 'wcm-active', '' ],
			'cancelled subscription, pending-cancellation membership' => [ 'cancelled', 'wcm-pending', '' ],
			'never linked, no end date, invitee not yet joined' => [ null, null, '' ],
			'never linked, future end date, invitee not yet joined' => [ null, null, gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ) ],
		];
	}

	/**
	 * A team with nothing to reuse gets a new group while someone in it has access
	 * or, for a team never linked to a subscription, while its term runs.
	 *
	 * @dataProvider teams_with_access
	 * @param string|null $subscription_status Linked subscription status, null for none.
	 * @param string|null $membership_status   The member's team membership status, null for none.
	 * @param string      $end_date            The team's own end date.
	 */
	public function test_team_with_access_gets_a_new_group( ?string $subscription_status, ?string $membership_status, string $end_date ) {
		$team = $this->create_team( $subscription_status, $membership_status, $end_date );

		$created = $this->migrate( [ 'live' => true ] );

		$this->assertCount( 1, $created, 'The team should get a new subscription.' );
		if ( null !== $membership_status ) {
			$this->assertTrue( (bool) Group_Subscription::user_is_member( $team['member_id'], reset( $created ) ), 'The member with access should be in the new group.' );
		}
	}

	/**
	 * A team migrated on an earlier run keeps being re-updated once its members'
	 * access lapses, rather than being skipped: the check never reaches a team
	 * that already has a group.
	 */
	public function test_previously_migrated_team_is_re_updated_after_access_lapses() {
		$team  = $this->create_team( 'cancelled', 'wcm-active' );
		$group = $this->migrate( [ 'live' => true ] );
		$this->assertCount( 1, $group, 'Fixture check: the first run creates the group.' );
		wp_update_post(
			[
				'ID'          => $team['membership_id'],
				'post_status' => 'wcm-expired',
			]
		);

		$created = $this->migrate( [ 'live' => true ] );

		$this->assertEmpty( $created, 'The re-run should create nothing.' );
		$this->assertSame( key( $group ), $this->summary_sub_for( $team['team_id'] ), 'The re-run should re-update the group from the first run.' );
	}

	/**
	 * --include-ended-teams creates a group for every team with nothing to reuse,
	 * whether or not anyone in it has access.
	 */
	public function test_include_ended_teams_creates_a_group_for_every_team() {
		$this->create_team( 'cancelled', 'wcm-expired' );

		$created = $this->migrate(
			[
				'live'                => true,
				'include-ended-teams' => true,
			]
		);

		$this->assertCount( 1, $created, 'The ended team should get a new subscription.' );
	}
}
