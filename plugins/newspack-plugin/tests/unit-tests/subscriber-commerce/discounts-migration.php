<?php
/**
 * Tests the WooCommerce Memberships → subscriber discounts mapping.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\CLI\Discounts_Migration;
use Newspack\Subscriber_Discounts;

/**
 * How a Memberships purchasing-discount rule becomes a subscriber discount.
 *
 * The mapping is exercised directly rather than through WP-CLI: the command
 * body is reporting, and this is the part that decides what a migrated site
 * ends up charging.
 *
 * @group subscriber-commerce
 * @group Subscriber_Discounts
 */
class Test_Discounts_Migration extends \WP_UnitTestCase {

	/**
	 * Resolve every plan to the same two subscription products.
	 *
	 * @return callable
	 */
	private function plan_granted_by_two_subscriptions() {
		return function () {
			return [ 11, 22 ];
		};
	}

	/**
	 * A Memberships purchasing-discount rule.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function memberships_rule( $overrides = [] ) {
		return array_merge(
			[
				'id'                 => 'rule_1',
				'membership_plan_id' => 500,
				'rule_type'          => 'purchasing_discount',
				'content_type'       => 'post_type',
				'content_type_name'  => 'product',
				'object_ids'         => [ 101, 102 ],
				'discount_type'      => 'amount',
				'discount_amount'    => '151',
				'active'             => 'yes',
			],
			$overrides
		);
	}

	/**
	 * The migrating sites' common shape: a fixed amount off a hand-picked list
	 * of products, for a plan granted by many subscription products. The
	 * audience becomes every product that granted the plan — one rule, not one
	 * per granting product.
	 */
	public function test_maps_a_fixed_amount_product_rule() {
		$mapped = Discounts_Migration::map_rules( [ $this->memberships_rule() ], $this->plan_granted_by_two_subscriptions() );

		$this->assertCount( 1, $mapped['rules'], 'One Memberships rule becomes one subscriber discount.' );
		$this->assertEmpty( $mapped['skipped'], 'Nothing to skip.' );

		$rule = $mapped['rules'][0];
		$this->assertSame( [ 11, 22 ], $rule['subscription_product_ids'], 'Every product that granted the plan becomes part of the audience.' );
		$this->assertSame( 'products', $rule['targeting'], 'Product ids map to specific-product targeting.' );
		$this->assertSame( [ 101, 102 ], $rule['product_ids'], 'The discounted products carry over.' );
		$this->assertSame( 'fixed', $rule['discount_type'], "Memberships' 'amount' is a fixed discount." );
		$this->assertEquals( 151.0, $rule['amount'], 'The amount carries over.' );
		$this->assertTrue( $rule['active'], 'An enabled rule stays enabled.' );
	}

	/**
	 * A taxonomy rule becomes category targeting, and a percentage becomes a
	 * percentage.
	 */
	public function test_maps_a_percentage_category_rule() {
		$mapped = Discounts_Migration::map_rules(
			[
				$this->memberships_rule(
					[
						'content_type'      => 'taxonomy',
						'content_type_name' => 'product_cat',
						'object_ids'        => [ 77 ],
						'discount_type'     => 'percentage',
						'discount_amount'   => '10',
					]
				),
			],
			$this->plan_granted_by_two_subscriptions()
		);

		$rule = $mapped['rules'][0];
		$this->assertSame( 'category', $rule['targeting'], 'A taxonomy rule targets categories.' );
		$this->assertSame( [ 77 ], $rule['category_ids'], 'The category carries over.' );
		$this->assertSame( [], $rule['product_ids'], 'A category rule carries no product ids.' );
		$this->assertSame( 'percent', $rule['discount_type'], "Memberships' 'percentage' is a percentage discount." );
	}

	/**
	 * Memberships treats an empty selection as "everything".
	 */
	public function test_empty_selection_maps_to_all_products() {
		$mapped = Discounts_Migration::map_rules(
			[ $this->memberships_rule( [ 'object_ids' => [] ] ) ],
			$this->plan_granted_by_two_subscriptions()
		);

		$this->assertSame( 'all', $mapped['rules'][0]['targeting'], 'No selection means the whole store.' );
	}

	/**
	 * A rule disabled in Memberships must not come back on during migration —
	 * at least one site carries a deliberately disabled discount rule.
	 */
	public function test_disabled_rules_migrate_paused() {
		$mapped = Discounts_Migration::map_rules(
			[ $this->memberships_rule( [ 'active' => 'no' ] ) ],
			$this->plan_granted_by_two_subscriptions()
		);

		$this->assertFalse( $mapped['rules'][0]['active'], 'A disabled discount stays paused after migration.' );
	}

	/**
	 * Memberships plans can be granted purely by hand, with no product. There is
	 * no subscription to key a discount on, and guessing one would discount for
	 * the wrong readers — so the rule is reported instead of migrated.
	 */
	public function test_plans_without_a_granting_product_are_skipped_not_guessed() {
		$mapped = Discounts_Migration::map_rules(
			[ $this->memberships_rule() ],
			function () {
				return [];
			}
		);

		$this->assertEmpty( $mapped['rules'], 'Nothing is migrated for a plan with no granting product.' );
		$this->assertCount( 1, $mapped['skipped'], 'The rule is reported for a human decision.' );
		$this->assertSame( 'rule_1', $mapped['skipped'][0]['source'], 'The report names the source rule.' );
	}

	/**
	 * Only purchasing discounts are migrated; content and product restrictions
	 * are a different feature and must not become discounts.
	 */
	public function test_other_rule_types_are_ignored() {
		$mapped = Discounts_Migration::map_rules(
			[
				$this->memberships_rule( [ 'rule_type' => 'content_restriction' ] ),
				$this->memberships_rule( [ 'rule_type' => 'product_restriction' ] ),
			],
			$this->plan_granted_by_two_subscriptions()
		);

		$this->assertEmpty( $mapped['rules'], 'Restriction rules are not discounts.' );
		$this->assertEmpty( $mapped['skipped'], 'Nor are they reported as problems.' );
	}

	/**
	 * Products flagged in Memberships as never discounted are carried onto the
	 * rules that would otherwise sweep them up. A hand-picked product list needs
	 * no exclusions — the publisher chose exactly those products.
	 */
	public function test_globally_excluded_products_become_rule_exclusions() {
		$excluded_product_ids = [ 999 ];

		$category_rule = Discounts_Migration::map_rules(
			[
				$this->memberships_rule(
					[
						'content_type'      => 'taxonomy',
						'content_type_name' => 'product_cat',
						'object_ids'        => [ 77 ],
					]
				),
			],
			$this->plan_granted_by_two_subscriptions(),
			$excluded_product_ids
		)['rules'][0];
		$this->assertSame( [ 999 ], $category_rule['excluded_product_ids'], 'A category rule inherits the excluded products.' );

		$product_rule = Discounts_Migration::map_rules(
			[ $this->memberships_rule() ],
			$this->plan_granted_by_two_subscriptions(),
			$excluded_product_ids
		)['rules'][0];
		$this->assertSame( [], $product_rule['excluded_product_ids'], 'A hand-picked product list needs no exclusions.' );
	}

	/**
	 * Memberships can target any product taxonomy; a subscriber discount
	 * resolves categories only. A tag- or attribute-based rule migrated into
	 * `category_ids` would match nothing while reporting success, so it is
	 * reported for a human instead.
	 */
	public function test_non_category_taxonomy_rules_are_skipped() {
		$mapped = Discounts_Migration::map_rules(
			[
				$this->memberships_rule(
					[
						'content_type'      => 'taxonomy',
						'content_type_name' => 'product_tag',
						'object_ids'        => [ 55 ],
					]
				),
			],
			$this->plan_granted_by_two_subscriptions()
		);

		$this->assertEmpty( $mapped['rules'], 'A tag-targeted discount is not migrated as a category rule.' );
		$this->assertCount( 1, $mapped['skipped'], 'It is reported instead.' );
		$this->assertStringContainsString( 'product_tag', $mapped['skipped'][0]['reason'], 'The report names the taxonomy that could not be expressed.' );
	}

	/**
	 * An unrecognized discount type must not fall through to "fixed": that would
	 * turn "10% off" into "$10 off" and report it as a clean migration.
	 */
	public function test_unknown_discount_types_are_skipped() {
		$mapped = Discounts_Migration::map_rules(
			[ $this->memberships_rule( [ 'discount_type' => '' ] ) ],
			$this->plan_granted_by_two_subscriptions()
		);

		$this->assertEmpty( $mapped['rules'], 'A rule with no discount type is not migrated.' );
		$this->assertCount( 1, $mapped['skipped'], 'It is reported instead.' );
	}

	/**
	 * Migrations get re-run — a first pass, a fix, a second pass. Rules carry an
	 * id derived from their source rule so a re-run updates them in place; minting
	 * a fresh id each time would duplicate every rule.
	 */
	public function test_rerunning_updates_rules_in_place() {
		delete_option( Subscriber_Discounts::OPTION_NAME );

		$store_mapped_rules = function () {
			foreach ( Discounts_Migration::map_rules( [ $this->memberships_rule() ], $this->plan_granted_by_two_subscriptions() )['rules'] as $rule ) {
				unset( $rule['_source_rule_id'], $rule['_source_plan_id'] );
				Subscriber_Discounts::save_rule( $rule );
			}
		};

		$store_mapped_rules();
		$store_mapped_rules();

		$this->assertCount( 1, Subscriber_Discounts::get_rules(), 'Running the migration twice leaves one rule, not two.' );
	}

	/**
	 * The mapping's output is accepted by the rule store — the two must not
	 * drift apart, or a migration would report success and store nothing.
	 */
	public function test_mapped_rules_are_valid_for_the_store() {
		delete_option( Subscriber_Discounts::OPTION_NAME );

		$mapped = Discounts_Migration::map_rules( [ $this->memberships_rule() ], $this->plan_granted_by_two_subscriptions() );
		$rule   = $mapped['rules'][0];
		unset( $rule['_source_rule_id'], $rule['_source_plan_id'] );

		$saved = Subscriber_Discounts::save_rule( $rule );

		$this->assertNotWPError( $saved, 'A mapped rule must be storable as-is.' );
		$this->assertCount( 1, Subscriber_Discounts::get_rules(), 'The migrated rule is persisted.' );
	}
}
