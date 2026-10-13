<?php
/**
 * Tests for `wp newspack migrate-teams` on teams with no access to carry over (NPPD-2340, NPPD-2412).
 *
 * A team with nothing to reuse gets a new $0 group subscription only while someone
 * in it still has access through the team. Recreating a group for a team that
 * lapsed, or for one nobody ever joined, would hand its owner and members free
 * access they do not have today.
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
	 * Lines of the end-of-run section that gives each skipped team's reason, keyed
	 * by team ID.
	 *
	 * @return array<int, string>
	 */
	private function summary_skip_reasons(): array {
		$header = false;
		foreach ( WP_CLI::$output as $index => $line ) {
			if ( str_starts_with( $line, '=== SKIPPED: NO ACCESS TO CARRY OVER' ) ) {
				$header = $index;
				break;
			}
		}
		$this->assertNotFalse( $header, 'The summary should have a section for teams skipped with no access.' );
		$reasons = [];
		foreach ( array_slice( WP_CLI::$output, $header + 1 ) as $line ) {
			if ( ! preg_match( '/^Team (\d+): (.+)$/', $line, $matches ) ) {
				break;
			}
			$reasons[ (int) $matches[1] ] = $matches[2];
		}
		return $reasons;
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
			'never linked, no members'                     => [ [ 'live' => true ], null, null, '' ],
			'never linked, membership expired'             => [ [ 'live' => true ], null, 'wcm-expired', '' ],
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
	 * @param string|null $membership_status   The member's team membership status, null for a team with no members.
	 * @param string      $end_date            The team's own end date.
	 */
	public function test_team_with_nobody_holding_access_is_skipped( array $flags, ?string $subscription_status, ?string $membership_status, string $end_date ) {
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
	 * Teams in which someone still has access.
	 *
	 * @return array[]
	 */
	public function teams_with_access() {
		return [
			// A linked team's own end date is not checked: Teams ends its memberships with the subscription.
			'cancelled subscription, active membership, past team end date' => [ 'cancelled', 'wcm-active', gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) ) ],
			'cancelled subscription, active membership'    => [ 'cancelled', 'wcm-active', '' ],
			'cancelled subscription, pending-cancellation membership' => [ 'cancelled', 'wcm-pending', '' ],
			'never linked, active membership, no end date' => [ null, 'wcm-active', '' ],
			'never linked, active membership, future end date' => [ null, 'wcm-active', gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ) ],
		];
	}

	/**
	 * A team with nothing to reuse gets a new group while someone in it has access
	 * and, for a team never linked to a subscription, while its term runs.
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
		$this->assertTrue( (bool) Group_Subscription::user_is_member( $team['member_id'], reset( $created ) ), 'The member with access should be in the new group.' );
	}

	/**
	 * A dry run over three teams never linked to a subscription—one nobody joined,
	 * one holding only a pending invitee, and one whose memberships all expired—
	 * plans nothing for any of them and gives each one's reason in the summary. The
	 * invitee is listed with no group to resolve to.
	 */
	public function test_unlinked_teams_without_access_are_reported_with_a_reason() {
		$empty_team   = $this->create_team( null, null );
		$invited_team = $this->create_team( null, null );
		$expired_team = $this->create_team( null, 'wcm-expired' );
		$invitation   = wp_insert_post(
			[
				'post_type'   => 'wc_team_invitation',
				'post_status' => 'wcmti-pending',
				'post_title'  => 'invitee@example.test',
				'post_parent' => $invited_team['team_id'],
			]
		);
		$this->assertNotWPError( $invitation, 'Fixture invitation creation should succeed.' );
		$this->post_ids[] = $invitation;

		$this->migrate( [ 'only-unlinked' => true ] );

		foreach ( [ $empty_team, $invited_team, $expired_team ] as $team ) {
			$this->assertSame( 'SKIPPED', $this->summary_sub_for( $team['team_id'] ), 'Each team should show as SKIPPED.' );
		}
		$done_lines = preg_grep( '/^Done\. /', WP_CLI::$successes );
		$this->assertStringContainsString( '0 had new subscriptions created', (string) reset( $done_lines ), 'The dry run should plan no subscription.' );
		$reasons = $this->summary_skip_reasons();
		$this->assertSame( 'nobody has joined the team', $reasons[ $empty_team['team_id'] ] ?? null, 'The empty team\'s reason should say nobody joined.' );
		$this->assertSame( 'nobody has joined the team, so its 1 pending invitation(s) will not resolve', $reasons[ $invited_team['team_id'] ] ?? null, 'The invited team\'s reason should name its unresolved invitation.' );
		$this->assertSame( 'nobody in the team has access today', $reasons[ $expired_team['team_id'] ] ?? null, 'The expired team\'s reason should say nobody has access.' );
		$invitation_table = end( WP_CLI::$tables );
		$this->assertContains(
			[
				'team_id' => $invited_team['team_id'],
				'sub'     => '—',
				'invitee' => 'invitee@example.test',
			],
			$invitation_table['items'],
			'The invitee should be listed with no group to resolve to.'
		);
	}

	/**
	 * A team never linked to a subscription ends on its own end date, and the group
	 * created for it ends then too.
	 */
	public function test_unlinked_team_end_date_carries_to_the_new_group() {
		$end_date = gmdate( 'Y-m-d H:i:s', strtotime( '+3 months' ) );
		$this->create_team( null, 'wcm-active', $end_date );

		$created = $this->migrate(
			[
				'live'          => true,
				'only-unlinked' => true,
			]
		);

		$this->assertCount( 1, $created, 'The team should get a new subscription.' );
		$this->assertSame( $end_date, reset( $created )->get_date( 'end' ), 'The new group should end on the team\'s end date.' );
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
