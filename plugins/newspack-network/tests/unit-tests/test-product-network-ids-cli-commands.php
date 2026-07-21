<?php
/**
 * Class TestProductNetworkIdsCLICommands
 *
 * End-to-end coverage of the assign/verify commands against real posts, postmeta and options,
 * driven through the WP_CLI shim in tests/class-wp-cli.php. These pin the behaviour a migration
 * runbook depends on: never writing a blank Network ID, never exiting 0 on a partial assignment,
 * and never greening a flip gate on a product that grants nothing.
 *
 * @package Newspack_Network
 */

use Newspack_Network\CLI\Product_Network_Ids;
use Newspack_Network\Site_Role;
use Newspack_Network\Woocommerce\Product_Admin;
use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;
use Newspack_Network\Incoming_Events\Product_Updated;

/**
 * Test the assign and verify commands.
 */
class TestProductNetworkIdsCLICommands extends WP_UnitTestCase {

	/**
	 * Set the site up as a Hub and register the post types WooCommerce would ( it is not active here ).
	 */
	public function set_up() {
		parent::set_up();

		update_option( Site_Role::OPTION_NAME, Site_Role::HUB_ROLE );
		register_post_type( 'product', [ 'public' => true ] );
		register_post_type( 'product_variation', [ 'public' => true ] );
		register_post_type( Memberships_Admin::MEMBERSHIP_PLANS_CPT, [ 'public' => true ] );

		WP_CLI::reset();
	}

	/**
	 * Create a product, optionally already carrying a Network ID.
	 *
	 * @param string $network_id The Network ID to tag it with, or '' to leave it untagged.
	 * @return int The product ID.
	 */
	private function create_product( $network_id = '' ) {
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		if ( '' !== $network_id ) {
			update_post_meta( $product_id, Product_Admin::NETWORK_ID_META_KEY, $network_id );
		}
		return $product_id;
	}

	/**
	 * Create a membership plan linking the given products.
	 *
	 * @param string $network_id  The plan's Network ID meta value.
	 * @param array  $product_ids The linked product IDs.
	 * @return int The plan ID.
	 */
	private function create_plan( $network_id, array $product_ids ) {
		$plan_id = self::factory()->post->create( [ 'post_type' => Memberships_Admin::MEMBERSHIP_PLANS_CPT ] );
		update_post_meta( $plan_id, Memberships_Admin::NETWORK_ID_META_KEY, $network_id );
		update_post_meta( $plan_id, '_product_ids', $product_ids );
		return $plan_id;
	}

	/**
	 * The Network ID currently stored on a product.
	 *
	 * @param int $product_id The product ID.
	 * @return string
	 */
	private function get_network_id( $product_id ) {
		return (string) get_post_meta( $product_id, Product_Admin::NETWORK_ID_META_KEY, true );
	}

	/**
	 * A plan whose Network ID is whitespace-only must never blank a correctly tagged product.
	 *
	 * The value survives a naive emptiness check but sanitizes to '', so before sanitization moved
	 * ahead of that check --overwrite wrote the blank over a correct value and propagated it.
	 */
	public function test_assign_never_writes_a_blank_network_id_over_a_correct_one() {
		$product_id = $this->create_product( 'golden-net' );
		$this->create_plan( '   ', [ $product_id ] );

		$this->expectException( WP_CLI_Halt::class );
		try {
			Product_Network_Ids::assign(
				[],
				[
					'apply'     => true,
					'overwrite' => true,
				]
			);
		} finally {
			$this->assertSame( 'golden-net', $this->get_network_id( $product_id ) );
			$this->assertStringContainsString( 'carry no Network ID', WP_CLI::get_output() );
		}
	}

	/**
	 * The happy path: a plan's Network ID lands on every product it links, and the command exits 0.
	 */
	public function test_assign_writes_the_plans_network_id_to_its_products() {
		$first_product_id  = $this->create_product();
		$second_product_id = $this->create_product();
		$this->create_plan( 'premium', [ $first_product_id, $second_product_id ] );

		Product_Network_Ids::assign( [], [ 'apply' => true ] );

		$this->assertSame( 'premium', $this->get_network_id( $first_product_id ) );
		$this->assertSame( 'premium', $this->get_network_id( $second_product_id ) );
		$this->assertStringContainsString( 'Success:', WP_CLI::get_output() );
	}

	/**
	 * A plan linking a variation tags the parent product, where the Network ID is read from.
	 */
	public function test_assign_folds_a_linked_variation_into_its_parent_product() {
		$parent_id    = $this->create_product();
		$variation_id = self::factory()->post->create(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $parent_id,
			]
		);
		$this->create_plan( 'premium', [ $variation_id ] );

		Product_Network_Ids::assign( [], [ 'apply' => true ] );

		$this->assertSame( 'premium', $this->get_network_id( $parent_id ) );
		$this->assertSame( '', $this->get_network_id( $variation_id ) );
	}

	/**
	 * A product claimed by two plans with different Network IDs is withheld -- and the command exits
	 * non-zero, so a scripted migration cannot record a green step for a partial assignment.
	 */
	public function test_assign_withholds_a_conflicted_product_and_exits_non_zero() {
		$conflicted_product_id  = $this->create_product();
		$unambiguous_product_id = $this->create_product();
		$this->create_plan( 'premium', [ $conflicted_product_id, $unambiguous_product_id ] );
		$this->create_plan( 'basic', [ $conflicted_product_id ] );

		$this->expectException( WP_CLI_Halt::class );
		try {
			Product_Network_Ids::assign( [], [ 'apply' => true ] );
		} finally {
			$this->assertSame( '', $this->get_network_id( $conflicted_product_id ) );
			$this->assertSame( 'premium', $this->get_network_id( $unambiguous_product_id ) );
		}
	}

	/**
	 * --products with a value that parses to no IDs ( an unset shell variable in a runbook ) must fail
	 * rather than report "nothing to check" and exit 0 -- this is the path a flip gate runs.
	 */
	public function test_verify_errors_when_the_products_flag_parses_to_nothing() {
		$this->create_product( 'premium' );

		$this->expectException( WP_CLI_Halt::class );
		try {
			Product_Network_Ids::verify( [], [ 'products' => '' ] );
		} finally {
			$this->assertStringContainsString( '--products was passed', WP_CLI::get_output() );
		}
	}

	/**
	 * Bare verify checks plan-linked products, not just already-tagged ones: otherwise everything
	 * assign withholds is excluded from the check by construction and the gate greens a network
	 * where a gate product grants nothing.
	 */
	public function test_verify_checks_plan_linked_products_that_assign_could_not_resolve() {
		$conflicted_product_id = $this->create_product();
		$this->create_plan( 'premium', [ $conflicted_product_id ] );
		$this->create_plan( 'basic', [ $conflicted_product_id ] );

		// A healthy, linked product, so the run would otherwise be green.
		$tagged_product_id = $this->create_product( 'gold' );
		update_option(
			Product_Updated::OPTION_NAME,
			[
				'https://other-site.test' => [
					9001 => [
						'id'         => 9001,
						'network_id' => 'gold',
					],
				],
			]
		);

		$this->expectException( WP_CLI_Halt::class );
		try {
			Product_Network_Ids::verify( [], [] );
		} finally {
			$output = WP_CLI::get_output();
			$this->assertStringContainsString( sprintf( '#%d: no Network ID set', $conflicted_product_id ), $output );
			$this->assertStringContainsString( sprintf( '✓ #%d "gold"', $tagged_product_id ), $output );
			$this->assertStringContainsString( 'Not ready to flip', $output );
		}
	}

	/**
	 * A green verify run: every checked product carries a Network ID another site also carries.
	 */
	public function test_verify_passes_when_a_product_is_linked_on_another_site() {
		$product_id = $this->create_product( 'gold' );
		$this->create_plan( 'gold', [ $product_id ] );
		update_option(
			Product_Updated::OPTION_NAME,
			[
				// The current site's own entry never counts as a link, so another site must carry it.
				get_bloginfo( 'url' )     => [
					$product_id => [
						'id'         => $product_id,
						'network_id' => 'gold',
					],
				],
				'https://other-site.test' => [
					9001 => [
						'id'         => 9001,
						'network_id' => 'gold',
					],
				],
			]
		);

		Product_Network_Ids::verify( [], [] );

		$this->assertStringContainsString( 'Success:', WP_CLI::get_output() );
	}

	/**
	 * --map must be a JSON object keyed by product ID: a JSON list's 0..n-1 keys would otherwise be
	 * taken for product IDs.
	 */
	public function test_assign_rejects_a_json_list_map() {
		$this->expectException( WP_CLI_Halt::class );
		try {
			Product_Network_Ids::assign( [], [ 'map' => '["premium","basic"]' ] );
		} finally {
			$this->assertStringContainsString( 'got a JSON list', WP_CLI::get_output() );
		}
	}

	/**
	 * A non-integer --map key is reported and skipped rather than cast to an unrelated product ID
	 * ( "12abc" would otherwise write to product 12 ).
	 */
	public function test_assign_skips_map_entries_with_non_integer_keys() {
		$product_id = $this->create_product();

		Product_Network_Ids::assign(
			[],
			[
				'map'   => wp_json_encode(
					[
						'12abc'              => 'premium',
						(string) $product_id => 'premium',
					]
				),
				'apply' => true,
			]
		);

		$this->assertSame( 'premium', $this->get_network_id( $product_id ) );
		$this->assertSame( '', (string) get_post_meta( 12, Product_Admin::NETWORK_ID_META_KEY, true ) );
		$this->assertStringContainsString( 'Skipping --map entry "12abc"', WP_CLI::get_output() );
	}
}
