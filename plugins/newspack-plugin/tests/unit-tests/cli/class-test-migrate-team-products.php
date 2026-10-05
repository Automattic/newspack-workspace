<?php
/**
 * Tests for the `wp newspack migrate-team-products` CLI command (NPPD-2153).
 *
 * The unit test in the group-subscription suite covers the pure limit mapping
 * (`map_product_max_members_to_group_limit()`); this suite covers the wiring the
 * regression actually lived in — that the command persists the owner-inclusive
 * *mapped* limit to `_newspack_group_subscription_limit`, not the raw product
 * member count.
 *
 * @package Newspack\Tests
 * @group teams-migration
 */

use Newspack\CLI\Teams_Migration;

/**
 * Test the migrate-team-products command end-to-end against mock products.
 *
 * @group teams-migration
 */
class Test_Migrate_Team_Products extends WP_UnitTestCase {

	/**
	 * Product post IDs to clean up.
	 *
	 * @var int[]
	 */
	private $product_ids = [];

	/**
	 * Load the WooCommerce and WP-CLI mocks the command depends on.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
	}

	/**
	 * Reset the mock product store, the recorded CLI output, and the team option.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		$products_database = [];
		WP_CLI::reset();
		delete_option( 'wc_memberships_for_teams_owners_must_take_seat' );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = [];
		foreach ( $this->product_ids as $id ) {
			wp_delete_post( $id, true );
		}
		$this->product_ids = [];
		delete_option( 'wc_memberships_for_teams_owners_must_take_seat' );
		parent::tear_down();
	}

	/**
	 * Create a published team product: a real `product` post so the command's
	 * get_posts() meta_query finds it, plus a matching mock WC_Product carrying the
	 * team member-count meta so wc_get_product() can read and write it.
	 *
	 * @param int   $max_members The product's team "Maximum member count".
	 * @param array $meta        Further product meta, e.g. the Teams pricing mode.
	 * @return int The product ID.
	 */
	private function create_team_product( int $max_members, array $meta = [] ): int {
		$product_id = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Team subscription',
			]
		);
		$this->assertNotWPError( $product_id, 'Fixture product creation should succeed.' );
		update_post_meta( $product_id, '_wc_memberships_for_teams_has_team_membership', 'yes' );
		$this->product_ids[] = $product_id;

		global $products_database;
		$products_database[ $product_id ] = new WC_Product(
			[
				'id'   => $product_id,
				'name' => 'Team subscription',
				'type' => 'subscription',
				'meta' => array_merge( [ '_wc_memberships_for_teams_max_member_count' => $max_members ], $meta ),
			]
		);
		return $product_id;
	}

	/**
	 * A live run must persist the owner-inclusive mapped limit (owner + members),
	 * not the raw product member count. This is the regression the fix exists to
	 * prevent: writing the raw count leaves the next buyer's owner occupying a seat
	 * the publisher paid for. Reverting the command to write `$max_members` again
	 * fails this test, where the mapping unit test would still pass.
	 */
	public function test_live_run_persists_owner_inclusive_limit() {
		$product_id = $this->create_team_product( 5 );

		( new Teams_Migration() )->migrate_team_products( [], [ 'live' => true ] );

		global $products_database;
		$product = $products_database[ $product_id ];
		$this->assertSame( 'yes', $product->get_meta( '_newspack_group_subscription_enabled' ), 'The command must enable group subscriptions on the product.' );
		$this->assertSame( 6, $product->get_meta( '_newspack_group_subscription_limit' ), 'A 5-member product must persist a limit of 6 (owner + 5), not the raw 5.' );
	}

	/**
	 * Teams' per-member pricing charges for every seat bought, which is what a
	 * per-seat group does. A live run must carry it over along with the seat bounds,
	 * each gaining the owner's seat unless "Owners must be members" reserves one
	 * already (the same adjustment the flat limit makes).
	 */
	public function test_per_member_pricing_maps_to_per_seat_with_owner_inclusive_bounds() {
		$product_id = $this->create_team_product(
			10,
			[
				'_wc_memberships_for_teams_pricing' => 'per_member',
				'_wc_memberships_for_teams_min_member_count' => 2,
			]
		);

		( new Teams_Migration() )->migrate_team_products( [], [ 'live' => true ] );

		$settings = \Newspack\Group_Subscription_Settings::get_product_settings( $product_id );
		$this->assertSame( 'per_seat', $settings['pricing_mode'], 'Per-member pricing should become per-seat group pricing.' );
		$this->assertSame( 3, $settings['min_seats'], 'A 2-member minimum should become 3 seats (owner + 2).' );
		$this->assertSame( 11, $settings['max_seats'], 'A 10-member maximum should become 11 seats (owner + 10).' );
	}

	/**
	 * When "Owners must be members" is on, the owner already holds one of the Teams
	 * seats, so the bounds carry over unchanged. An unset maximum stays unbounded.
	 */
	public function test_per_member_bounds_carry_over_unchanged_when_owner_takes_a_seat() {
		update_option( 'wc_memberships_for_teams_owners_must_take_seat', 'yes' );
		$product_id = $this->create_team_product(
			0,
			[
				'_wc_memberships_for_teams_pricing' => 'per_member',
				'_wc_memberships_for_teams_min_member_count' => 2,
			]
		);

		( new Teams_Migration() )->migrate_team_products( [], [ 'live' => true ] );

		$settings = \Newspack\Group_Subscription_Settings::get_product_settings( $product_id );
		$this->assertSame( 'per_seat', $settings['pricing_mode'], 'Per-member pricing should become per-seat group pricing.' );
		$this->assertSame( 2, $settings['min_seats'], 'The owner already holds a seat, so the minimum carries over as-is.' );
		$this->assertSame( 0, $settings['max_seats'], 'An unset Teams maximum should leave the seat count unbounded.' );
	}

	/**
	 * A per-team (flat) product stays flat-priced.
	 */
	public function test_per_team_pricing_stays_flat() {
		$product_id = $this->create_team_product( 5, [ '_wc_memberships_for_teams_pricing' => 'per_team' ] );

		( new Teams_Migration() )->migrate_team_products( [], [ 'live' => true ] );

		$settings = \Newspack\Group_Subscription_Settings::get_product_settings( $product_id );
		$this->assertSame( 'per_team', $settings['pricing_mode'], 'A per-team product should keep flat group pricing.' );
		$this->assertSame( 6, $settings['limit'], 'The flat limit mapping is unchanged.' );
	}
}
