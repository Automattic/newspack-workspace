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
	 * Create a published variable team product whose subscription variations carry
	 * their own Teams "Maximum member count", as Teams stores it.
	 *
	 * @param int|string $parent_max       The parent's Teams maximum ('' = unset).
	 * @param array      $variation_maxima Each variation's Teams maximum ('' = unset), keyed by variation name.
	 * @param array      $parent_meta      Further parent meta, e.g. the Teams pricing mode.
	 * @param array      $variation_meta   Further meta per variation, keyed by variation name.
	 * @return int[] The variation IDs keyed by name, plus the parent under 'parent'.
	 */
	private function create_variable_team_product( $parent_max, array $variation_maxima, array $parent_meta = [], array $variation_meta = [] ): array {
		$parent_id = $this->create_team_product( 0 );
		$ids       = [ 'parent' => $parent_id ];
		// Variations live only in the mock store, so their IDs just need to be unique keys.
		foreach ( $variation_maxima as $name => $max ) {
			$ids[ $name ] = $parent_id + count( $ids ) * 1000;
		}

		global $products_database;
		$products_database[ $parent_id ] = new WC_Product(
			[
				'id'       => $parent_id,
				'name'     => 'Team subscription',
				'type'     => 'variable-subscription',
				'children' => array_values( array_diff_key( $ids, [ 'parent' => true ] ) ),
				'meta'     => array_merge( [ '_wc_memberships_for_teams_max_member_count' => $parent_max ], $parent_meta ),
			]
		);
		foreach ( $variation_maxima as $name => $max ) {
			$products_database[ $ids[ $name ] ] = new WC_Product(
				[
					'id'        => $ids[ $name ],
					'name'      => $name,
					'type'      => 'subscription_variation',
					'parent_id' => $parent_id,
					'meta'      => array_merge( [ '_wc_memberships_for_teams_max_member_count' => $max ], $variation_meta[ $name ] ?? [] ),
				]
			);
		}
		return $ids;
	}

	/**
	 * Teams reads the maximum off the purchased variation and never falls back to
	 * the parent, so each variation's group limit comes from its own maximum and a
	 * variation without one stays unlimited, whatever the parent holds. Teams saves
	 * a blank variation field as 0, so both forms must read as unlimited. With
	 * "Owners must be members" on, the owner already holds one of the Teams seats,
	 * so the counts carry over as-is.
	 */
	public function test_live_run_maps_each_variation_from_its_own_teams_max() {
		update_option( 'wc_memberships_for_teams_owners_must_take_seat', 'yes' );
		$ids = $this->create_variable_team_product(
			10,
			[
				'Monthly' => 2,
				'Large'   => 200,
				'Blank'   => '',
				'Zero'    => 0,
			]
		);

		( new Teams_Migration() )->migrate_team_products( [], [ 'live' => true ] );

		$limit_of = fn( $id ) => \Newspack\Group_Subscription_Settings::get_product_settings( $id )['limit'];
		$this->assertSame( 2, $limit_of( $ids['Monthly'] ), 'A 2-member variation must keep a limit of 2, not the parent\'s.' );
		$this->assertSame( 200, $limit_of( $ids['Large'] ), 'A 200-member variation must keep a limit of 200.' );
		$this->assertSame( 0, $limit_of( $ids['Blank'] ), 'A variation Teams sells unlimited must stay unlimited, not take the parent\'s.' );
		$this->assertSame( 0, $limit_of( $ids['Zero'] ), 'A variation saved as 0 is unlimited in Teams too.' );
		$this->assertSame( 10, $limit_of( $ids['parent'] ), 'The parent keeps its own maximum.' );
	}

	/**
	 * The dry run must show each variation's limit, so an operator can check them
	 * before writing, and must write nothing. Only a product unlimited in Teams
	 * reads as unlimited. With "Owners must be members" off, each variation's limit
	 * gains the owner's seat.
	 */
	public function test_dry_run_lists_each_variation_limit() {
		$ids = $this->create_variable_team_product(
			'',
			[
				'Monthly'   => 2,
				'Unlimited' => '',
			]
		);

		( new Teams_Migration() )->migrate_team_products( [], [] );

		$limits = array_column( WP_CLI::$tables[0]['items'], 'Limit / max seats', 'Product' );
		$this->assertSame(
			[
				$ids['parent']    => 'Unlimited',
				$ids['Monthly']   => 3,
				$ids['Unlimited'] => 'Unlimited',
			],
			$limits,
			'The summary must list the parent and each variation with its own owner-inclusive limit.'
		);
		global $products_database;
		$this->assertSame( '', $products_database[ $ids['Monthly'] ]->get_meta( '_newspack_group_subscription_limit' ), 'A dry run must not write.' );
	}

	/**
	 * Teams keeps per-member pricing on the parent but the member counts on each
	 * variation, so every variation must become per-seat with its own bounds. The
	 * summary shows the seat maximum for those rows, since that caps a per-seat group;
	 * a 1-member variation tells it apart from the flat limit, which never drops below 2.
	 */
	public function test_per_member_variations_get_their_own_seat_bounds() {
		update_option( 'wc_memberships_for_teams_owners_must_take_seat', 'yes' );
		$ids = $this->create_variable_team_product(
			'',
			[
				'Small' => 5,
				'Solo'  => 1,
				'Open'  => '',
			],
			[ '_wc_memberships_for_teams_pricing' => 'per_member' ],
			[ 'Small' => [ '_wc_memberships_for_teams_min_member_count' => 2 ] ]
		);

		( new Teams_Migration() )->migrate_team_products( [], [ 'live' => true ] );

		$seats_of = function ( $id ) {
			$settings = \Newspack\Group_Subscription_Settings::get_product_settings( $id );
			return [ $settings['pricing_mode'], $settings['min_seats'], $settings['max_seats'] ];
		};
		$this->assertSame( [ 'per_seat', 2, 5 ], $seats_of( $ids['Small'] ), 'A 2–5 member variation should sell 2–5 seats when the owner holds one of them.' );
		$this->assertSame( [ 'per_seat', 1, 0 ], $seats_of( $ids['Open'] ), 'A variation without a maximum should sell unbounded seats.' );
		$caps = array_column( WP_CLI::$tables[0]['items'], 'Limit / max seats', 'Product' );
		$this->assertSame( 1, $caps[ $ids['Solo'] ], 'The summary should show the seat maximum for a per-seat variation, not the flat limit.' );
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
