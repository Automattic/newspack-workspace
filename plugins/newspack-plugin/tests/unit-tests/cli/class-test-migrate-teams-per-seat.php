<?php
/**
 * Tests for `wp newspack migrate-teams` against a per-seat migration product (NPPD-2278).
 *
 * A per-seat group's capacity is its line-item quantity; the limit meta the command
 * writes after adding members is ignored for it. These tests drive the command
 * end-to-end so they hold the outcome that matters: every team member lands in the
 * group, and the group keeps the seats the team had.
 *
 * @package Newspack\Tests
 * @group teams-migration
 */

use Newspack\CLI\Teams_Migration;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;

/**
 * Test migrate-teams with per-seat and flat migration products.
 *
 * @group teams-migration
 */
class Test_Migrate_Teams_Per_Seat extends WP_UnitTestCase {

	/**
	 * Migration product ID used by every test.
	 */
	const PRODUCT_ID = 7100;

	/**
	 * Post IDs (teams, invitations) to clean up.
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
	 * Reset the mock stores and recorded CLI output.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		WP_CLI::reset();
		Group_Subscription::reset_cache();
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
	 * Create a team with no linked subscription, so the command creates one.
	 *
	 * @param int   $owner_id   Team owner.
	 * @param int[] $member_ids Seat-holding members (the owner excluded).
	 * @param int   $seat_count The team's seat count (0 = unlimited).
	 * @return int Team post ID.
	 */
	private function create_unlinked_team( int $owner_id, array $member_ids, int $seat_count ): int {
		$team_id = wp_insert_post(
			[
				'post_type'   => 'wc_memberships_team',
				'post_status' => 'publish',
				'post_title'  => 'Example team',
				'post_author' => $owner_id,
			]
		);
		$this->assertNotWPError( $team_id, 'Fixture team creation should succeed.' );
		$this->post_ids[] = $team_id;
		foreach ( $member_ids as $member_id ) {
			add_post_meta( $team_id, '_member_id', $member_id );
		}
		if ( $seat_count ) {
			update_post_meta( $team_id, '_seat_count', $seat_count );
		}
		return $team_id;
	}

	/**
	 * Create a pending WooCommerce Teams invitation for a team.
	 *
	 * @param int    $team_id Team post ID.
	 * @param string $email   Invitee email.
	 */
	private function create_pending_invitation( int $team_id, string $email ): void {
		$invitation_id = wp_insert_post(
			[
				'post_type'   => 'wc_team_invitation',
				'post_status' => 'wcmti-pending',
				'post_title'  => $email,
				'post_parent' => $team_id,
			]
		);
		$this->assertNotWPError( $invitation_id, 'Fixture invitation creation should succeed.' );
		$this->post_ids[] = $invitation_id;
	}

	/**
	 * Register the migration product.
	 *
	 * @param string $pricing_mode Group pricing mode: per_seat or per_team.
	 */
	private function create_migration_product( string $pricing_mode ): void {
		wc_create_mock_product(
			[
				'id'   => self::PRODUCT_ID,
				'name' => 'Group migration',
				'type' => 'subscription',
				'meta' => [
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled' => 'yes',
					Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'pricing_mode' => $pricing_mode,
				],
			]
		);
	}

	/**
	 * Run the command live and return the one subscription it created.
	 *
	 * @return WC_Subscription
	 */
	private function migrate_and_get_subscription() {
		( new Teams_Migration() )->migrate_teams(
			[],
			[
				'product-id' => self::PRODUCT_ID,
				'live'       => true,
			]
		);
		global $subscriptions_database;
		$this->assertCount( 1, $subscriptions_database, 'The command should create one subscription for the team.' );
		return reset( $subscriptions_database );
	}

	/**
	 * The quantity of a subscription's single line item.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @return int
	 */
	private function line_item_quantity( $subscription ): int {
		$items = $subscription->get_items();
		$this->assertCount( 1, $items, 'The subscription should carry one line item.' );
		return (int) reset( $items )->get_quantity();
	}

	/**
	 * A per-seat group created for a team gets the team's owner-inclusive seat
	 * count, so every member is added and the team keeps its unused seats.
	 *
	 * Before the fix the line item carried a quantity of 1, which a per-seat group
	 * reads as a one-seat group: the owner filled it and every member add failed.
	 */
	public function test_new_per_seat_subscription_gets_the_team_seat_count() {
		$this->create_migration_product( Group_Subscription_Settings::PRICING_MODE_PER_SEAT );
		$owner   = $this->create_reader();
		$members = [ $this->create_reader(), $this->create_reader(), $this->create_reader() ];
		// Five seats, owner not holding one: the group needs owner + 5 = 6.
		$this->create_unlinked_team( $owner, $members, 5 );

		$subscription = $this->migrate_and_get_subscription();

		foreach ( $members as $member ) {
			$this->assertTrue( (bool) Group_Subscription::user_is_member( $member, $subscription ), 'Every team member should be added to the group.' );
		}
		$this->assertSame( 6, $this->line_item_quantity( $subscription ), 'The seat count should be the team seat limit plus the owner.' );
		$this->assertSame( 6, Group_Subscription_Settings::get_subscription_settings( $subscription )['limit'], 'The group capacity should match the seat count.' );
		$this->assertSame( 0.0, (float) $subscription->get_total(), 'A migration subscription stays free whatever its seat count.' );
	}

	/**
	 * An unlimited team has no seat count to carry over, so its per-seat group is
	 * sized to hold everyone: the owner, the members, and each pending invitee, whose
	 * join-team link still has to find a free seat after the switch.
	 */
	public function test_unlimited_team_per_seat_subscription_fits_members_and_invitees() {
		$this->create_migration_product( Group_Subscription_Settings::PRICING_MODE_PER_SEAT );
		$owner   = $this->create_reader();
		$members = [ $this->create_reader(), $this->create_reader() ];
		$team_id = $this->create_unlinked_team( $owner, $members, 0 );
		$this->create_pending_invitation( $team_id, 'invitee@example.test' );

		$subscription = $this->migrate_and_get_subscription();

		$this->assertSame( 4, $this->line_item_quantity( $subscription ), 'Owner + 2 members + 1 pending invitee = 4 seats.' );
		foreach ( $members as $member ) {
			$this->assertTrue( (bool) Group_Subscription::user_is_member( $member, $subscription ), 'Every team member should be added to the group.' );
		}
	}

	/**
	 * A flat-priced group is unchanged: one line item, capacity from the limit meta.
	 */
	public function test_new_flat_subscription_keeps_a_single_quantity() {
		$this->create_migration_product( Group_Subscription_Settings::PRICING_MODE_PER_TEAM );
		$owner   = $this->create_reader();
		$members = [ $this->create_reader(), $this->create_reader() ];
		$this->create_unlinked_team( $owner, $members, 5 );

		$subscription = $this->migrate_and_get_subscription();

		$this->assertSame( 1, $this->line_item_quantity( $subscription ), 'A flat-priced group keeps a quantity of 1.' );
		$this->assertSame( 6, Group_Subscription_Settings::get_subscription_settings( $subscription )['limit'], 'A flat-priced group takes its capacity from the limit meta.' );
		foreach ( $members as $member ) {
			$this->assertTrue( (bool) Group_Subscription::user_is_member( $member, $subscription ), 'Every team member should be added to the group.' );
		}
	}

	/**
	 * Re-running the command re-aligns the team's $0 group onto the migration
	 * product. For a per-seat product that must not shrink the group to one seat.
	 */
	public function test_rerun_keeps_per_seat_capacity() {
		$this->create_migration_product( Group_Subscription_Settings::PRICING_MODE_PER_SEAT );
		$owner   = $this->create_reader();
		$members = [ $this->create_reader(), $this->create_reader() ];
		$this->create_unlinked_team( $owner, $members, 5 );

		$subscription = $this->migrate_and_get_subscription();
		$this->assertSame( 6, $this->line_item_quantity( $subscription ), 'Fixture check: the first run sizes the group.' );

		// The second run finds the group stamped with this team's ID and re-aligns it.
		$subscription = $this->migrate_and_get_subscription();

		$this->assertSame( 6, $this->line_item_quantity( $subscription ), 'A re-run should keep the team seat count, not reset it to 1.' );
	}
}
