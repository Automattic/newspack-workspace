<?php
/**
 * Tests the shared product targeting behind subscriber-commerce rules.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Product_Targeting;
use Newspack\Subscriber_Commerce;

/**
 * Tests how a rule's "Applies to" fields resolve to store products — the single
 * source of truth shared by subscriber-only products and subscriber discounts.
 *
 * WooCommerce is not loaded in the test suite, so products are the repo's
 * `WC_Product` mocks backed by real `product` posts (real posts are needed:
 * category matching goes through `has_term()`).
 *
 * @group subscriber-commerce
 * @group Product_Targeting
 */
class Test_Product_Targeting extends \WP_UnitTestCase {

	/**
	 * A simple product.
	 *
	 * @var \WC_Product
	 */
	private $product;

	/**
	 * A variable product.
	 *
	 * @var \WC_Product
	 */
	private $variable_product;

	/**
	 * A variation of the variable product.
	 *
	 * @var \WC_Product
	 */
	private $variation;

	/**
	 * Parent product category.
	 *
	 * @var int
	 */
	private $parent_category_id;

	/**
	 * Child of the parent product category.
	 *
	 * @var int
	 */
	private $child_category_id;

	/**
	 * Load the WooCommerce mocks.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Register the product post type and category taxonomy (the real ones come
	 * from WooCommerce), then build the products used across the assertions.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type( 'product', [ 'public' => true ] );
		register_post_type( 'product_variation', [ 'public' => false ] );
		register_taxonomy(
			'product_cat',
			'product',
			[
				'public'       => true,
				// As WooCommerce registers it: restricting a category restricts its children.
				'hierarchical' => true,
			]
		);

		$this->parent_category_id = $this->factory->term->create( [ 'taxonomy' => 'product_cat' ] );
		$this->child_category_id  = $this->factory->term->create(
			[
				'taxonomy' => 'product_cat',
				'parent'   => $this->parent_category_id,
			]
		);

		$this->product          = $this->create_product();
		$this->variable_product = $this->create_product();
		$this->variation        = $this->create_product( $this->variable_product->get_id() );

		Product_Targeting::flush_cache();
	}

	/**
	 * Reset the memoized targeting between assertions.
	 */
	public function tear_down() {
		Product_Targeting::flush_cache();
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Create a product post plus its mock, registered so wc_get_product() finds it.
	 *
	 * @param int    $parent_id Parent product ID, for a variation.
	 * @param string $type      WooCommerce product type.
	 * @param int[]  $children  Child product IDs, for a grouped product.
	 *
	 * @return \WC_Product
	 */
	private function create_product( $parent_id = 0, $type = 'simple', $children = [] ) {
		$post_id = $this->factory->post->create(
			[
				'post_type'   => $parent_id ? 'product_variation' : 'product',
				'post_parent' => $parent_id,
			]
		);
		$product = new \WC_Product(
			[
				'id'        => $post_id,
				'parent_id' => $parent_id,
				'type'      => $type,
				'children'  => $children,
			]
		);

		global $products_database;
		$products_database[ $post_id ] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return $product;
	}

	/**
	 * Build a sanitized rule with the given overrides.
	 *
	 * @param array $overrides Rule fields to override.
	 *
	 * @return array
	 */
	private function make_rule( $overrides = [] ) {
		return Subscriber_Commerce::sanitize_base_rule(
			array_merge(
				[
					'id'                       => 'rule',
					'subscription_product_ids' => [ 1 ],
					'active'                   => true,
				],
				$overrides
			)
		);
	}

	/**
	 * A rule listing a product covers that product and nothing else.
	 */
	public function test_specific_products_targeting() {
		$rule = $this->make_rule(
			[
				'targeting'   => 'products',
				'product_ids' => [ $this->product->get_id() ],
			]
		);

		$this->assertTrue( Product_Targeting::rule_covers_product( $rule, $this->product ) );
		$this->assertFalse( Product_Targeting::rule_covers_product( $rule, $this->variable_product ) );
	}

	/**
	 * A variation is covered when its parent is listed: publishers pick the
	 * parent product, never the individual variations.
	 */
	public function test_variation_is_covered_through_its_parent() {
		$rule = $this->make_rule(
			[
				'targeting'   => 'products',
				'product_ids' => [ $this->variable_product->get_id() ],
			]
		);

		$this->assertTrue( Product_Targeting::rule_covers_product( $rule, $this->variation ) );
	}

	/**
	 * Covering a category covers its subcategories. Without this, a rule
	 * covering "Premium" would leave everything in "Premium > Merch" out.
	 */
	public function test_category_targeting_cascades_to_subcategories() {
		wp_set_object_terms( $this->product->get_id(), [ $this->child_category_id ], 'product_cat' );

		$rule = $this->make_rule(
			[
				'targeting'    => 'category',
				'category_ids' => [ $this->parent_category_id ],
			]
		);

		$this->assertTrue( Product_Targeting::rule_covers_product( $rule, $this->product ) );
	}

	/**
	 * A variation carries no terms of its own, so it matches through its
	 * parent's categories.
	 */
	public function test_variation_matches_parent_categories() {
		wp_set_object_terms( $this->variable_product->get_id(), [ $this->parent_category_id ], 'product_cat' );

		$rule = $this->make_rule(
			[
				'targeting'    => 'category',
				'category_ids' => [ $this->parent_category_id ],
			]
		);

		$this->assertTrue( Product_Targeting::rule_covers_product( $rule, $this->variation ) );
	}

	/**
	 * A product outside every listed category is not covered.
	 */
	public function test_category_targeting_skips_uncategorized_products() {
		$rule = $this->make_rule(
			[
				'targeting'    => 'category',
				'category_ids' => [ $this->parent_category_id ],
			]
		);

		$this->assertFalse( Product_Targeting::rule_covers_product( $rule, $this->product ) );
	}

	/**
	 * "All products" covers everything but the exclusions.
	 */
	public function test_all_targeting_honors_exclusions() {
		$rule = $this->make_rule(
			[
				'targeting'            => 'all',
				'excluded_product_ids' => [ $this->product->get_id() ],
			]
		);

		$this->assertFalse( Product_Targeting::rule_covers_product( $rule, $this->product ) );
		$this->assertTrue( Product_Targeting::rule_covers_product( $rule, $this->variable_product ) );
	}

	/**
	 * Excluding a variable product excludes its variations too — otherwise the
	 * product a publisher excluded would still be sold, by variation.
	 */
	public function test_exclusion_applies_to_variations_of_an_excluded_parent() {
		$rule = $this->make_rule(
			[
				'targeting'            => 'all',
				'excluded_product_ids' => [ $this->variable_product->get_id() ],
			]
		);

		$this->assertFalse( Product_Targeting::rule_covers_product( $rule, $this->variation ) );
	}

	/**
	 * Excluding a grouped product does NOT free the standalone products sold
	 * under it.
	 *
	 * A grouped product's children are ordinary top-level products, also sold on
	 * their own, so reaching through the container to exclude them would lift the
	 * rule off those products everywhere — widening access on the strength of one
	 * bundle exclusion. An exclusion therefore means exactly the IDs listed (and
	 * their variations), and excluding a grouped container is a no-op on its
	 * children. Whether it should behave otherwise is a product decision left to
	 * the branch owner (PR #742 review); this test pins today's behaviour so the
	 * decision can't be reversed by accident.
	 */
	public function test_excluding_a_grouped_product_does_not_free_its_children() {
		$grouped = $this->create_product(
			0,
			'grouped',
			[ $this->product->get_id() ]
		);

		$rule = $this->make_rule(
			[
				'targeting'            => 'all',
				'excluded_product_ids' => [ $grouped->get_id() ],
			]
		);

		// The grouped container itself, named in the list, is excluded.
		$this->assertFalse( Product_Targeting::rule_covers_product( $rule, $grouped ) );
		// Its child, a standalone product, stays covered by the "all" rule.
		$this->assertTrue( Product_Targeting::rule_covers_product( $rule, $this->product ) );
	}

	/**
	 * Pausing a rule hands its products back.
	 */
	public function test_inactive_rules_are_skipped() {
		$rules = [
			$this->make_rule(
				[
					'targeting'   => 'products',
					'product_ids' => [ $this->product->get_id() ],
					'active'      => false,
				]
			),
		];

		$this->assertSame( [], Product_Targeting::get_matching_rules( $rules, $this->product ) );
	}

	/**
	 * Every rule covering a product is returned, so callers can decide across
	 * all of them rather than only the first.
	 */
	public function test_get_matching_rules_returns_every_covering_rule() {
		wp_set_object_terms( $this->product->get_id(), [ $this->parent_category_id ], 'product_cat' );

		$by_name     = $this->make_rule(
			[
				'id'          => 'byname',
				'targeting'   => 'products',
				'product_ids' => [ $this->product->get_id() ],
			]
		);
		$by_category = $this->make_rule(
			[
				'id'           => 'bycategory',
				'targeting'    => 'category',
				'category_ids' => [ $this->parent_category_id ],
			]
		);

		$matching = Product_Targeting::get_matching_rules( [ $by_name, $by_category ], $this->product );

		$this->assertCount( 2, $matching );
		$this->assertSame( [ 'byname', 'bycategory' ], wp_list_pluck( $matching, 'id' ) );
	}

	/**
	 * Two rule sets that differ must not share a memoized verdict — the cache
	 * key has to cover the rules, not just the product.
	 */
	public function test_memoization_distinguishes_rule_sets() {
		$covering = [
			$this->make_rule(
				[
					'targeting'   => 'products',
					'product_ids' => [ $this->product->get_id() ],
				]
			),
		];
		$other    = [
			$this->make_rule(
				[
					'targeting'   => 'products',
					'product_ids' => [ $this->variable_product->get_id() ],
				]
			),
		];

		$this->assertCount( 1, Product_Targeting::get_matching_rules( $covering, $this->product ) );
		$this->assertCount( 0, Product_Targeting::get_matching_rules( $other, $this->product ) );
	}

	/**
	 * An unrecognized targeting value falls back to specific products, so a
	 * malformed rule can never widen to the whole store.
	 */
	public function test_unknown_targeting_falls_back_to_specific_products() {
		$rule = $this->make_rule( [ 'targeting' => 'everything' ] );

		$this->assertSame( 'products', $rule['targeting'] );
		$this->assertFalse( Product_Targeting::rule_covers_product( $rule, $this->product ) );
	}
}
