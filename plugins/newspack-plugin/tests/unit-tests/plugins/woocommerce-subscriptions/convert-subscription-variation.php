<?php
/**
 * Tests for the convert-subscription-variation CLI command.
 *
 * The command converts a variation of a variable subscription product into a standalone
 * simple subscription product in place, keeping its ID so subscription line items, gate
 * rules, and automations keyed on the ID keep working.
 *
 * These tests exercise the pure decision helpers and the target validation directly. The
 * conversion and line-item phases operate on WooCommerce data stores and caches that the
 * test environment does not provide (WC is mocked, not loaded), so that glue is thin and
 * was verified end-to-end on a production migration; the invariants it relies on are
 * documented on the class.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\CLI\Convert_Subscription_Variation;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
// Loaded directly: the Composer classmap of an existing checkout predates this file.
require_once __DIR__ . '/../../../../includes/cli/class-convert-subscription-variation.php';

/**
 * Test the convert-subscription-variation validation and pruning decisions.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Convert_Subscription_Variation extends WP_UnitTestCase {

	/**
	 * Reset the mock product database before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		$products_database = [];
	}

	/**
	 * Clean up the mock product database after each test.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = [];
		parent::tear_down();
	}

	/**
	 * Register a mock parent product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $type       Product type.
	 */
	private function register_parent( int $product_id, string $type = 'variable-subscription' ) {
		global $products_database;
		$products_database[ $product_id ] = new WC_Product(
			[
				'id'   => $product_id,
				'name' => 'Parent',
				'type' => $type,
			]
		);
	}

	/**
	 * Create a real variation post carrying attribute meta.
	 *
	 * @param int   $parent_id  Parent post ID.
	 * @param array $attributes Attribute slug => value.
	 * @return int The variation post ID.
	 */
	private function create_variation_post( int $parent_id, array $attributes = [] ): int {
		$variation_id = self::factory()->post->create(
			[
				'post_type'   => 'product_variation',
				'post_title'  => 'Subscription - Print - Yearly',
				'post_parent' => $parent_id,
			]
		);
		foreach ( $attributes as $slug => $value ) {
			update_post_meta( $variation_id, 'attribute_' . $slug, $value );
		}
		return $variation_id;
	}

	/**
	 * A variation of a variable subscription validates and reports its parent, title,
	 * and attribute values keyed by slug.
	 */
	public function test_validate_target_accepts_subscription_variation() {
		$this->register_parent( 900 );
		$variation_id = $this->create_variation_post( 900, [ 'plan' => 'Print - In Area - Yearly' ] );

		$target = Convert_Subscription_Variation::validate_target( $variation_id );

		$this->assertIsArray( $target );
		$this->assertSame( $variation_id, $target['variation_id'] );
		$this->assertSame( 900, $target['parent_id'] );
		$this->assertSame( 'Subscription - Print - Yearly', $target['title'] );
		$this->assertSame( [ 'plan' => 'Print - In Area - Yearly' ], $target['attributes'] );
	}

	/**
	 * A missing post, a non-variation post, and a variation of a non-subscription
	 * variable product are each rejected.
	 */
	public function test_validate_target_rejects_invalid_targets() {
		$this->assertWPError( Convert_Subscription_Variation::validate_target( 999999 ) );

		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->assertWPError( Convert_Subscription_Variation::validate_target( $page_id ) );

		// Variation of a plain variable (non-subscription) product.
		$this->register_parent( 901, 'variable' );
		$variation_id = $this->create_variation_post( 901 );
		$this->assertWPError( Convert_Subscription_Variation::validate_target( $variation_id ) );

		// Variation whose parent no longer resolves at all.
		$orphan_id = $this->create_variation_post( 902 );
		$this->assertWPError( Convert_Subscription_Variation::validate_target( $orphan_id ) );
	}

	/**
	 * A product carrying the conversion stash validates as an interrupted run to resume,
	 * with parent and attributes recovered from the stash; a plain product without the
	 * stash is rejected.
	 */
	public function test_validate_target_resumes_interrupted_conversion() {
		$product_id = self::factory()->post->create(
			[
				'post_type'  => 'product',
				'post_title' => 'Subscription - Print - Yearly',
			]
		);
		$this->assertWPError( Convert_Subscription_Variation::validate_target( $product_id ) );

		update_post_meta( $product_id, Convert_Subscription_Variation::CONVERTED_PARENT_META, 900 );
		update_post_meta( $product_id, Convert_Subscription_Variation::CONVERTED_ATTRIBUTES_META, [ 'plan' => 'Print - In Area - Yearly' ] );

		$target = Convert_Subscription_Variation::validate_target( $product_id );
		$this->assertIsArray( $target );
		$this->assertTrue( $target['already_converted'] );
		$this->assertSame( 900, $target['parent_id'] );
		$this->assertSame( [ 'plan' => 'Print - In Area - Yearly' ], $target['attributes'] );
	}

	/**
	 * A fresh variation target is flagged as not yet converted.
	 */
	public function test_validate_target_flags_fresh_targets() {
		$this->register_parent( 900 );
		$variation_id = $this->create_variation_post( 900 );
		$target       = Convert_Subscription_Variation::validate_target( $variation_id );
		$this->assertFalse( $target['already_converted'] );
	}

	/**
	 * An attribute option is pruned when the converted variation was its only user.
	 */
	public function test_prune_removes_option_only_the_converted_variation_used() {
		$options = [ 'Digital Monthly', 'Digital Yearly', 'Print Yearly' ];
		$kept    = Convert_Subscription_Variation::compute_pruned_options(
			$options,
			'Print Yearly',
			[ 'Digital Monthly', 'Digital Yearly' ],
			'Print Yearly'
		);
		$this->assertSame( [ 'Digital Monthly', 'Digital Yearly' ], $kept );
	}

	/**
	 * An attribute option survives when another remaining variation still uses the same
	 * value.
	 */
	public function test_prune_keeps_option_still_used_by_a_remaining_variation() {
		$options = [ 'Digital Monthly', 'Print Yearly' ];
		$kept    = Convert_Subscription_Variation::compute_pruned_options(
			$options,
			'Print Yearly',
			[ 'Digital Monthly', 'Print Yearly' ],
			'Print Yearly'
		);
		$this->assertSame( $options, $kept );
	}

	/**
	 * Taxonomy-backed attributes store term IDs as options; pruning compares them
	 * loosely by string so int and numeric-string IDs match.
	 */
	public function test_prune_matches_taxonomy_term_ids_across_types() {
		$kept = Convert_Subscription_Variation::compute_pruned_options(
			[ 11, 12, 13 ],
			'12',
			[ 'digital-monthly' ],
			'print-yearly'
		);
		$this->assertSame( [ 11, 13 ], $kept );
	}

	/**
	 * Line items store the displayed attribute value under the attribute slug; the keys
	 * to delete come from the variation's attribute meta keys, lower-cased.
	 */
	public function test_item_display_meta_keys_derive_from_attribute_slugs() {
		$this->assertSame(
			[ 'plan', 'pa_delivery-zone' ],
			Convert_Subscription_Variation::item_display_meta_keys(
				[
					'plan'             => 'Print - In Area - Yearly',
					'pa_Delivery-Zone' => 'in-area',
				]
			)
		);
	}
}
