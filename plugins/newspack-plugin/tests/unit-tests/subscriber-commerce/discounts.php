<?php
/**
 * Tests the subscriber-discount rule store: sanitization, validation, and the
 * pure discount math every other surface (price filters, admin preview,
 * migration) depends on.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscriber_Discounts;

/**
 * Subscriber discount rule storage and math.
 *
 * @group Subscriber_Discounts
 */
class Newspack_Test_Subscriber_Discounts_Storage extends WP_UnitTestCase {

	/**
	 * A minimal valid rule: subscribers of subscription 10 get £5 off product 200.
	 *
	 * @return array
	 */
	private function valid_rule() {
		return [
			'subscription_product_ids' => [ 10 ],
			'targeting'                => 'products',
			'product_ids'              => [ 200 ],
			'discount_type'            => 'fixed',
			'amount'                   => 5.0,
		];
	}

	/**
	 * Reset the store between tests so option state never leaks across cases.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Subscriber_Discounts::OPTION_NAME );
		delete_option( Subscriber_Discounts::SETTINGS_OPTION_NAME );
	}

	/**
	 * A saved rule gets an id and a creation date, and comes back with every
	 * field present so consumers never have to null-check the shape.
	 */
	public function test_save_rule_assigns_id_and_fills_defaults() {
		$saved_rule = Subscriber_Discounts::save_rule( $this->valid_rule() );

		$this->assertNotWPError( $saved_rule, 'A minimal valid rule should save.' );
		$this->assertNotEmpty( $saved_rule['id'], 'Saving must assign an id.' );
		$this->assertNotEmpty( $saved_rule['created_at'], 'Saving must stamp a creation date.' );
		$this->assertTrue( $saved_rule['active'], 'Rules are active unless explicitly paused.' );
		$this->assertSame( [], $saved_rule['category_ids'], 'Unused targeting fields are still present, as empty arrays.' );
		$this->assertSame( [], $saved_rule['excluded_product_ids'], 'Unused targeting fields are still present, as empty arrays.' );

		$this->assertCount( 1, Subscriber_Discounts::get_rules(), 'The saved rule should be readable back.' );
	}

	/**
	 * Re-saving an existing rule updates it in place rather than appending a
	 * duplicate — the editor saves the whole rule on every edit.
	 */
	public function test_save_rule_updates_in_place() {
		$saved_rule = Subscriber_Discounts::save_rule( $this->valid_rule() );

		$updated_rule = Subscriber_Discounts::save_rule( array_merge( $saved_rule, [ 'amount' => 7.5 ] ) );

		$this->assertSame( $saved_rule['id'], $updated_rule['id'], 'Updating must keep the same id.' );
		$this->assertCount( 1, Subscriber_Discounts::get_rules(), 'Updating must not append a second rule.' );
		$this->assertEquals( 7.5, Subscriber_Discounts::get_rule( $saved_rule['id'] )['amount'], 'The new amount should persist.' );
	}

	/**
	 * Targeting fields that don't belong to the selected mode are dropped on
	 * save. Without this, switching a rule from "specific products" to
	 * "category" in the editor would leave the old product ids behind, and the
	 * runtime — which reads the fields, not the UI state — would apply a rule
	 * the publisher believes they replaced.
	 */
	public function test_save_rule_clears_targeting_fields_from_other_modes() {
		$rule_switched_to_category = Subscriber_Discounts::save_rule(
			[
				'subscription_product_ids' => [ 10 ],
				'targeting'                => 'category',
				'category_ids'             => [ 30 ],
				'product_ids'              => [ 200, 201 ],
				'discount_type'            => 'percent',
				'amount'                   => 10,
			]
		);

		$this->assertSame( [], $rule_switched_to_category['product_ids'], 'A category rule must not retain product ids.' );
		$this->assertSame( [ 30 ], $rule_switched_to_category['category_ids'], 'The category selection is kept.' );

		$rule_targeting_specific_products = Subscriber_Discounts::save_rule(
			[
				'subscription_product_ids' => [ 10 ],
				'targeting'                => 'products',
				'product_ids'              => [ 200 ],
				'excluded_product_ids'     => [ 201 ],
				'discount_type'            => 'fixed',
				'amount'                   => 5,
			]
		);

		$this->assertSame(
			[],
			$rule_targeting_specific_products['excluded_product_ids'],
			'Exclusions are meaningless when the publisher hand-picked the products — the picker is the exclusion.'
		);
	}

	/**
	 * Invalid rules are rejected rather than silently stored, because a stored
	 * bad rule would mis-price the storefront.
	 *
	 * @dataProvider invalid_rule_provider
	 *
	 * @param array  $invalid_rule  The rule to reject.
	 * @param string $expected_code The expected WP_Error code.
	 * @param string $why           Why this rule is invalid.
	 */
	public function test_save_rule_rejects_invalid_rules( $invalid_rule, $expected_code, $why ) {
		$result = Subscriber_Discounts::save_rule( $invalid_rule );

		$this->assertWPError( $result, $why );
		$this->assertSame( $expected_code, $result->get_error_code(), $why );
		$this->assertCount( 0, Subscriber_Discounts::get_rules(), 'A rejected rule must not be persisted.' );
	}

	/**
	 * Invalid rule cases.
	 *
	 * @return array
	 */
	public function invalid_rule_provider() {
		$base = [
			'subscription_product_ids' => [ 10 ],
			'targeting'                => 'products',
			'product_ids'              => [ 200 ],
			'discount_type'            => 'fixed',
			'amount'                   => 5.0,
		];
		return [
			'no audience'            => [
				array_merge( $base, [ 'subscription_product_ids' => [] ] ),
				'newspack_subscriber_discount_no_audience',
				'A rule with no subscription would discount for everyone.',
			],
			'zero amount'            => [
				array_merge( $base, [ 'amount' => 0 ] ),
				'newspack_subscriber_discount_invalid_amount',
				'A zero discount is not a discount.',
			],
			'negative amount'        => [
				array_merge( $base, [ 'amount' => -5 ] ),
				'newspack_subscriber_discount_invalid_amount',
				'A negative discount would raise the price.',
			],
			'over 100 percent'       => [
				array_merge(
					$base,
					[
						'discount_type' => 'percent',
						'amount'        => 101,
					]
				),
				'newspack_subscriber_discount_invalid_amount',
				'Over 100% off would imply paying the reader.',
			],
			'products with none set' => [
				array_merge( $base, [ 'product_ids' => [] ] ),
				'newspack_subscriber_discount_no_products',
				'"Specific products" with no products selected matches nothing.',
			],
			'category with none set' => [
				array_merge(
					$base,
					[
						'targeting'    => 'category',
						'category_ids' => [],
					]
				),
				'newspack_subscriber_discount_no_categories',
				'"Category" with no category selected matches nothing.',
			],
			'unknown targeting'      => [
				array_merge( $base, [ 'targeting' => 'everything' ] ),
				'newspack_subscriber_discount_invalid_targeting',
				'An unrecognized targeting mode must not fall through to a permissive default.',
			],
		];
	}

	/**
	 * A rule targeting the whole store needs no product or category selection —
	 * it is the one mode where empty targeting fields are correct.
	 */
	public function test_all_products_rule_needs_no_selection() {
		$store_wide_rule = Subscriber_Discounts::save_rule(
			[
				'subscription_product_ids' => [ 10 ],
				'targeting'                => 'all',
				'discount_type'            => 'percent',
				'amount'                   => 5,
			]
		);

		$this->assertNotWPError( $store_wide_rule, 'An all-products rule is valid with no selection.' );
	}

	/**
	 * Pausing keeps the rule but takes it out of circulation, so a publisher can
	 * end a promotion without losing its configuration.
	 */
	public function test_paused_rules_are_kept_but_excluded_from_active_rules() {
		$saved_rule = Subscriber_Discounts::save_rule( $this->valid_rule() );

		Subscriber_Discounts::set_rule_active( $saved_rule['id'], false );

		$this->assertCount( 1, Subscriber_Discounts::get_rules(), 'A paused rule is still stored.' );
		$this->assertCount( 0, Subscriber_Discounts::get_active_rules(), 'A paused rule never prices anything.' );
	}

	/**
	 * Deleting removes the rule outright.
	 */
	public function test_delete_rule() {
		$saved_rule = Subscriber_Discounts::save_rule( $this->valid_rule() );

		$this->assertTrue( Subscriber_Discounts::delete_rule( $saved_rule['id'] ), 'Deleting an existing rule reports success.' );
		$this->assertCount( 0, Subscriber_Discounts::get_rules(), 'The rule is gone.' );
		$this->assertFalse( Subscriber_Discounts::delete_rule( 'nope' ), 'Deleting a missing rule reports failure rather than throwing.' );
	}

	/**
	 * Settings read back with defaults filled in, and saving merges rather than
	 * replaces, so a caller that only knows about one setting can't wipe the
	 * others.
	 */
	public function test_settings_defaults_and_merge_on_save() {
		$default_settings = Subscriber_Discounts::get_settings();

		$this->assertTrue( $default_settings['apply_on_sale'], 'Products already on sale are discounted by default, as they are in Memberships.' );
		$this->assertFalse( $default_settings['apply_at_checkout'], 'A subscription in the cart does not discount the rest of the order by default.' );

		Subscriber_Discounts::save_settings( [ 'apply_on_sale' => false ] );

		$merged_settings = Subscriber_Discounts::get_settings();
		$this->assertFalse( $merged_settings['apply_on_sale'], 'The saved setting persists.' );
		$this->assertFalse( $merged_settings['apply_at_checkout'], 'Untouched settings keep their value.' );
	}

	/**
	 * The discount math, which the price filters, the admin preview and the
	 * migration report all share.
	 *
	 * @dataProvider discount_math_provider
	 *
	 * @param float      $base_price     Price before the discount.
	 * @param string     $discount_type  'fixed' or 'percent'.
	 * @param float      $amount         Discount amount.
	 * @param float|null $expected_price Expected discounted price, or null when no discount applies.
	 * @param string     $why            What this case pins down.
	 */
	public function test_discounted_price( $base_price, $discount_type, $amount, $expected_price, $why ) {
		$rule = [
			'discount_type' => $discount_type,
			'amount'        => $amount,
		];

		$this->assertSame( $expected_price, Subscriber_Discounts::discounted_price( $base_price, $rule ), $why );
	}

	/**
	 * Discount math cases.
	 *
	 * @return array
	 */
	public function discount_math_provider() {
		return [
			'percentage off'                 => [ 520.0, 'percent', 15.0, 442.0, '15% off 520 is 442 — the design\'s worked example.' ],
			'fixed amount off'               => [ 1450.0, 'fixed', 151.0, 1299.0, 'Fixed amounts are how the migrating sites hand-tune to a clean member price.' ],
			'rounds to two decimals'         => [ 9.99, 'percent', 10.0, 8.99, 'Prices are rounded to currency precision, half away from zero, as WooCommerce does.' ],
			'fixed larger than price floors' => [ 5.0, 'fixed', 20.0, 0.0, 'A discount bigger than the price floors at zero rather than going negative.' ],
			'hundred percent is free'        => [ 40.0, 'percent', 100.0, 0.0, '100% off is free, not negative.' ],
			'zero-priced product unchanged'  => [ 0.0, 'fixed', 5.0, null, 'A free product has nothing to discount, so no fake sale price is produced.' ],
		];
	}

	/**
	 * When several rules cover the same product only the largest reduction
	 * applies, and every rule is measured against the catalog price rather than
	 * against what a previous rule left behind. WooCommerce Memberships instead
	 * compounds them, so this is the one place where a migrated site's prices
	 * deliberately differ — pinned here in both directions.
	 */
	public function test_combined_price_takes_the_single_best_discount() {
		$ten_percent_off = [
			'discount_type' => 'percent',
			'amount'        => 10,
		];
		$five_pounds_off = [
			'discount_type' => 'fixed',
			'amount'        => 5,
		];

		$best_price = Subscriber_Discounts::combined_price( 100.0, [ $ten_percent_off, $five_pounds_off ] );

		$this->assertSame(
			90.0,
			$best_price,
			'The larger reduction wins (10% off 100 beats £5 off), not the first or last rule.'
		);
		$this->assertNotSame(
			85.0,
			$best_price,
			'Overlapping rules must not accumulate — 100 → 90 → 85 is Memberships behaviour, not ours.'
		);
		$this->assertSame(
			90.0,
			Subscriber_Discounts::combined_price( 100.0, [ $five_pounds_off, $ten_percent_off ] ),
			'The order rules happen to be stored in cannot change what a reader pays.'
		);
	}

	/**
	 * A discount larger than the product's price settles at zero rather than
	 * going negative.
	 */
	public function test_combined_price_floors_at_zero() {
		$sixty_pounds_off = [
			'discount_type' => 'fixed',
			'amount'        => 60,
		];

		$this->assertSame(
			0.0,
			Subscriber_Discounts::combined_price( 50.0, [ $sixty_pounds_off ] ),
			'£60 off a £50 product is free, not -£10.'
		);
	}

	/**
	 * No applicable rules means no price change at all — the caller must be able
	 * to tell "no discount" from "discounted to the same price".
	 */
	public function test_combined_price_returns_null_when_no_rule_applies() {
		$this->assertNull(
			Subscriber_Discounts::combined_price( 100.0, [] ),
			'An empty rule set leaves the price untouched.'
		);
	}

	/**
	 * Rules are returned newest first, matching the admin list's default order.
	 */
	public function test_rules_are_returned_newest_first() {
		$older_rule = Subscriber_Discounts::save_rule( array_merge( $this->valid_rule(), [ 'created_at' => '2026-01-01' ] ) );
		$newer_rule = Subscriber_Discounts::save_rule( array_merge( $this->valid_rule(), [ 'created_at' => '2026-06-01' ] ) );

		$rule_ids_in_order = wp_list_pluck( Subscriber_Discounts::get_rules(), 'id' );

		$this->assertSame( [ $newer_rule['id'], $older_rule['id'] ], $rule_ids_in_order, 'Newest rules sort first.' );
	}
}
