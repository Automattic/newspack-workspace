<?php
/**
 * Tests the WooCommerce Memberships → subscriber-only products migration.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\CLI\Product_Restrictions_Migration;
use Newspack\Product_Targeting;
use Newspack\Subscriber_Only_Products;

/**
 * How Memberships product restriction rules become subscriber-only product
 * restrictions.
 *
 * @group subscriber-commerce
 * @group Subscriber_Only_Products
 */
class Test_Product_Restrictions_Migration extends \WP_UnitTestCase {

	/**
	 * Load the WP-CLI mocks the command reports through.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
	}

	/**
	 * Start every test with no migrated rules and no Memberships rules.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Subscriber_Only_Products::OPTION_NAME );
		delete_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION );
		\WP_CLI::reset();
	}

	/**
	 * Plan 500 is granted by subscriptions 11 and 22, plan 600 by 33.
	 *
	 * @return callable
	 */
	private function plan_products_getter(): callable {
		return function ( $plan_id ) {
			return [
				500 => [ 11, 22 ],
				600 => [ 33 ],
			][ $plan_id ] ?? [];
		};
	}

	/**
	 * A Memberships product restriction rule, shaped as the Memberships admin
	 * saves it: an empty `active` flag, which Memberships sets for discounts only.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function memberships_rule( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                 => 'rule_1',
				'membership_plan_id' => 500,
				'rule_type'          => 'product_restriction',
				'content_type'       => 'post_type',
				'content_type_name'  => 'product',
				'object_ids'         => [ 101, 102 ],
				'access_type'        => 'purchase',
				'access_schedule'    => 'immediate',
				'active'             => '',
			],
			$overrides
		);
	}

	/**
	 * Two published plans with their granting subscriptions, and Memberships
	 * rules naming them by placeholder, as the command reads them from the site.
	 *
	 * @param array[] $rules Memberships rules, with `membership_plan_id` set to 'monthly' or 'annual'.
	 */
	private function store_memberships_rules( array $rules ): void {
		$plan_ids = [
			'monthly' => $this->factory->post->create( [ 'post_type' => 'wc_membership_plan' ] ),
			'annual'  => $this->factory->post->create( [ 'post_type' => 'wc_membership_plan' ] ),
		];
		foreach ( [ 11, 22 ] as $subscription_product_id ) {
			wc_create_mock_product(
				[
					'id'   => $subscription_product_id,
					'type' => 'subscription',
				]
			);
		}
		update_post_meta( $plan_ids['monthly'], '_product_ids', [ 11 ] );
		update_post_meta( $plan_ids['annual'], '_product_ids', [ 22 ] );

		foreach ( $rules as $index => $rule ) {
			$rules[ $index ]['membership_plan_id'] = $plan_ids[ $rule['membership_plan_id'] ];
		}
		update_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION, $rules );
	}

	/**
	 * Memberships scopes a restriction to one plan, and a plan often carries
	 * several rules. Each plan becomes one rule over all its products, sold to
	 * holders of the subscriptions that grant that plan, so the Monthly and
	 * Annual audiences stay apart.
	 */
	public function test_writes_one_rule_per_plan() {
		$mapped = Product_Restrictions_Migration::map_rules(
			[
				$this->memberships_rule(),
				$this->memberships_rule(
					[
						'id'         => 'rule_2',
						'object_ids' => [ 102, 103 ],
					]
				),
				$this->memberships_rule(
					[
						'id'                 => 'rule_3',
						'membership_plan_id' => 600,
						'object_ids'         => [ 104 ],
					]
				),
				// Not a product restriction, so not this migrator's business.
				$this->memberships_rule(
					[
						'id'        => 'rule_4',
						'rule_type' => 'purchasing_discount',
					]
				),
			],
			$this->plan_products_getter()
		);

		$this->assertCount( 2, $mapped['rules'], 'Two plans, two rules.' );
		list( $monthly, $annual ) = $mapped['rules'];
		$this->assertSame( [ 101, 102, 103 ], $monthly['product_ids'], 'The plan\'s rules merge into one product list.' );
		$this->assertSame( [ 11, 22 ], $monthly['subscription_product_ids'], 'Its audience is the subscriptions granting that plan.' );
		$this->assertSame( [ 104 ], $annual['product_ids'] );
		$this->assertSame( [ 33 ], $annual['subscription_product_ids'] );
		$this->assertTrue( $monthly['active'], 'A migrated restriction enforces straight away.' );
	}

	/**
	 * A rule naming a product category restricts the category. One naming
	 * nothing restricts every product. Within a plan these are separate rules,
	 * because one subscriber-only rule has one targeting.
	 */
	public function test_category_and_catalog_wide_rules_keep_their_targeting() {
		$mapped = Product_Restrictions_Migration::map_rules(
			[
				$this->memberships_rule(),
				$this->memberships_rule(
					[
						'id'                => 'rule_2',
						'content_type'      => 'taxonomy',
						'content_type_name' => 'product_cat',
						'object_ids'        => [ 7 ],
					]
				),
				$this->memberships_rule(
					[
						'id'                 => 'rule_3',
						'membership_plan_id' => 600,
						'object_ids'         => [],
					]
				),
			],
			$this->plan_products_getter()
		);

		$targeting = wp_list_pluck( $mapped['rules'], 'targeting' );
		$this->assertSame( [ Product_Targeting::TARGETING_PRODUCTS, Product_Targeting::TARGETING_CATEGORY, Product_Targeting::TARGETING_ALL ], $targeting );
		$this->assertSame( [ 7 ], $mapped['rules'][1]['category_ids'] );
	}

	/**
	 * Each of these would write a rule that restricts the wrong thing, so each
	 * is reported for a human instead: a plan granted only by hand has no
	 * subscription to sell to, a tag rule cannot be expressed, and Memberships
	 * ignores the rules of an unpublished plan.
	 */
	public function test_rules_needing_a_decision_are_skipped_and_reported() {
		$mapped = Product_Restrictions_Migration::map_rules(
			[
				$this->memberships_rule( [ 'membership_plan_id' => 999 ] ),
				$this->memberships_rule(
					[
						'id'                => 'rule_2',
						'content_type'      => 'taxonomy',
						'content_type_name' => 'product_tag',
						'object_ids'        => [ 8 ],
					]
				),
				$this->memberships_rule(
					[
						'id'                 => 'rule_3',
						'membership_plan_id' => 600,
					]
				),
				$this->memberships_rule(
					[
						'id'                 => 'rule_4',
						'membership_plan_id' => 0,
					]
				),
				// A tag rule on a plan with no subscription is skipped for its
				// taxonomy, so it keeps no category restriction alive.
				$this->memberships_rule(
					[
						'id'                 => 'rule_5',
						'membership_plan_id' => 999,
						'content_type'       => 'taxonomy',
						'content_type_name'  => 'product_tag',
						'object_ids'         => [ 8 ],
					]
				),
			],
			$this->plan_products_getter(),
			[],
			function ( $plan_id ) {
				return 600 !== $plan_id;
			}
		);

		$this->assertEmpty( $mapped['rules'] );
		$this->assertSame(
			[
				'rule_1' => 'Plan grants access without a subscription product — pick the subscription(s) by hand.',
				'rule_2' => 'Targets the "product_tag" taxonomy, which subscriber-only products cannot express — re-target it by product or category.',
				'rule_3' => 'Plan is not published, so Memberships does not apply this rule.',
				'rule_4' => 'Rule names no membership plan.',
				'rule_5' => 'Targets the "product_tag" taxonomy, which subscriber-only products cannot express — re-target it by product or category.',
			],
			wp_list_pluck( $mapped['skipped'], 'reason', 'source' )
		);
		$this->assertSame(
			[ Product_Restrictions_Migration::migrated_rule_id( 999, Product_Targeting::TARGETING_PRODUCTS ) ],
			$mapped['still_enforced_rule_ids'],
			'Only the rule Memberships still enforces keeps its earlier restriction.'
		);
	}

	/**
	 * Memberships never restricts a product flagged "disable restrictions".
	 * A named list drops it; a category or catalog-wide rule excludes it.
	 */
	public function test_products_memberships_left_public_stay_buyable() {
		$mapped = Product_Restrictions_Migration::map_rules(
			[
				$this->memberships_rule(),
				$this->memberships_rule(
					[
						'id'                 => 'rule_2',
						'membership_plan_id' => 600,
						'object_ids'         => [],
					]
				),
			],
			$this->plan_products_getter(),
			[ 102 ]
		);

		$this->assertSame( [ 101 ], $mapped['rules'][0]['product_ids'] );
		$this->assertSame( [ 102 ], $mapped['rules'][1]['excluded_product_ids'] );
	}

	/**
	 * Two things Memberships can do that Access Control cannot: hide a
	 * "view"-restricted product from non-members, and make members wait before
	 * they can buy. Both rules still migrate, and each gap is reported.
	 */
	public function test_hidden_and_delayed_restrictions_are_reported() {
		$mapped = Product_Restrictions_Migration::map_rules(
			[
				$this->memberships_rule( [ 'access_type' => 'view' ] ),
				$this->memberships_rule(
					[
						'id'              => 'rule_2',
						'object_ids'      => [ 103 ],
						'access_schedule' => '30 days',
					]
				),
			],
			$this->plan_products_getter()
		);

		$this->assertSame( [ 101, 102, 103 ], $mapped['rules'][0]['product_ids'], 'The purchase restriction migrates either way.' );
		$this->assertSame( [ 101, 102 ], wp_list_pluck( $mapped['hidden'], 'object_id' ), 'Only the view-restricted products are reported as hidden.' );
		$this->assertSame( [ 'rule_2' ], wp_list_pluck( $mapped['delayed'], 'source' ), 'Only the rule with a waiting period is reported as delayed.' );
	}

	/**
	 * Where one plan may buy a product and another may only see it, Memberships
	 * keeps the second plan from buying. Migrated rules each grant purchase on
	 * their own, so the product is reported.
	 */
	public function test_a_view_only_plan_gaining_purchase_rights_is_reported() {
		$mapped = Product_Restrictions_Migration::map_rules(
			[
				$this->memberships_rule(),
				$this->memberships_rule(
					[
						'id'                 => 'rule_2',
						'membership_plan_id' => 600,
						'object_ids'         => [ 102, 104 ],
						'access_type'        => 'view',
					]
				),
			],
			$this->plan_products_getter()
		);

		$this->assertSame(
			[
				[
					'product_id' => 102,
					'plans'      => [ 600 ],
				],
			],
			$mapped['view_only_buyers'],
			'Product 104 has no purchase rule, so plan 600 could buy it already.'
		);
	}

	/**
	 * A view rule over a category can't be checked product by product, so
	 * an overlap with another plan's purchase rule is named for a human.
	 */
	public function test_a_category_view_rule_beside_another_plans_purchase_rule_is_named() {
		$mapped = Product_Restrictions_Migration::map_rules(
			[
				$this->memberships_rule(),
				$this->memberships_rule(
					[
						'id'                 => 'rule_2',
						'membership_plan_id' => 600,
						'content_type'       => 'taxonomy',
						'content_type_name'  => 'product_cat',
						'object_ids'         => [ 7 ],
						'access_type'        => 'view',
					]
				),
			],
			$this->plan_products_getter()
		);

		$this->assertSame( [ 'rule_2' ], wp_list_pluck( $mapped['unchecked_view_overlaps'], 'source' ) );
	}

	/**
	 * End to end through the command: a dry run writes nothing, a live run
	 * writes one rule per plan, and running it again updates those rules
	 * rather than adding copies.
	 */
	public function test_command_is_dry_run_by_default_and_idempotent_live() {
		$this->store_memberships_rules(
			[
				$this->memberships_rule( [ 'membership_plan_id' => 'monthly' ] ),
				$this->memberships_rule(
					[
						'id'                 => 'rule_2',
						'membership_plan_id' => 'annual',
						'object_ids'         => [ 103 ],
						'access_type'        => 'view',
					]
				),
			]
		);

		$command = new Product_Restrictions_Migration();
		$command->migrate_product_restrictions( [], [] );
		$this->assertSame( [], Subscriber_Only_Products::get_rules(), 'A dry run writes nothing.' );
		$hidden_warnings = array_filter(
			\WP_CLI::$warnings,
			function ( $warning ) {
				return false !== strpos( $warning, 'Product 103' );
			}
		);
		$this->assertCount( 1, $hidden_warnings, 'The hidden product gets its own warning.' );

		$command->migrate_product_restrictions( [], [ 'live' => true ] );
		$command->migrate_product_restrictions( [], [ 'live' => true ] );

		$rules = Subscriber_Only_Products::get_rules();
		$this->assertCount( 2, $rules, 'Two live runs leave one rule per plan.' );
		$this->assertEqualsCanonicalizing( [ [ 11 ], [ 22 ] ], wp_list_pluck( $rules, 'subscription_product_ids' ) );
	}

	/**
	 * A re-run follows Memberships: a restriction whose source rule is gone
	 * stops blocking purchases. A pause the publisher set in Access Control
	 * survives, since Memberships has no pause of its own to restore.
	 */
	public function test_rerun_removes_orphaned_rules_and_keeps_a_publisher_pause() {
		$monthly_rule = $this->memberships_rule( [ 'membership_plan_id' => 'monthly' ] );
		$annual_rule  = $this->memberships_rule(
			[
				'id'                 => 'rule_2',
				'membership_plan_id' => 'annual',
				'object_ids'         => [ 103 ],
			]
		);
		$this->store_memberships_rules( [ $monthly_rule, $annual_rule ] );
		$command = new Product_Restrictions_Migration();
		$command->migrate_product_restrictions( [], [ 'live' => true ] );
		$publisher_rule = Subscriber_Only_Products::save_rule(
			[
				'subscription_product_ids' => [ 11 ],
				'product_ids'              => [ 999 ],
				'active'                   => true,
			]
		);

		$stored_rules = get_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION );
		$monthly_id   = Product_Restrictions_Migration::migrated_rule_id( $stored_rules[0]['membership_plan_id'], Product_Targeting::TARGETING_PRODUCTS );
		Subscriber_Only_Products::save_rule( array_merge( Subscriber_Only_Products::get_rule( $monthly_id ), [ 'active' => false ] ) );
		update_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION, [ $stored_rules[0] ] );
		$command->migrate_product_restrictions( [], [ 'live' => true ] );

		$rules = Subscriber_Only_Products::get_rules();
		$this->assertEqualsCanonicalizing( [ $monthly_id, $publisher_rule['id'] ], wp_list_pluck( $rules, 'id' ), 'The annual rule, whose source is gone, is removed; the publisher\'s own rule is not touched.' );
		$this->assertFalse( Subscriber_Only_Products::get_rule( $monthly_id )['active'], 'The monthly rule stays paused.' );
	}

	/**
	 * A plan that loses its granting product keeps only the restriction for
	 * the rule Memberships still enforces. Its other restriction, whose
	 * source rule is gone, is removed.
	 */
	public function test_only_the_still_enforced_rules_restriction_is_kept() {
		$product_rule  = $this->memberships_rule( [ 'membership_plan_id' => 'monthly' ] );
		$category_rule = $this->memberships_rule(
			[
				'id'                 => 'rule_2',
				'membership_plan_id' => 'monthly',
				'content_type'       => 'taxonomy',
				'content_type_name'  => 'product_cat',
				'object_ids'         => [ 7 ],
			]
		);
		$this->store_memberships_rules( [ $product_rule, $category_rule ] );
		$command = new Product_Restrictions_Migration();
		$command->migrate_product_restrictions( [], [ 'live' => true ] );

		$stored_rules = get_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION );
		$plan_id      = $stored_rules[1]['membership_plan_id'];
		update_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION, [ $stored_rules[1] ] );
		delete_post_meta( $plan_id, '_product_ids' );
		$command->migrate_product_restrictions( [], [ 'live' => true ] );

		$this->assertSame(
			[ Product_Restrictions_Migration::migrated_rule_id( $plan_id, Product_Targeting::TARGETING_CATEGORY ) ],
			wp_list_pluck( Subscriber_Only_Products::get_rules(), 'id' )
		);
	}

	/**
	 * Eligibility looks for an active subscription, so a one-time product that
	 * grants a plan can never satisfy a restriction. It is left out of the
	 * audience and reported; a plan granted only that way needs a decision.
	 */
	public function test_one_time_granting_products_are_left_out_of_the_audience() {
		$this->store_memberships_rules(
			[
				$this->memberships_rule( [ 'membership_plan_id' => 'monthly' ] ),
				$this->memberships_rule(
					[
						'id'                 => 'rule_2',
						'membership_plan_id' => 'annual',
						'object_ids'         => [ 103 ],
					]
				),
			]
		);
		wc_create_mock_product(
			[
				'id'   => 44,
				'type' => 'simple',
			]
		);
		$stored_rules = get_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION );
		update_post_meta( $stored_rules[0]['membership_plan_id'], '_product_ids', [ 11, 44 ] );
		update_post_meta( $stored_rules[1]['membership_plan_id'], '_product_ids', [ 44 ] );

		( new Product_Restrictions_Migration() )->migrate_product_restrictions( [], [ 'live' => true ] );

		$rules = Subscriber_Only_Products::get_rules();
		$this->assertSame( [ [ 11 ] ], wp_list_pluck( $rules, 'subscription_product_ids' ), 'Only the subscription joins the audience; the plan granted by a one-time product alone is skipped.' );
		$one_time_warnings = array_filter(
			\WP_CLI::$warnings,
			function ( $warning ) {
				return false !== strpos( $warning, 'product(s) 44' );
			}
		);
		$this->assertCount( 2, $one_time_warnings, 'Each plan granted by the one-time product is reported.' );
	}

	/**
	 * Missing or unreadable Memberships rules stop the command. Read as an
	 * empty rule set, they would mark every migrated restriction stale and
	 * delete it.
	 */
	public function test_unreadable_memberships_rules_change_nothing() {
		$this->store_memberships_rules( [ $this->memberships_rule( [ 'membership_plan_id' => 'monthly' ] ) ] );
		$command = new Product_Restrictions_Migration();
		$command->migrate_product_restrictions( [], [ 'live' => true ] );
		$migrated_rules = Subscriber_Only_Products::get_rules();
		$this->assertNotEmpty( $migrated_rules );

		$break_source_data = [
			'unreadable' => function () {
				update_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION, 'not-an-array' );
			},
			'missing'    => function () {
				delete_option( Product_Restrictions_Migration::MEMBERSHIPS_RULES_OPTION );
			},
		];
		foreach ( $break_source_data as $case => $break ) {
			$break();
			try {
				$command->migrate_product_restrictions( [], [ 'live' => true ] );
				$this->fail( "The command should stop on $case rules." );
			} catch ( \WP_CLI_Mock_Exception $exception ) {
				$this->assertSame( $migrated_rules, Subscriber_Only_Products::get_rules(), "Nothing changes on $case rules." );
			}
		}
	}
}
