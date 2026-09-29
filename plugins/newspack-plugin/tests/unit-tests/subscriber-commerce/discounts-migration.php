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
	 * Load the WooCommerce mocks the stacking report reads products through.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
	}

	/**
	 * Discard the mock product registry between assertions.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * A product with a regular price, registered so wc_get_product() finds it.
	 *
	 * @param float $regular_price Regular price.
	 * @return int Product post id.
	 */
	private function create_priced_product( $regular_price ) {
		register_post_type( 'product', [ 'public' => true ] );
		$product_id = $this->factory->post->create( [ 'post_type' => 'product' ] );

		global $products_database;
		$products_database[ $product_id ] = new \WC_Product( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			[
				'id'            => $product_id,
				'name'          => 'Annual pass',
				'regular_price' => $regular_price,
			]
		);

		return $product_id;
	}

	/**
	 * Give a reader an active membership of each of the given plans.
	 *
	 * @param int   $user_id  Reader.
	 * @param int[] $plan_ids Plans they hold.
	 */
	private function grant_memberships( $user_id, $plan_ids ) {
		foreach ( $plan_ids as $plan_id ) {
			$this->factory->post->create(
				[
					'post_type'   => 'wc_user_membership',
					'post_status' => 'wcm-active',
					'post_author' => $user_id,
					'post_parent' => $plan_id,
				]
			);
		}
	}

	/**
	 * Run the store-level settings port against whatever options are set.
	 */
	private function report_settings_parity() {
		\WP_CLI::reset();
		$report_settings_parity_method = new \ReflectionMethod( Discounts_Migration::class, 'report_settings_parity' );
		$report_settings_parity_method->setAccessible( true );
		$report_settings_parity_method->invoke( null, false, 1, [] );
	}

	/**
	 * The private reader/product comparison, which is where the report's
	 * numbers come from.
	 *
	 * @param array $rules_by_plan Active purchasing-discount rules keyed by plan id.
	 * @return array[]
	 */
	private function readers_losing_stacked_discounts( $rules_by_plan ) {
		$readers_losing_stacked_discounts_method = new \ReflectionMethod( Discounts_Migration::class, 'readers_losing_stacked_discounts' );
		$readers_losing_stacked_discounts_method->setAccessible( true );
		return $readers_losing_stacked_discounts_method->invoke( null, $rules_by_plan );
	}

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
	 * A plan's rule taking a percentage off one product.
	 *
	 * @param int    $plan_id    Membership plan.
	 * @param int    $product_id Discounted product.
	 * @param string $percentage Percentage off.
	 * @return array
	 */
	private function percentage_rule( $plan_id, $product_id, $percentage ) {
		return $this->memberships_rule(
			[
				'membership_plan_id' => $plan_id,
				'object_ids'         => [ $product_id ],
				'discount_type'      => 'percentage',
				'discount_amount'    => $percentage,
			]
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
	 * Products flagged in Memberships as never discounted stay undiscounted.
	 *
	 * Memberships applies that flag before any rule matches, so it beats a rule
	 * that names the product outright. A subscriber discount has nowhere to put
	 * an exclusion on a hand-picked product list, so the flagged product comes
	 * off the list instead.
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
			[ $this->memberships_rule( [ 'object_ids' => [ 101, 999 ] ] ) ],
			$this->plan_granted_by_two_subscriptions(),
			$excluded_product_ids
		)['rules'][0];
		$this->assertSame( [], $product_rule['excluded_product_ids'], 'A hand-picked product list carries no exclusions; the store would drop them.' );
		$this->assertSame( [ 101 ], $product_rule['product_ids'], 'The flagged product is dropped from the list rather than silently discounted.' );

		$skipped = Discounts_Migration::map_rules(
			[ $this->memberships_rule( [ 'object_ids' => [ 999 ] ] ) ],
			$this->plan_granted_by_two_subscriptions(),
			$excluded_product_ids
		)['skipped'];
		$this->assertCount( 1, $skipped, 'A rule left with no products at all is reported rather than saved as a rule that discounts nothing.' );
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

	/**
	 * The report exists to price one thing: a reader holding two plans whose
	 * discounts hit the same product. Memberships compounds them, Access
	 * Control applies the better one alone, so that reader starts paying more.
	 * 20% then 20% off 100 is 64 under Memberships and 80 after the flip.
	 *
	 * Overlapping rules alone are not the finding — most sites carrying two
	 * discount plans have nobody in both, and a reader in one plan pays the
	 * same either way. Only the reader in both is reported.
	 */
	public function test_a_reader_holding_two_discounting_plans_pays_more_after_the_flip() {
		$product_id = $this->create_priced_product( 100.00 );
		$reader_id  = $this->factory->user->create();
		$this->grant_memberships( $reader_id, [ 500, 600 ] );
		$this->grant_memberships( $this->factory->user->create(), [ 500 ] );

		$affected_readers = $this->readers_losing_stacked_discounts(
			[
				500 => [ $this->percentage_rule( 500, $product_id, '20' ) ],
				600 => [ $this->percentage_rule( 600, $product_id, '20' ) ],
			]
		);

		$this->assertCount( 1, $affected_readers, 'Only the reader holding both plans is reported, and once, for the shared product.' );
		$this->assertSame( $reader_id, $affected_readers[0]['user_id'], 'The affected reader is named, so the publisher can be shown who.' );
		$this->assertEquals( 64.0, $affected_readers[0]['stacked_price'], 'Memberships compounds the two rules rather than adding them.' );
		$this->assertEquals( 80.0, $affected_readers[0]['best_price'], 'Access Control applies the single best rule.' );
	}

	/**
	 * Both of Memberships' store-level discount settings have to survive the
	 * flip. The on-sale one inverts on the way across — Memberships stores an
	 * *exclusion* — and the upsell one maps directly. A site that turned either
	 * on and lost it would quietly charge its subscribers differently.
	 */
	public function test_store_level_settings_carry_across_from_memberships() {
		delete_option( Subscriber_Discounts::SETTINGS_OPTION_NAME );
		update_option( Discounts_Migration::EXCLUDE_ON_SALE_OPTION, 'yes' );
		update_option( Discounts_Migration::APPLY_WHEN_PURCHASING_OPTION, 'yes' );

		$this->report_settings_parity();

		$settings = Subscriber_Discounts::get_settings();
		$this->assertFalse( $settings['apply_on_sale'], 'Memberships excluding on-sale products means ours must not apply to them.' );
		$this->assertTrue( $settings['apply_at_checkout'], 'A site using the upsell keeps it.' );
	}

	/**
	 * A re-run must not revert a setting the publisher changed after migrating.
	 */
	public function test_stored_settings_are_left_alone_on_a_re_run() {
		Subscriber_Discounts::save_settings( [ 'apply_at_checkout' => true ] );
		update_option( Discounts_Migration::APPLY_WHEN_PURCHASING_OPTION, 'no' );

		$this->report_settings_parity();

		$this->assertTrue(
			Subscriber_Discounts::get_settings()['apply_at_checkout'],
			'A publisher-set value survives a second migration run.'
		);
	}
}
