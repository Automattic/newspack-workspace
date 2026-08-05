<?php
/**
 * Tests tier composition under the APFS subscription-plan product model.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * Test tiers under the subscription-plan product model.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Tiers_Plans extends WP_UnitTestCase {
	/**
	 * Reset mock state before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database, $subscriptions_database;
		$products_database                              = [];
		$subscriptions_database                          = [];
		WCS_ATT_Product_Schemes::$products_with_schemes = [];
		WCS_ATT_Product_Schemes::$product_schemes       = [];
		// spl_object_id() is recycled once a clone is freed, so a stale plan key
		// from a previous test's clone can otherwise leak onto an unrelated
		// object in this one.
		WCS_ATT_Product_Schemes::$active_schemes = [];
	}

	/**
	 * Reset mock state after each test.
	 */
	public function tear_down() {
		WCS_ATT_Product_Schemes::$products_with_schemes = [];
		WCS_ATT_Product_Schemes::$product_schemes       = [];
		WCS_ATT_Product_Schemes::$active_schemes         = [];
		parent::tear_down();
	}

	/**
	 * Register a product with the given plans in the mock scheme registry.
	 *
	 * @param int   $id    Product ID.
	 * @param array $plans [ scheme_key => [ 'period' => string, 'interval' => int ] ].
	 */
	protected function give_plans( $id, $plans ) {
		WCS_ATT_Product_Schemes::$products_with_schemes[] = $id;
		WCS_ATT_Product_Schemes::$product_schemes[ $id ]  = $plans;
	}

	/**
	 * A product's plans come back keyed by scheme key.
	 */
	public function test_get_subscription_plans_returns_schemes() {
		$product = wc_create_mock_product(
			[
				'id'   => 300,
				'type' => 'variable',
			]
		);
		$this->give_plans(
			300,
			[
				'mkey' => [
					'period'   => 'month',
					'interval' => 1,
				],
				'ykey' => [
					'period'   => 'year',
					'interval' => 1,
				],
			]
		);

		$plans = WooCommerce_Subscriptions::get_subscription_plans( $product );
		$this->assertEquals( [ 'mkey', 'ykey' ], array_keys( $plans ) );
		$this->assertSame( 'month_1', WooCommerce_Subscriptions::get_plan_frequency( $plans['mkey'] ) );
		$this->assertSame( 'year_1', WooCommerce_Subscriptions::get_plan_frequency( $plans['ykey'] ) );
	}

	/**
	 * A product with no plans returns an empty array, not a warning.
	 */
	public function test_get_subscription_plans_without_plans() {
		$product = wc_create_mock_product(
			[
				'id'   => 301,
				'type' => 'variable',
			]
		);
		$this->assertSame( [], WooCommerce_Subscriptions::get_subscription_plans( $product ) );
	}

	/**
	 * A stamped plan wins over legacy meta.
	 *
	 * The legacy meta here is the hardcoded `month` that
	 * WC_Subscriptions_Admin::set_variation_meta_defaults_on_bulk_add() writes
	 * onto every generated variation. An annual plan must not render as monthly.
	 */
	public function test_frequency_comes_from_the_stamped_plan() {
		$product = wc_create_mock_product(
			[
				'id'   => 310,
				'type' => 'variable',
				'meta' => [
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		$this->give_plans(
			310,
			[
				'ykey' => [
					'period'   => 'year',
					'interval' => 1,
				],
			]
		);

		WCS_ATT_Product_Schemes::set_subscription_scheme( $product, 'ykey' );

		$this->assertSame( 'year_1', \Newspack\Subscriptions_Tiers::get_frequency( $product ) );
	}

	/**
	 * With no plan stamped, legacy meta still drives the frequency.
	 */
	public function test_frequency_falls_back_to_legacy_meta() {
		$product = wc_create_mock_product(
			[
				'id'   => 311,
				'type' => 'subscription',
				'meta' => [
					'_subscription_period'          => 'week',
					'_subscription_period_interval' => '2',
				],
			]
		);

		$this->assertSame( 'week_2', \Newspack\Subscriptions_Tiers::get_frequency( $product ) );
	}

	/**
	 * A plan-based variable product yields one bucket per plan, each holding
	 * every variation. This is the NPPM-3053 regression: an annual plan that
	 * never reached the modal at all.
	 */
	public function test_plan_based_variable_product_yields_one_bucket_per_plan() {
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 320,
				'type'     => 'variable',
				'children' => [ 321, 322 ],
			]
		);
		$this->give_plans( 320, $plans );
		foreach ( [ 321, 322 ] as $vid ) {
			wc_create_mock_product(
				[
					'id'        => $vid,
					'type'      => 'variation',
					'parent_id' => 320,
				]
			);
			$this->give_plans( $vid, $plans );
		}

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );

		$this->assertSame( [ 'month_1', 'year_1' ], array_keys( $tiers ) );
		$this->assertCount( 2, $tiers['month_1'] );
		$this->assertCount( 2, $tiers['year_1'] );
		$this->assertSame(
			[ 321, 322 ],
			array_map(
				function ( $p ) {
					return $p->get_id();
				},
				$tiers['year_1']
			)
		);
	}

	/**
	 * Two plans sharing a period and interval get their own buckets rather than
	 * merging into one and duplicating the tiers.
	 */
	public function test_plans_sharing_a_frequency_do_not_merge() {
		$plans = [
			'cheap' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'dear'  => [
				'period'   => 'month',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 330,
				'type'     => 'variable',
				'children' => [ 331 ],
			]
		);
		$this->give_plans( 330, $plans );
		wc_create_mock_product(
			[
				'id'        => 331,
				'type'      => 'variation',
				'parent_id' => 330,
			]
		);
		$this->give_plans( 331, $plans );

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );

		$this->assertCount( 2, $tiers, 'Both plans should survive, in separate buckets.' );
		foreach ( $tiers as $products ) {
			$this->assertCount( 1, $products );
		}
	}

	/**
	 * A legacy `variable-subscription` product (no plans) must produce exactly
	 * the buckets it produced before NPPM-3053: one bucket keyed by the legacy
	 * period/interval, the same product IDs, in the same order. This pins the
	 * "expansion is additive, legacy flow unperturbed" constraint in CI —
	 * previously only exercised manually against a live fixture.
	 */
	public function test_legacy_variable_subscription_product_is_unchanged() {
		$parent = wc_create_mock_product(
			[
				'id'       => 340,
				'type'     => 'variable-subscription',
				'children' => [ 341, 342 ],
			]
		);
		foreach ( [ 341, 342 ] as $vid ) {
			wc_create_mock_product(
				[
					'id'        => $vid,
					'type'      => 'variation',
					'parent_id' => 340,
					'meta'      => [
						'_subscription_period'          => 'month',
						'_subscription_period_interval' => '1',
					],
				]
			);
		}

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );

		$this->assertSame( [ 'month_1' ], array_keys( $tiers ) );
		$this->assertSame(
			[ 341, 342 ],
			array_map(
				function ( $p ) {
					return $p->get_id();
				},
				$tiers['month_1']
			)
		);
	}

	/**
	 * A grouped product's children are each their own cart-item parent, so
	 * APFS's convert_to_sub_<parent_id> - a single key at the form level -
	 * can never be keyed correctly for a plan-based child. On `release`, a
	 * grouped product with a plan-based (`variable`) child already yields no
	 * tiers, because the legacy child-type check rejects `variable`. This
	 * pins that same "no tiers" outcome for the plan model, rather than
	 * letting the child's plans expand into a purchasable-but-unaddressable
	 * form that would take a one-time payment instead of subscribing.
	 */
	public function test_grouped_product_with_only_a_plan_based_child_yields_no_tiers() {
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 350,
				'type'     => 'grouped',
				'children' => [ 351 ],
			]
		);
		wc_create_mock_product(
			[
				'id'       => 351,
				'type'     => 'variable',
				'children' => [ 352, 353 ],
			]
		);
		$this->give_plans( 351, $plans );
		foreach ( [ 352, 353 ] as $vid ) {
			wc_create_mock_product(
				[
					'id'        => $vid,
					'type'      => 'variation',
					'parent_id' => 351,
				]
			);
			$this->give_plans( $vid, $plans );
		}

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );

		$this->assertSame( [], $tiers );
	}

	/**
	 * A grouped product mixing a legacy child and a plan-based child must
	 * still yield the legacy child's tiers, unchanged - the plan-based
	 * child's exclusion (see the test above) is additive, not a blanket
	 * "grouped products are broken" regression.
	 */
	public function test_grouped_product_with_mixed_children_yields_only_the_legacy_tiers() {
		$parent = wc_create_mock_product(
			[
				'id'       => 355,
				'type'     => 'grouped',
				'children' => [ 356, 357 ],
			]
		);
		wc_create_mock_product(
			[
				'id'   => 356,
				'type' => 'subscription',
				'meta' => [
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		wc_create_mock_product(
			[
				'id'       => 357,
				'type'     => 'variable',
				'children' => [ 358, 359 ],
			]
		);
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
		];
		$this->give_plans( 357, $plans );
		foreach ( [ 358, 359 ] as $vid ) {
			wc_create_mock_product(
				[
					'id'        => $vid,
					'type'      => 'variation',
					'parent_id' => 357,
				]
			);
			$this->give_plans( $vid, $plans );
		}

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );

		$this->assertSame( [ 'month_1' ], array_keys( $tiers ) );
		$this->assertSame(
			[ 356 ],
			array_map(
				function ( $p ) {
					return $p->get_id();
				},
				$tiers['month_1']
			)
		);
	}

	/**
	 * NPPM-3053 regression for get_current_tier(): under the plan model the
	 * same variation ID is stamped into every plan's bucket
	 * (get_tiers_by_frequency()'s expansion), so ID-only matching resolved an
	 * annual subscriber to whichever bucket happened to be checked first
	 * (`month_1`). A reader on the annual plan must resolve to `year_1`.
	 */
	public function test_get_current_tier_matches_the_subscribed_plan() {
		$user_id = self::factory()->user->create();

		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 350,
				'type'     => 'variable',
				'children' => [ 351 ],
			]
		);
		$this->give_plans( 350, $plans );
		wc_create_mock_product(
			[
				'id'        => 351,
				'type'      => 'variation',
				'parent_id' => 350,
			]
		);
		$this->give_plans( 351, $plans );

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );
		$this->assertSame( [ 'month_1', 'year_1' ], array_keys( $tiers ), 'Precondition: both buckets exist.' );

		// The reader's subscription holds variation 351, checked out on the
		// annual scheme - `_wcsatt_scheme` is the order item meta APFS records
		// at checkout.
		$item = new WC_Order_Item_Product(
			[
				'id'         => 900,
				'product_id' => 351,
				'meta'       => [ '_wcsatt_scheme' => 'ykey' ],
			]
		);
		wcs_create_subscription(
			[
				'customer_id'      => $user_id,
				'status'           => 'active',
				'products'         => [ 351 ],
				'items'            => [ $item ],
				'billing_period'   => 'year',
				'billing_interval' => 1,
			]
		);

		[ $frequency, $product, $subscription ] = \Newspack\Subscriptions_Tiers::get_current_tier( $tiers, $user_id );

		$this->assertSame( 'year_1', $frequency );
		$this->assertNotNull( $product );
		$this->assertNotNull( $subscription );
	}

	/**
	 * The get_current_tier() fallback path: when the subscription's line item
	 * carries no resolvable scheme key, the match falls back to comparing
	 * billing period/interval against the bucket's frequency - still enough
	 * to tell the annual bucket from the monthly one.
	 */
	public function test_get_current_tier_falls_back_to_billing_period_without_a_scheme_key() {
		$user_id = self::factory()->user->create();

		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 360,
				'type'     => 'variable',
				'children' => [ 361 ],
			]
		);
		$this->give_plans( 360, $plans );
		wc_create_mock_product(
			[
				'id'        => 361,
				'type'      => 'variation',
				'parent_id' => 360,
			]
		);
		$this->give_plans( 361, $plans );

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );

		// No item, so no `_wcsatt_scheme` to resolve - only the billing
		// period/interval on the subscription itself.
		wcs_create_subscription(
			[
				'customer_id'      => $user_id,
				'status'           => 'active',
				'products'         => [ 361 ],
				'billing_period'   => 'year',
				'billing_interval' => 1,
			]
		);

		[ $frequency ] = \Newspack\Subscriptions_Tiers::get_current_tier( $tiers, $user_id );

		$this->assertSame( 'year_1', $frequency );
	}

	/**
	 * A subscription created back when the site ran the standalone All
	 * Products for Subscriptions plugin (pre-WCS-9.0) carries the APFS-v1
	 * meta key `_wcsatt_scheme_id`, not the current `_wcsatt_scheme`. The
	 * scheme lookup must still resolve it via `WCS_ATT_Order::get_subscription_scheme()`
	 * rather than reading `_wcsatt_scheme` directly, or these are exactly the
	 * publishers most likely to have plan-based products - the ones who were
	 * early on APFS - and they'd silently drop to the weaker billing-period
	 * fallback instead.
	 */
	public function test_get_current_tier_resolves_the_v1_scheme_key() {
		$user_id = self::factory()->user->create();

		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 370,
				'type'     => 'variable',
				'children' => [ 371 ],
			]
		);
		$this->give_plans( 370, $plans );
		wc_create_mock_product(
			[
				'id'        => 371,
				'type'      => 'variation',
				'parent_id' => 370,
			]
		);
		$this->give_plans( 371, $plans );

		$tiers = \Newspack\Subscriptions_Tiers::get_tiers_by_frequency( $parent );
		$this->assertSame( [ 'month_1', 'year_1' ], array_keys( $tiers ), 'Precondition: both buckets exist.' );

		// The v1 key, no `_wcsatt_scheme` present - and a billing period/interval
		// that would itself resolve to the *wrong* bucket if the v1 key weren't
		// read, proving the scheme-key path (not the fallback) is what matched.
		$item = new WC_Order_Item_Product(
			[
				'id'         => 901,
				'product_id' => 371,
				'meta'       => [ '_wcsatt_scheme_id' => 'ykey' ],
			]
		);
		wcs_create_subscription(
			[
				'customer_id'      => $user_id,
				'status'           => 'active',
				'products'         => [ 371 ],
				'items'            => [ $item ],
				'billing_period'   => 'month',
				'billing_interval' => 1,
			]
		);

		[ $frequency ] = \Newspack\Subscriptions_Tiers::get_current_tier( $tiers, $user_id );

		$this->assertSame( 'year_1', $frequency );
	}

	/**
	 * Card period and interval follow the stamped plan, not the legacy meta the
	 * editor stamps on generated variations.
	 */
	public function test_frequency_parts_follow_the_plan() {
		$product = wc_create_mock_product(
			[
				'id'   => 340,
				'type' => 'variable',
				'meta' => [
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		$this->give_plans(
			340,
			[
				'ykey' => [
					'period'   => 'year',
					'interval' => 2,
				],
			]
		);
		WCS_ATT_Product_Schemes::set_subscription_scheme( $product, 'ykey' );

		$this->assertSame(
			[
				'period'   => 'year',
				'interval' => 2,
			],
			\Newspack\Subscriptions_Tiers::get_frequency_parts( $product )
		);
	}

	/**
	 * Without a stamped plan, get_frequency_parts() falls back to legacy meta.
	 */
	public function test_frequency_parts_falls_back_to_legacy_meta() {
		$product = wc_create_mock_product(
			[
				'id'   => 411,
				'type' => 'subscription',
				'meta' => [
					'_subscription_period'          => 'week',
					'_subscription_period_interval' => '2',
				],
			]
		);

		$this->assertSame(
			[
				'period'   => 'week',
				'interval' => 2,
			],
			\Newspack\Subscriptions_Tiers::get_frequency_parts( $product )
		);
	}

	/**
	 * When legacy meta is absent, get_frequency_parts() floors the interval at 1,
	 * preventing division-by-zero errors in render_nyp_product_card().
	 */
	public function test_frequency_parts_floors_absent_interval_at_one() {
		$product = wc_create_mock_product(
			[
				'id'   => 412,
				'type' => 'subscription',
				'meta' => [
					'_subscription_period' => 'month',
					// No _subscription_period_interval meta.
				],
			]
		);

		$this->assertSame(
			[
				'period'   => 'month',
				'interval' => 1,
			],
			\Newspack\Subscriptions_Tiers::get_frequency_parts( $product )
		);
	}

	/**
	 * The frequency control posts the chosen plan, so APFS can resolve it from
	 * $_REQUEST. Without this the cart falls back to no plan and the reader is
	 * charged once instead of subscribing.
	 */
	public function test_frequency_control_posts_the_plan_key() {
		ob_start();
		\Newspack\Subscriptions_Tiers::render_frequency_control(
			[ 'month_1', 'year_1' ],
			'month_1',
			false,
			[
				'month_1' => 'mkey',
				'year_1'  => 'ykey',
			],
			350
		);
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'name="convert_to_sub_350"', $markup );
		$this->assertStringContainsString( 'value="mkey"', $markup );
		$this->assertStringContainsString( 'value="ykey"', $markup );
		// Exactly one radio checked - assertStringContainsString( 'checked' )
		// alone would pass with zero or with both checked, neither of which
		// is a valid form state. Count the `checked='checked'` attribute
		// checked() emits, not the bare word "checked" - it appears twice
		// within that single attribute (name and value).
		$this->assertSame( 1, substr_count( $markup, "checked='checked'" ), 'Exactly one radio should be checked.' );
		// And it must be the one for the initially-visible panel (`month_1`,
		// the $current_frequency argument), not just any radio.
		if ( preg_match( '/<input[^>]*value="mkey"[^>]*>/s', $markup, $checked_input ) ) {
			$this->assertStringContainsString( 'checked', $checked_input[0], 'The month_1/mkey radio should be the checked one.' );
		} else {
			$this->fail( 'Could not find the mkey radio in the markup.' );
		}
	}

	/**
	 * Legacy products keep the button control - there is no plan to post.
	 */
	public function test_frequency_control_stays_buttons_without_plans() {
		ob_start();
		\Newspack\Subscriptions_Tiers::render_frequency_control( [ 'month_1', 'year_1' ], 'month_1' );
		$markup = ob_get_clean();

		$this->assertStringContainsString( '<button', $markup );
		$this->assertStringNotContainsString( 'convert_to_sub', $markup );
	}

	/**
	 * NPPM-3053 critical regression: a product with exactly one plan (the
	 * ordinary publisher setup - one monthly plan plus price-tier
	 * variations) has only one frequency bucket, so
	 * render_frequency_control() is never called (it only runs for >1
	 * frequency). render_form() must fall back to a hidden input carrying
	 * that single plan's key, or the reader is charged once instead of
	 * subscribing - exactly the bug this task exists to fix, for the most
	 * common case.
	 */
	public function test_render_form_posts_the_plan_key_for_a_single_plan_product() {
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 380,
				'type'     => 'variable',
				'children' => [ 381, 382 ],
			]
		);
		$this->give_plans( 380, $plans );
		foreach ( [ 381, 382 ] as $vid ) {
			wc_create_mock_product(
				[
					'id'        => $vid,
					'type'      => 'variation',
					'parent_id' => 380,
					'price'     => 5,
				]
			);
			$this->give_plans( $vid, $plans );
		}

		ob_start();
		\Newspack\Subscriptions_Tiers::render_form( $parent );
		$markup = ob_get_clean();

		// Exactly one convert_to_sub input, and it's the hidden fallback -
		// not a radio, since there's only one plan to choose from. The form
		// still has other, unrelated radios: the price-tier `product_id`
		// selection (see render_product_card()), which is untouched by this
		// task and deliberately excluded from this assertion.
		$this->assertSame( 1, substr_count( $markup, 'convert_to_sub_380' ), 'Exactly one convert_to_sub input should be posted.' );
		$this->assertStringContainsString( '<input type="hidden" name="convert_to_sub_380" value="mkey">', $markup );
		$this->assertStringNotContainsString( 'type="radio" name="convert_to_sub_380"', $markup, 'A single plan has nothing to choose between, so no plan radio should render.' );
	}

	/**
	 * A product with more than one plan renders the plan-radio control
	 * (render_frequency_control()'s job, covered directly above), and
	 * render_form() must wire it up with the correct plan-key map and
	 * parent ID, with exactly one radio checked - the one for the
	 * initially-visible panel.
	 */
	public function test_render_form_wires_up_the_plan_radios_for_a_multi_plan_product() {
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		// Two variations per plan (price tiers), matching the ordinary
		// publisher setup and keeping is_single_tier() false, so render_form()
		// takes the tabbed-panel path this test targets rather than the flat
		// "current subscription" card layout used when every frequency has
		// exactly one product.
		$parent = wc_create_mock_product(
			[
				'id'       => 420,
				'type'     => 'variable',
				'children' => [ 421, 422 ],
			]
		);
		$this->give_plans( 420, $plans );
		foreach ( [ 421, 422 ] as $vid ) {
			wc_create_mock_product(
				[
					'id'        => $vid,
					'type'      => 'variation',
					'parent_id' => 420,
					'price'     => 5,
				]
			);
			$this->give_plans( $vid, $plans );
		}

		ob_start();
		\Newspack\Subscriptions_Tiers::render_form( $parent );
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'name="convert_to_sub_420"', $markup );
		$this->assertStringContainsString( 'value="mkey"', $markup );
		$this->assertStringContainsString( 'value="ykey"', $markup );
		// No hidden fallback when the radio control itself posts the plan.
		$this->assertStringNotContainsString( 'type="hidden" name="convert_to_sub_420"', $markup );

		preg_match_all( '/<input[^>]*name="convert_to_sub_420"[^>]*>/s', $markup, $inputs );
		$checked_inputs = array_filter(
			$inputs[0],
			function ( $input ) {
				return false !== strpos( $input, 'checked' );
			}
		);
		$this->assertCount( 1, $checked_inputs, 'Exactly one plan radio should be checked.' );
		$this->assertStringContainsString( 'value="mkey"', reset( $checked_inputs ), 'The first (initially-visible) frequency should be the checked one.' );
	}

	/**
	 * NPPM-3053 mis-selling regression: when every plan's bucket holds exactly
	 * one tier - a `simple` product with a monthly and an annual plan - the
	 * form used to take the flat, no-tabs card layout, printing one card per
	 * plan at that plan's own price while a single hidden input pinned all of
	 * them to the *first* plan. The reader picked "Yearly" and was billed
	 * monthly, and since every card carries the same product_id nothing
	 * downstream could tell them apart either. More than one plan on offer
	 * must always render the control that posts which plan was chosen.
	 */
	public function test_render_form_posts_the_plan_choice_with_one_tier_per_plan() {
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		// A simple product is its own single tier, so each plan's bucket holds
		// exactly one product and is_single_tier() is true.
		$product = wc_create_mock_product(
			[
				'id'    => 460,
				'type'  => 'simple',
				'price' => 5,
			]
		);
		$this->give_plans( 460, $plans );

		ob_start();
		\Newspack\Subscriptions_Tiers::render_form( $product );
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'name="convert_to_sub_460"', $markup, 'The plan-posting control must render.' );

		// The posted key varies with the reader's selection: one radio per
		// plan, each carrying its own scheme key, in a single named group.
		preg_match_all( '/<input[^>]*name="convert_to_sub_460"[^>]*>/s', $markup, $inputs );
		$this->assertCount( 2, $inputs[0], 'One plan-posting input per plan.' );
		foreach ( $inputs[0] as $input ) {
			$this->assertStringContainsString( 'type="radio"', $input, 'The plan must be posted by a control the reader can change.' );
		}
		$this->assertStringContainsString( 'value="mkey"', $inputs[0][0] );
		$this->assertStringContainsString( 'value="ykey"', $inputs[0][1] );

		// No hidden input pinning every card to one plan - that was the bug.
		$this->assertStringNotContainsString( 'type="hidden" name="convert_to_sub_460"', $markup );

		$checked_inputs = array_filter(
			$inputs[0],
			function ( $input ) {
				return false !== strpos( $input, 'checked' );
			}
		);
		$this->assertCount( 1, $checked_inputs, 'Exactly one plan radio should be checked.' );
		$this->assertStringContainsString( 'value="mkey"', reset( $checked_inputs ), 'The first (initially-visible) plan should be the checked one.' );
	}

	/**
	 * The same mis-selling case reached through a `variable` product with a
	 * single variation: one variation across two plans is still one tier per
	 * bucket, so it took the same flat layout with the same pinned plan.
	 */
	public function test_render_form_posts_the_plan_choice_for_a_single_variation() {
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		$parent = wc_create_mock_product(
			[
				'id'       => 470,
				'type'     => 'variable',
				'children' => [ 471 ],
			]
		);
		$this->give_plans( 470, $plans );
		wc_create_mock_product(
			[
				'id'        => 471,
				'type'      => 'variation',
				'parent_id' => 470,
				'price'     => 5,
			]
		);
		$this->give_plans( 471, $plans );

		ob_start();
		\Newspack\Subscriptions_Tiers::render_form( $parent );
		$markup = ob_get_clean();

		preg_match_all( '/<input[^>]*name="convert_to_sub_470"[^>]*>/s', $markup, $inputs );
		$this->assertCount( 2, $inputs[0], 'One plan-posting radio per plan.' );
		$this->assertStringContainsString( 'value="mkey"', $inputs[0][0] );
		$this->assertStringContainsString( 'value="ykey"', $inputs[0][1] );
		$this->assertStringNotContainsString( 'type="hidden" name="convert_to_sub_470"', $markup );
	}

	/**
	 * Switch-flow fallback: when the subscriber's current subscription
	 * matches none of the tiers (a retired plan, or filtered out by the
	 * ownership check), get_current_tier() returns a null frequency. Without
	 * a fallback, zero radios get checked and the switch form posts no plan
	 * - the same one-time-charge bug, in the switch flow.
	 */
	public function test_render_form_checks_a_radio_when_switching_with_no_matching_tier() {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
			'ykey' => [
				'period'   => 'year',
				'interval' => 1,
			],
		];

		// Two variations per plan - see the comment in the multi-plan wiring
		// test above for why this keeps render_form() on the tabbed-panel
		// path this test targets.
		$parent = wc_create_mock_product(
			[
				'id'       => 430,
				'type'     => 'variable',
				'children' => [ 431, 432 ],
			]
		);
		$this->give_plans( 430, $plans );
		foreach ( [ 431, 432 ] as $vid ) {
			wc_create_mock_product(
				[
					'id'        => $vid,
					'type'      => 'variation',
					'parent_id' => 430,
					'price'     => 5,
				]
			);
			$this->give_plans( $vid, $plans );
		}

		// Logged in with no subscriptions at all, so get_current_tier() can't
		// match anything - the fallback path this test targets.
		$switch_data = [
			'subscription' => new WC_Subscription( [ 'id' => 999 ] ),
			'item_id'      => 111,
		];

		ob_start();
		\Newspack\Subscriptions_Tiers::render_form( $parent, null, null, $switch_data );
		$markup = ob_get_clean();

		preg_match_all( '/<input[^>]*name="convert_to_sub_430"[^>]*>/s', $markup, $inputs );
		$checked_inputs = array_filter(
			$inputs[0],
			function ( $input ) {
				return false !== strpos( $input, 'checked' );
			}
		);
		$this->assertCount( 1, $checked_inputs, 'A radio should still be checked with no matching tier.' );
	}

	/**
	 * The switch flow prices a name-your-price card off the subscription's
	 * current product. That product can have been deleted since, in which case
	 * `wc_get_product()` returns false - which used to flow straight into the
	 * plan accessors and fatal, taking the whole modal down. Fall back to the
	 * target product's own price instead.
	 */
	public function test_nyp_card_survives_a_deleted_switch_product() {
		$product = wc_create_mock_product(
			[
				'id'    => 480,
				'type'  => 'simple',
				'price' => 10,
				'meta'  => [
					'_nyp'                          => 'yes',
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);

		ob_start();
		\Newspack\Subscriptions_Tiers::render_nyp_product_card(
			$product,
			false,
			[
				'item' => [
					'product_id' => 4809, // Never registered: a deleted product.
					'line_total' => 20,
				],
			]
		);
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'name="price"', $markup );
		$this->assertStringContainsString( 'value="10"', $markup, 'With no base product to convert from, the target price stands.' );
	}

	/**
	 * A grouped product's cart item posts its child product's ID, not the
	 * grouped parent's - so a convert_to_sub keyed on the grouped parent ID
	 * would never be read by APFS. render_form() must not emit one.
	 *
	 * The fixture deliberately mixes a legacy child with a plan-based one: the
	 * plan-based child is dropped in composition, so a grouped product with
	 * *only* plan-based children yields no tiers at all and render_form()
	 * returns before emitting a single byte - against which "the markup
	 * contains no convert_to_sub" is vacuously true and would pass against
	 * unimplemented code. The legacy child keeps the form rendering, so the
	 * assertion has something to bite on.
	 */
	public function test_render_form_skips_plan_posting_for_a_grouped_product() {
		$parent = wc_create_mock_product(
			[
				'id'       => 440,
				'type'     => 'grouped',
				'children' => [ 441, 442 ],
			]
		);
		wc_create_mock_product(
			[
				'id'    => 441,
				'type'  => 'subscription',
				'price' => 5,
				'meta'  => [
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);
		$plans = [
			'mkey' => [
				'period'   => 'month',
				'interval' => 1,
			],
		];
		wc_create_mock_product(
			[
				'id'       => 442,
				'type'     => 'variable',
				'children' => [ 443 ],
			]
		);
		$this->give_plans( 442, $plans );
		wc_create_mock_product(
			[
				'id'        => 443,
				'type'      => 'variation',
				'parent_id' => 442,
				'price'     => 7,
			]
		);
		$this->give_plans( 443, $plans );

		ob_start();
		\Newspack\Subscriptions_Tiers::render_form( $parent );
		$markup = ob_get_clean();

		$this->assertStringContainsString( '<form', $markup, 'Precondition: the legacy child keeps the form rendering.' );
		$this->assertStringContainsString( 'name="product_id" value="441"', $markup, 'The legacy child is still offered.' );
		$this->assertStringNotContainsString( 'value="443"', $markup, 'The plan-based child is dropped in composition.' );
		$this->assertStringNotContainsString( 'convert_to_sub', $markup, 'Grouped products have no single parent ID to post a plan against.' );
	}

	/**
	 * With no product, render_form() aggregates every non-donation
	 * subscription product with no common parent, so it must never fall
	 * back to "convert_to_sub_0" - a key that looks real but is read by
	 * nothing.
	 */
	public function test_render_form_skips_plan_posting_with_no_product() {
		// Registered so get_tiers_by_frequency( null ) picks it up in its
		// catalog-wide aggregation, giving the form something to render. The
		// aggregation queries the legacy product types only, and a legacy
		// product never carries plans (see
		// WooCommerce_Subscriptions::has_subscription_plans()) - which is
		// precisely why this path can never have a plan key to post.
		wc_create_mock_product(
			[
				'id'    => 450,
				'type'  => 'subscription',
				'price' => 5,
				'meta'  => [
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => '1',
				],
			]
		);

		ob_start();
		\Newspack\Subscriptions_Tiers::render_form( null );
		$markup = ob_get_clean();

		$this->assertStringContainsString( '<form', $markup, 'Precondition: the form actually renders.' );
		$this->assertStringNotContainsString( 'convert_to_sub', $markup );
	}
}
