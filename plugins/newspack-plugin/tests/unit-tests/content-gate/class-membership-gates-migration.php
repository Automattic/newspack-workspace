<?php
/**
 * Characterization tests for the migrate-membership-gates CLI (NPPD-2059).
 *
 * These pin the behavior of the pure mapping/fingerprint/layout-extraction
 * helpers exactly as ported from the standalone drop-in. Where a test asserts a
 * buggy result on purpose it is flagged with the follow-up issue ID; those
 * stacked fixes will flip the corresponding assertion:
 *
 * - NPPD-2058: extract_gate_layouts() only inspects top-level wrapper blocks, so
 *   nested / reusable-block gate layouts migrate as empty. Pinned by the
 *   extract_gate_layouts / serialize_gate_inner_blocks tests below (they flip red).
 * - NPPD-2063: map_rules_to_ac_format() emits the raw WooCommerce content-type
 *   name as the AC rule slug instead of remapping to 'post_types' / 'specific_posts'.
 *   Pinned by the map_rules_to_ac_format tests below (they flip red).
 *
 * NOT pinned here: NPPD-2064 (fingerprint-based gate splitting/grouping). That fix
 * lands in group_plans_by_fingerprint() and the merged-product consolidation, which
 * depend on WC_Memberships_Membership_Plan and so are not unit-testable in this
 * harness — they are exercised end-to-end against real WooCommerce Memberships. The
 * compute_rules_fingerprint() tests below only pin the fingerprint's *canonicality*
 * (order-independence), which the 2064 fix preserves; they will NOT flip red, so the
 * 2064 author must add net-new grouping/split tests rather than rely on these.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\CLI\Membership_Gates_Migration;
use Newspack\Newsletters\Subscription_Lists;

// The trait has to be defined before the class that uses it. Production load order
// comes from CLI\Initializer; a test requiring the class directly supplies it here.
require_once dirname( __DIR__, 3 ) . '/includes/cli/trait-one-time-purchase-migration.php';
require_once dirname( __DIR__, 3 ) . '/includes/cli/class-membership-gates-migration.php';

/**
 * Characterization tests for the migrate-membership-gates helpers.
 */
class Test_Membership_Gates_Migration extends \WP_UnitTestCase {

	/**
	 * Load the newsletters mocks once for the class. Deferred to set_up_before_class()
	 * rather than a file-scope require because PHPUnit loads every test file before the
	 * run starts: a file-scope require would define Subscription_List and
	 * Subscription_Lists for the whole suite, and three production guards branch on
	 * class_exists() for those, so unrelated tests would silently take the
	 * "Newsletters active" path.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-namespaced-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * The argument vector PHPUnit was invoked with, restored after each test.
	 *
	 * @var array|null
	 */
	private $original_argv;

	/**
	 * The mock product database as it stood before this test, restored afterwards.
	 *
	 * The mock builder writes into a global keyed by product ID, and the IDs here come
	 * from the post factory — so without this a fixture could land on an ID another
	 * test class hardcodes, and outlive the test that registered it.
	 *
	 * @var array|null
	 */
	private $original_products_database;

	/**
	 * Remember the argument vector the bare-flag tests overwrite, and the mock product
	 * database the product fixtures write into.
	 */
	public function set_up() {
		parent::set_up();
		global $products_database;
		$this->original_products_database = $products_database;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw argv, kept verbatim so tear_down() can restore it.
		$this->original_argv = $_SERVER['argv'] ?? null;
	}

	/**
	 * Put the argument vector and the mock product database back so neither can leak
	 * into another test class.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = $this->original_products_database;
		if ( null === $this->original_argv ) {
			unset( $_SERVER['argv'] );
		} else {
			$_SERVER['argv'] = $this->original_argv;
		}
		parent::tear_down();
	}

	/**
	 * Invoke a private static method on the CLI class via reflection.
	 *
	 * @param string $method_name The method name.
	 * @param array  $arguments   Positional arguments.
	 *
	 * @return mixed The method return value.
	 */
	private function invoke_private_static( string $method_name, array $arguments ) {
		$reflected_method = new \ReflectionMethod( Membership_Gates_Migration::class, $method_name );
		$reflected_method->setAccessible( true );
		return $reflected_method->invoke( null, ...$arguments );
	}

	/**
	 * Build a minimal stand-in for a WC_Memberships_Membership_Plan_Rule.
	 *
	 * The drop-in's rule mapping only calls get_content_type_name() and
	 * get_object_ids(), so the WC Memberships plugin is not needed to exercise it.
	 *
	 * @param string $content_type_name The WC content type name (e.g. 'post', 'category').
	 * @param int[]  $object_ids        The restricted object IDs.
	 *
	 * @return object A rule-shaped object.
	 */
	private function make_rule( string $content_type_name, array $object_ids ) {
		return new class( $content_type_name, $object_ids ) {

			/**
			 * The WC content type name.
			 *
			 * @var string
			 */
			private $content_type_name;

			/**
			 * The restricted object IDs.
			 *
			 * @var int[]
			 */
			private $object_ids;

			/**
			 * Constructor.
			 *
			 * @param string $content_type_name The WC content type name.
			 * @param int[]  $object_ids        The restricted object IDs.
			 */
			public function __construct( string $content_type_name, array $object_ids ) {
				$this->content_type_name = $content_type_name;
				$this->object_ids        = $object_ids;
			}

			/**
			 * Return the WC content type name.
			 *
			 * @return string
			 */
			public function get_content_type_name() {
				return $this->content_type_name;
			}

			/**
			 * Return the restricted object IDs.
			 *
			 * @return int[]
			 */
			public function get_object_ids() {
				return $this->object_ids;
			}
		};
	}

	/**
	 * Build a plan-group descriptor as group_plans_by_fingerprint() would, carrying
	 * just the access method group_requires_purchase() inspects.
	 *
	 * @param string $access_method The WCM plan access method ('purchase' or 'signup').
	 *
	 * @return array
	 */
	private function make_group_plan( string $access_method ): array {
		return [
			'pid'           => 0,
			'name'          => 'Plan',
			'access_method' => $access_method,
			'ac_rules'      => [],
			'product_ids'   => [],
		];
	}

	/**
	 * A garbage `_product_ids` entry normalizes to 0, and a rule value of 0 grants the
	 * gate to every paying reader — WC_Subscription::has_product() matches a line item
	 * whose variation_id is 0, which every simple-product line item's is. A negative ID
	 * is dropped for the same reason: absint() would have turned it into a different,
	 * real product ID. A deleted product writes a rule nothing can satisfy, which fails
	 * safe but over-restricts, so it is dropped and reported too. Variations keep this
	 * command's inherited behavior and stay dropped.
	 */
	public function test_resolve_product_ids_drops_ids_a_subscription_rule_must_not_carry() {
		$product   = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$variation = self::factory()->post->create( [ 'post_type' => 'product_variation' ] );
		$deleted   = self::factory()->post->create( [ 'post_type' => 'product' ] );
		wp_delete_post( $deleted, true );

		$group = [
			[
				'pid'           => 0,
				'name'          => 'Plan',
				'access_method' => 'purchase',
				'ac_rules'      => [],
				'product_ids'   => [ $product, 0, -7, $deleted, $variation ],
			],
		];

		$resolved = $this->invoke_private_static( 'resolve_product_ids', [ $group ] );

		$this->assertSame( [ $product ], $resolved['product_ids'] );
		$this->assertSame( [ 0, -7 ], $resolved['dropped']['invalid'] );
		$this->assertSame( [ $deleted ], $resolved['dropped']['unresolvable'] );
		$this->assertSame( [ $variation ], $resolved['dropped']['variations'] );
	}

	/**
	 * Register a WooCommerce mock product for a post the factory already made.
	 *
	 * The migration asks wc_get_product() which rule can carry a product, and a post
	 * the mock database has never heard of comes back as false — which classifies as
	 * one-time. Registering the mock is what lets a fixture say which kind it is.
	 *
	 * @param int    $product_id The product post ID.
	 * @param string $type       The WooCommerce product type.
	 *
	 * @return int The product post ID, for chaining.
	 */
	private function register_product_type( int $product_id, string $type ): int {
		\wc_create_mock_product(
			[
				'id'   => $product_id,
				'type' => $type,
			]
		);
		return $product_id;
	}

	/**
	 * Create a product post of a given WooCommerce type.
	 *
	 * @param string $type The WooCommerce product type.
	 *
	 * @return int The product post ID.
	 */
	private function create_product( string $type ): int {
		return $this->register_product_type( self::factory()->post->create( [ 'post_type' => 'product' ] ), $type );
	}

	/**
	 * A duration pair in the shape the one_time_purchase rule stores.
	 *
	 * @param int    $value The duration amount.
	 * @param string $unit  The duration unit.
	 *
	 * @return array
	 */
	private function duration( int $value, string $unit ): array {
		return [
			'duration_value' => $value,
			'duration_unit'  => $unit,
		];
	}

	/**
	 * Build a plan-group descriptor of the shape group_plans_by_fingerprint() produces.
	 *
	 * @param string     $access_method     The WCM plan access method.
	 * @param int[]      $product_ids       The plan's product IDs.
	 * @param string     $name              The plan name.
	 * @param array|null $one_time_duration The plan's own access length, as
	 *                                      derive_one_time_duration() reads it. Null
	 *                                      stands for a plan whose access ends on a
	 *                                      fixed calendar date.
	 *
	 * @return array
	 */
	private function make_product_plan( string $access_method, array $product_ids, string $name = 'Plan', ?array $one_time_duration = null ): array {
		return [
			'pid'               => 0,
			'name'              => $name,
			'access_method'     => $access_method,
			'ac_rules'          => [],
			'product_ids'       => $product_ids,
			'one_time_duration' => $one_time_duration,
		];
	}

	/**
	 * A mixed group is registration-gated and writes no paid access rules, so a
	 * purchase plan inside it has no one-time rule to give a duration to. Consulting
	 * it anyway would stop the run over a rule that was never going to be written.
	 */
	public function test_resolve_group_duration_asks_nothing_of_a_group_that_writes_no_rules() {
		$one_time = $this->create_product( 'simple' );
		$group    = [
			$this->make_product_plan( 'purchase', [ $one_time ], 'Paid', null ),
			$this->make_product_plan( 'signup', [], 'Free' ),
		];

		$result = $this->invoke_private_static( 'resolve_group_duration', [ $group, null ] );

		$this->assertSame( [], $result['plans'] );
		$this->assertNull( $result['duration'] );
	}

	/**
	 * Run a group through the three steps the write loop takes to reach its paid
	 * access rules, so a test exercises the chain rather than one link of it.
	 *
	 * @param array[]    $group    Plan descriptors.
	 * @param array|null $override An operator-supplied --one-time-duration value.
	 *
	 * @return array[] The access rule groups the gate would store.
	 */
	private function build_group_access_rules( array $group, ?array $override = null ): array {
		$products = $this->invoke_private_static( 'resolve_product_ids', [ $group ] );
		$duration = $this->invoke_private_static( 'resolve_group_duration', [ $group, $override ] );
		return $this->invoke_private_static( 'build_access_rules', [ $products, $duration['duration'] ] );
	}

	/**
	 * A plan granting on subscription products only needs the subscription rule and
	 * nothing else. A second rule group would be OR'd in, so an unwanted one-time
	 * group here would hand the content to anyone who ever bought the product once.
	 */
	public function test_build_access_rules_writes_only_a_subscription_group_for_subscription_products() {
		$subscription = $this->create_product( 'subscription' );
		$variable     = $this->create_product( 'variable-subscription' );

		$access_rules = $this->build_group_access_rules(
			[ $this->make_product_plan( 'purchase', [ $subscription, $variable ], 'Members' ) ]
		);

		$this->assertSame(
			[
				[
					[
						'slug'  => 'subscription',
						'value' => [ $subscription, $variable ],
					],
				],
			],
			$access_rules
		);
	}

	/**
	 * A plan granting on a product bought once must migrate to the one-time rule,
	 * carrying the plan's own access length. The subscription rule is the condition
	 * such a buyer can never satisfy — it is what this whole split exists to stop
	 * being written for them.
	 */
	public function test_build_access_rules_writes_only_a_one_time_group_for_one_time_products() {
		$one_time = $this->create_product( 'simple' );

		$access_rules = $this->build_group_access_rules(
			[ $this->make_product_plan( 'purchase', [ $one_time ], 'Prepaid', $this->duration( 12, 'months' ) ) ]
		);

		$this->assertSame(
			[
				[
					[
						'slug'  => 'one_time_purchase',
						'value' => [
							'product_ids'    => [ $one_time ],
							'duration_value' => 12,
							'duration_unit'  => 'months',
						],
					],
				],
			],
			$access_rules
		);
	}

	/**
	 * The case the split exists for. A plan grants membership on any of its products,
	 * so a plan holding both kinds must produce two rule groups: access rule groups
	 * are OR'd while the rules inside one are AND'd, so flattening them into a single
	 * group would demand a subscription AND a one-time purchase, and admit nobody.
	 */
	public function test_build_access_rules_writes_two_rule_groups_when_a_plan_grants_on_both_kinds() {
		$subscription = $this->create_product( 'subscription' );
		$one_time     = $this->create_product( 'simple' );

		$access_rules = $this->build_group_access_rules(
			[ $this->make_product_plan( 'purchase', [ $subscription, $one_time ], 'Premium', $this->duration( 90, 'days' ) ) ]
		);

		$this->assertCount( 2, $access_rules );
		$this->assertCount( 1, $access_rules[0], 'Rules within a group are AND-ed, so each kind gets a group of its own.' );
		$this->assertCount( 1, $access_rules[1], 'Rules within a group are AND-ed, so each kind gets a group of its own.' );
		$this->assertSame(
			[
				'slug'  => 'subscription',
				'value' => [ $subscription ],
			],
			$access_rules[0][0]
		);
		$this->assertSame(
			[
				'slug'  => 'one_time_purchase',
				'value' => [
					'product_ids'    => [ $one_time ],
					'duration_value' => 90,
					'duration_unit'  => 'days',
				],
			],
			$access_rules[1][0]
		);
	}

	/**
	 * With no duration there is nothing for the one-time rule to say, and a rule
	 * missing its duration is not a stricter rule but an unreadable one. No group is
	 * written, and the plan is named — which is what the command refuses the run over
	 * before anything is written, rather than publishing a gate any registered reader
	 * would pass.
	 */
	public function test_build_access_rules_writes_no_one_time_group_without_a_duration() {
		$one_time = $this->create_product( 'simple' );
		$group    = [ $this->make_product_plan( 'purchase', [ $one_time ], 'Ends on a date' ) ];

		$duration = $this->invoke_private_static( 'resolve_group_duration', [ $group, null ] );

		$this->assertSame( [], $this->build_group_access_rules( $group ) );
		$this->assertNull( $duration['duration'] );
		$this->assertSame( [ 'Ends on a date' ], $duration['plans'], 'The plan is named so the pre-flight refusal can say which one needs --one-time-duration.' );
	}

	/**
	 * --one-time-duration exists for the plan whose access ends on a calendar date,
	 * which has no duration to read. The operator's value must reach the rule, or the
	 * flag is a no-op that only silences the error.
	 */
	public function test_build_access_rules_lets_an_override_supply_a_missing_duration() {
		$one_time = $this->create_product( 'simple' );

		$access_rules = $this->build_group_access_rules(
			[ $this->make_product_plan( 'purchase', [ $one_time ], 'Ends on a date' ) ],
			$this->duration( 0, 'forever' )
		);

		$this->assertSame(
			[
				'product_ids'    => [ $one_time ],
				'duration_value' => 0,
				'duration_unit'  => 'forever',
			],
			$access_rules[0][0]['value']
		);
	}

	/**
	 * The split is read off the product, not off the plan: a plan grants on its
	 * products without recording which kind each one is, so resolve_product_ids() has
	 * to partition them or the rule builder has nothing to go on.
	 */
	public function test_resolve_product_ids_splits_survivors_by_the_rule_that_can_carry_them() {
		$subscription = $this->create_product( 'subscription' );
		$one_time     = $this->create_product( 'simple' );

		$resolved = $this->invoke_private_static(
			'resolve_product_ids',
			[ [ $this->make_product_plan( 'purchase', [ $subscription, $one_time ] ) ] ]
		);

		$this->assertSame( [ $subscription, $one_time ], $resolved['product_ids'] );
		$this->assertSame( [ $subscription ], $resolved['subscription_ids'] );
		$this->assertSame( [ $one_time ], $resolved['one_time_ids'] );
	}

	/**
	 * A group is purchase-gated only when EVERY plan requires a purchase — the two
	 * gate modes AND for a logged-in reader, so a mixed group would demand the
	 * subscription from members the signup plan granted for free. A group holding one
	 * signup plan and one purchase plan is therefore registration-gated, not
	 * purchase-gated.
	 */
	public function test_group_requires_purchase_only_when_every_plan_is_purchase() {
		$all_purchase = [ $this->make_group_plan( 'purchase' ), $this->make_group_plan( 'purchase' ) ];
		$mixed        = [ $this->make_group_plan( 'signup' ), $this->make_group_plan( 'purchase' ) ];
		$all_signup   = [ $this->make_group_plan( 'signup' ) ];

		$this->assertTrue(
			$this->invoke_private_static( 'group_requires_purchase', [ $all_purchase ] ),
			'A group where every plan requires a purchase is purchase-gated.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'group_requires_purchase', [ $mixed ] ),
			'A mixed signup+purchase group is registration-gated — the signup plan grants the more permissive access.'
		);
		$this->assertFalse(
			$this->invoke_private_static( 'group_requires_purchase', [ $all_signup ] ),
			'A signup-only group is registration-gated.'
		);
	}

	/**
	 * NPPD-2063: the AC rule slug is the raw WooCommerce content-type name, not the
	 * AC content-rules key ('post_types' for post types, 'specific_posts' for
	 * individual posts). Object IDs are stringified. The stacked NPPD-2063 fix will
	 * change the expected slug here.
	 */
	public function test_map_rules_to_ac_format_uses_raw_wc_content_type_name_as_slug() {
		$post_rule = $this->make_rule( 'post', [ 12, 34 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $post_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post',
					'value' => [ '12', '34' ],
				],
			],
			$mapped_rules,
			'Slug should be the verbatim WC content-type name and values stringified (NPPD-2063 seam).'
		);
	}

	/**
	 * Two rules with the same content type are merged into one AC rule with a
	 * de-duplicated, stringified value list. (The 'category' slug assertion is also
	 * touched by NPPD-2063, which will remap the slug — expect this to flip red too.)
	 */
	public function test_map_rules_to_ac_format_merges_and_dedupes_object_ids_for_the_same_slug() {
		$first_category_rule  = $this->make_rule( 'category', [ 1, 2 ] );
		$second_category_rule = $this->make_rule( 'category', [ 2, 3 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $first_category_rule, $second_category_rule ] ]
		);

		$this->assertCount( 1, $mapped_rules, 'Same-slug rules collapse into a single AC rule.' );
		$this->assertSame( 'category', $mapped_rules[0]['slug'] );
		$this->assertSame( [ '1', '2', '3' ], $mapped_rules[0]['value'], 'Object IDs are merged, de-duplicated, and stringified.' );
	}

	/**
	 * Rules with an empty content-type name are dropped entirely.
	 */
	public function test_map_rules_to_ac_format_skips_rules_with_empty_content_type() {
		$empty_rule = $this->make_rule( '', [ 7 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $empty_rule ] ] );

		$this->assertSame( [], $mapped_rules );
	}

	/**
	 * NPPD-2064 grouping key: the fingerprint is canonical, so rule sets that are
	 * equivalent up to rule order and object-ID order collapse to the same string
	 * (and therefore into a single gate).
	 */
	public function test_compute_rules_fingerprint_is_independent_of_rule_and_value_order() {
		$rules_in_one_order = [
			[
				'slug'  => 'category',
				'value' => [ '2', '1' ],
			],
			[
				'slug'  => 'post',
				'value' => [ '5' ],
			],
		];
		$rules_in_other_order = [
			[
				'slug'  => 'post',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'category',
				'value' => [ '1', '2' ],
			],
		];

		$fingerprint_one   = $this->invoke_private_static( 'compute_rules_fingerprint', [ $rules_in_one_order ] );
		$fingerprint_other = $this->invoke_private_static( 'compute_rules_fingerprint', [ $rules_in_other_order ] );

		$this->assertSame( $fingerprint_one, $fingerprint_other, 'Equivalent rule sets must share a fingerprint so they merge into one gate (NPPD-2064 seam).' );
	}

	/**
	 * Differing content rules produce different fingerprints, so the plans land in
	 * separate gates.
	 */
	public function test_compute_rules_fingerprint_differs_for_different_rules() {
		$category_rules = [
			[
				'slug'  => 'category',
				'value' => [ '1' ],
			],
		];
		$post_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
		];

		$this->assertNotSame(
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $category_rules ] ),
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $post_rules ] )
		);
	}

	/**
	 * Happy path: top-level non-member-content maps to the registration layout and
	 * top-level member-content maps to the paid-access (custom_access) layout, with
	 * the WooCommerce wrapper blocks themselves stripped from the output.
	 */
	public function test_extract_gate_layouts_reads_top_level_wrapper_blocks() {
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Become a member to read this.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Thanks for being a member.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Become a member to read this.', $layouts['registration'] );
		$this->assertStringContainsString( 'Thanks for being a member.', $layouts['custom_access'] );
		$this->assertStringNotContainsString( 'woocommerce-memberships/non-member-content', $layouts['registration'], 'The wrapper block markup is not carried into the layout.' );
	}

	/**
	 * A gate post may interleave several top-level wrappers of the same type (a post
	 * mixing public and members-only sections). Every wrapper's content is kept, in
	 * document order, for both wrapper types — no wrapper silently wins over another.
	 */
	public function test_extract_gate_layouts_concatenates_repeated_top_level_wrappers() {
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>First upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>First members-only section.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Second upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Second members-only section.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'First upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'Second upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'First members-only section.', $layouts['custom_access'] );
		$this->assertStringContainsString( 'Second members-only section.', $layouts['custom_access'] );
		$this->assertLessThan(
			strpos( $layouts['registration'], 'Second upsell.' ),
			strpos( $layouts['registration'], 'First upsell.' ),
			'Wrappers are concatenated in document order.'
		);
	}

	/**
	 * NPPD-2058: only top-level wrapper blocks are inspected, so a gate whose
	 * non-member-content wrapper is nested inside another block (here a group)
	 * migrates as an EMPTY registration layout. The stacked NPPD-2058 fix walks
	 * nested/reusable blocks and will make these assertions non-empty.
	 */
	public function test_extract_gate_layouts_returns_empty_for_nested_wrapper_blocks() {
		$gate_content = <<<'HTML'
<!-- wp:group --><div class="wp-block-group">
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Nested upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
</div><!-- /wp:group -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertSame( '', $layouts['registration'], 'A nested non-member-content wrapper yields an empty registration layout (NPPD-2058 bug).' );
		$this->assertNull( $layouts['custom_access'], 'No top-level member-content wrapper means a null custom-access layout.' );
	}

	/**
	 * The inner-block serializer drops WooCommerce Memberships wrapper blocks while
	 * keeping ordinary content blocks.
	 */
	public function test_serialize_gate_inner_blocks_strips_membership_wrapper_blocks() {
		$inner_blocks = parse_blocks(
			'<!-- wp:paragraph --><p>Keep me.</p><!-- /wp:paragraph -->'
			. '<!-- wp:woocommerce-memberships/member-content --><!-- wp:paragraph --><p>Drop me.</p><!-- /wp:paragraph --><!-- /wp:woocommerce-memberships/member-content -->'
		);

		$serialized = $this->invoke_private_static( 'serialize_gate_inner_blocks', [ $inner_blocks ] );

		$this->assertStringContainsString( 'Keep me.', $serialized );
		$this->assertStringNotContainsString( 'woocommerce-memberships/member-content', $serialized );
		$this->assertStringNotContainsString( 'Drop me.', $serialized );
	}

	/**
	 * A gate whose every content rule carries a slug the evaluator cannot resolve is
	 * reported as unenforceable.
	 *
	 * This is the NPPD-2063 slug mistranslation seen from the operator's side: the
	 * migration writes rules with the raw WooCommerce content-type name ('post'), and
	 * Content_Restriction_Control::rule_matches_post() falls through to
	 * get_taxonomy( 'post' ) — which is null — so the gate matches no post at all.
	 */
	public function test_verify_migrated_gate_flags_content_rules_the_evaluator_cannot_resolve() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post',
					'value' => [ '1' ],
				],
			] 
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its content rules resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0] );
	}

	/**
	 * A gate whose rules are only partly resolvable under-gates rather than failing
	 * outright: the rules combine with 'any', so the content behind the dead slugs is
	 * left readable while the rest is gated. That partial leak is reported too — a
	 * plan restricting all posts plus a category (a common WCM configuration) maps to
	 * exactly this shape, and reporting it clean would hide the NPPD-2063 blast radius
	 * until cutover.
	 */
	public function test_verify_migrated_gate_flags_content_rules_only_some_of_which_resolve() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post',
					'value' => [ '1' ],
				],
				[
					'slug'  => 'category',
					'value' => [ '2' ],
				],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules do not resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0], 'The dead slug is named so the operator knows what is left ungated.' );
	}

	/**
	 * A gate migrated from a plan that required a purchase, but whose paid access mode
	 * was never activated, lets any registered reader through: registration mode alone
	 * stops nobody with an account, since the migration never writes
	 * require_verification. That is a worse outcome than an inert gate — the content
	 * silently loses its paywall at cutover — so it must not pass verification.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_with_no_paid_access_mode() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'its paid access mode is not active', $issues[0] );
		$this->assertSame(
			[],
			$this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, false ] ),
			'The same gate is fine for a signup-only plan — only the purchase requirement makes it a leak.'
		);
	}

	/**
	 * An active paid access mode with no access rules asks for no purchase:
	 * is_post_restricted() skips an empty rule set, so a registered reader passes.
	 * Reachable when every one of a plan's products is a variation (dropped as gates
	 * reference parent products only) or when the plan has no products at all.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_whose_paid_access_has_no_rules() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		\Newspack\Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Paid access fixture layout', '' ),
				'access_rules'   => [],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * The paid path migrated fully — an active paid access mode constrained by the
	 * plan's products — so nothing is reported.
	 */
	public function test_verify_migrated_gate_passes_a_purchase_gate_with_product_access_rules() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		\Newspack\Content_Gate::update_custom_access_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Paid access fixture layout', '' ),
				'access_rules'   => [
					[
						[
							'slug'  => 'subscription',
							'value' => [ 123 ],
						],
					],
				],
			]
		);

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] ) );
	}

	/**
	 * The evaluator only checks that a mode's layout ID is truthy, so a blank layout
	 * post counts as "gated" and the reader gets a truncated article with nothing
	 * under it — no form, no upsell, no explanation.
	 */
	public function test_verify_migrated_gate_flags_an_active_mode_with_an_empty_layout() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			]
		);
		$layout_id = \Newspack\Content_Gate::get_registration_settings( $gate_id )['gate_layout_id'];
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => '',
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'points at an empty layout', $issues[0] );
	}

	/**
	 * A gate written with rule slugs the evaluator handles by name, an active mode and
	 * a layout post passes verification with no issues.
	 */
	public function test_verify_migrated_gate_passes_for_an_enforceable_gate() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] ) );
	}

	/**
	 * An active mode pointing at no layout post restricts nothing —
	 * Content_Restriction_Control requires a truthy gate_layout_id — so it is flagged.
	 */
	public function test_verify_migrated_gate_flags_an_active_mode_with_no_layout() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);
		\Newspack\Content_Gate::update_registration_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => 0,
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertContains( 'the registration mode is active with no layout', $issues );
	}

	/**
	 * A gate whose modes are all inactive is skipped outright by the evaluator.
	 */
	public function test_verify_migrated_gate_flags_a_gate_with_no_active_mode() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			] 
		);
		\Newspack\Content_Gate::update_registration_settings( $gate_id, [ 'active' => false ] );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertContains( 'neither the registration nor the paid access mode is active', $issues );
	}

	/**
	 * Slug resolvability mirrors Content_Restriction_Control::rule_matches_post():
	 * three slugs are handled by name and everything else must be a registered
	 * taxonomy.
	 */
	public function test_is_content_rule_slug_resolvable_matches_the_evaluator() {
		foreach ( [ 'post_types', 'specific_posts', 'newsletters', 'category', 'post_tag' ] as $resolvable_slug ) {
			$this->assertTrue(
				$this->invoke_private_static( 'is_content_rule_slug_resolvable', [ $resolvable_slug ] ),
				sprintf( 'Expected "%s" to be resolvable.', $resolvable_slug )
			);
		}
		foreach ( [ 'post', 'page', 'not_a_taxonomy' ] as $unresolvable_slug ) {
			$this->assertFalse(
				$this->invoke_private_static( 'is_content_rule_slug_resolvable', [ $unresolvable_slug ] ),
				sprintf( 'Expected "%s" to be unresolvable.', $unresolvable_slug )
			);
		}
	}

	// -------------------------------------------------------------------------
	// compute_pre_write_issues() — dry-run predictive verification
	// -------------------------------------------------------------------------

	/**
	 * A plan with all-unresolvable content-rule slugs is flagged in dry-run, mirroring
	 * the "none of its content rules resolve" check in verify_migrated_gate().
	 */
	public function test_compute_pre_write_issues_flags_all_unresolvable_slugs() {
		$ac_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
		];
		$layouts  = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its content rules resolve', $issues[0] );
		$this->assertStringContainsString( 'post', $issues[0] );
	}

	/**
	 * When only some slugs are unresolvable, the partial-leak variant of the message
	 * is produced — the content behind the dead rules stays ungated while the rest is
	 * covered.
	 */
	public function test_compute_pre_write_issues_flags_partially_unresolvable_slugs() {
		$ac_rules = [
			[
				'slug'  => 'post',
				'value' => [ '1' ],
			],
			[
				'slug'  => 'category',
				'value' => [ '2' ],
			],
		];
		$layouts = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules do not resolve', $issues[0] );
	}

	/**
	 * A purchase plan with no custom_access layout extracted is flagged — apply_layout()
	 * will be skipped for the paid access mode, so any registered reader gets through.
	 * Mirrors verify_migrated_gate()'s "paid access mode is not active" check.
	 */
	public function test_compute_pre_write_issues_flags_purchase_plan_with_no_custom_access_layout() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, true, $layouts, [ 123 ] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'paid access mode will not be activated', $issues[0] );
	}

	/**
	 * A purchase plan whose merged product IDs are all empty is flagged — access_rules
	 * will be an empty array, so the paid access mode asks for no purchase and any
	 * registered reader passes. Mirrors verify_migrated_gate()'s "active but has no
	 * access rules" check.
	 */
	public function test_compute_pre_write_issues_flags_purchase_plan_with_empty_product_ids() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => '<p>Member content.</p>',
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, true, $layouts, [] ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * A signup-only plan with resolvable slugs produces no pre-write issues.
	 */
	public function test_compute_pre_write_issues_returns_empty_for_a_clean_signup_plan() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Register.</p>',
			'custom_access' => null,
		];

		$this->assertSame(
			[],
			$this->invoke_private_static(
				'compute_pre_write_issues',
				[ $ac_rules, false, $layouts, [] ]
			)
		);
	}

	/**
	 * A purchase plan with a custom_access layout and at least one product ID is clean.
	 */
	public function test_compute_pre_write_issues_returns_empty_for_a_clean_purchase_plan() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<p>Upsell.</p>',
			'custom_access' => '<p>Welcome.</p>',
		];

		$this->assertSame(
			[],
			$this->invoke_private_static(
				'compute_pre_write_issues',
				[ $ac_rules, true, $layouts, [ 99 ] ]
			)
		);
	}

	/**
	 * Create a published gate with an active registration mode pointing at a real
	 * layout post — i.e. enforceable except for the content rules under test.
	 *
	 * @param array[] $content_rules AC-format content rules.
	 *
	 * @return int The gate post ID.
	 */
	private function create_enforceable_gate( array $content_rules ): int {
		$gate_id = \Newspack\Content_Gate::create_gate( [ 'title' => 'Verification fixture' ] );
		\Newspack\Content_Rules::update_gate_content_rules( $gate_id, $content_rules );
		\Newspack\Content_Gate::update_registration_settings(
			$gate_id,
			[
				'active'         => true,
				'gate_layout_id' => \Newspack\Content_Gate::create_gate_layout( 'Verification fixture layout', '' ),
			]
		);
		return $gate_id;
	}

	/**
	 * Create a published post carrying the given block content, standing in for an
	 * np_memberships_gate.
	 *
	 * A plain post (the default 'post' type) is used because extract_gate_layouts()
	 * only reads post_content — it never checks the post type — so the real
	 * np_memberships_gate CPT (registered by WooCommerce Memberships, which is not
	 * loaded in the unit-test harness) is not needed here.
	 *
	 * @param string $content The block markup.
	 *
	 * @return \WP_Post
	 */
	private function create_gate_post( string $content ): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_content' => $content,
				'post_status'  => 'publish',
			]
		);
		return get_post( $post_id );
	}

	/**
	 * Newsletter-list rules belong to the premium newsletter gate bucket, which
	 * migrate-premium-newsletters writes. Mapped here they would be inert — the
	 * evaluator judges a list post against the newsletter bucket — while still
	 * entering the fingerprint, splitting two plans that restrict identical content
	 * into two gates.
	 */
	public function test_map_rules_to_ac_format_skips_newsletter_list_rules() {
		$rules = [
			$this->make_rule( 'post', [] ),
			$this->make_rule( Subscription_Lists::CPT, [ 21, 22 ] ),
		];

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ $rules ] );

		$this->assertCount( 1, $mapped_rules );
		$this->assertSame( 'post', $mapped_rules[0]['slug'] );
	}

	/**
	 * A plan restricting only newsletter lists maps to no content rules at all, which
	 * is correct — but it is not the same as a plan that restricts nothing, and the
	 * operator needs to know where it went.
	 */
	public function test_plan_has_newsletter_rules_distinguishes_the_skip_reason() {
		$this->assertTrue(
			$this->invoke_private_static( 'plan_has_newsletter_rules', [ [ $this->make_rule( Subscription_Lists::CPT, [ 21 ] ) ] ] )
		);
		$this->assertFalse(
			$this->invoke_private_static( 'plan_has_newsletter_rules', [ [ $this->make_rule( 'post', [] ) ] ] )
		);
	}

	/**
	 * Build a plan-group descriptor carrying just the name find_superseded_gates()
	 * inspects.
	 *
	 * @param string $name The plan name.
	 *
	 * @return array
	 */
	private function make_named_plan( string $name ): array {
		return [
			'pid'           => 0,
			'name'          => $name,
			'access_method' => 'purchase',
			'ac_rules'      => [],
			'product_ids'   => [],
		];
	}

	/**
	 * When regrouping merges plans a previous run migrated separately, the gates
	 * those plans were written to are named so the operator can retire them.
	 */
	public function test_find_superseded_gates_names_gates_the_merged_plans_already_have() {
		$group          = [ $this->make_named_plan( 'Plan A' ), $this->make_named_plan( 'Plan B' ) ];
		$existing_gates = [
			'plan a' => 11,
			'plan b' => 22,
		];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a | plan b', $existing_gates ] );

		// Keyed by the title as written, not the lower-cased lookup key: the operator is
		// being sent to Newsletters > Premium to find these gates by name.
		$this->assertSame(
			[
				'Plan A' => 11,
				'Plan B' => 22,
			],
			$superseded
		);
	}

	/**
	 * A single-plan group's title IS its plan name, so the gate it is about to update
	 * must never be reported as superseded by itself.
	 */
	public function test_find_superseded_gates_excludes_the_groups_own_title() {
		$group = [ $this->make_named_plan( 'Plan A' ) ];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a', [ 'plan a' => 11 ] ] );

		$this->assertSame( [], $superseded );
	}

	/**
	 * A genuinely new group supersedes nothing.
	 */
	public function test_find_superseded_gates_returns_empty_when_no_plan_has_a_gate() {
		$group = [ $this->make_named_plan( 'Plan A' ), $this->make_named_plan( 'Plan B' ) ];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a | plan b', [ 'other' => 11 ] ] );

		$this->assertSame( [], $superseded );
	}

	/**
	 * The pre-flight prompt fires once for every group that would supersede an
	 * existing gate, so it has to find them all before the first write.
	 */
	public function test_find_superseding_groups_names_what_each_merged_group_supersedes() {
		$plan_groups = [
			'[1]' => [ $this->make_named_plan( 'Plan A' ), $this->make_named_plan( 'Plan B' ) ],
		];

		$superseding = $this->invoke_private_static(
			'find_superseding_groups',
			[
				$plan_groups,
				[
					'plan a' => 11,
					'plan b' => 22,
				],
			]
		);

		$this->assertSame(
			[
				'Plan A | Plan B' => [
					'Plan A' => 11,
					'Plan B' => 22,
				],
			],
			$superseding
		);
	}

	/**
	 * A group whose own title already exists updates that gate rather than creating a
	 * second one, so it supersedes nothing and must not raise a prompt.
	 */
	public function test_find_superseding_groups_skips_a_group_that_updates_its_own_gate() {
		$plan_groups = [
			'[1]' => [ $this->make_named_plan( 'Plan A' ) ],
		];

		$this->assertSame(
			[],
			$this->invoke_private_static( 'find_superseding_groups', [ $plan_groups, [ 'plan a' => 11 ] ] )
		);
	}

	/**
	 * Two same-named plans with different content rules land in different groups and
	 * resolve to one gate title. The second group would take the update branch, and
	 * update_gate_content_rules() replaces rather than merges — so the first group's
	 * content would end up behind no gate at all while both rows reported as
	 * processed. The collision is computable before any write, so it is found here.
	 */
	public function test_find_colliding_gate_titles_fires_for_two_groups_sharing_a_title() {
		$plan_groups = [
			'[1]' => [ $this->make_named_plan( 'Premium' ) ],
			'[2]' => [ $this->make_named_plan( 'premium' ) ],
		];

		$this->assertSame(
			[ 'Premium' ],
			$this->invoke_private_static( 'find_colliding_gate_titles', [ $plan_groups ] )
		);
	}

	/**
	 * One group cannot collide with itself.
	 */
	public function test_find_colliding_gate_titles_is_empty_for_a_single_group() {
		$plan_groups = [
			'[1]' => [ $this->make_named_plan( 'Premium' ) ],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_colliding_gate_titles', [ $plan_groups ] ) );
	}

	/**
	 * Distinct titles name distinct gates, which is the ordinary multi-gate run — it
	 * must not be stopped.
	 */
	public function test_find_colliding_gate_titles_is_empty_for_distinct_titles() {
		$plan_groups = [
			'[1]' => [ $this->make_named_plan( 'Premium' ) ],
			'[2]' => [ $this->make_named_plan( 'Insider' ) ],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_colliding_gate_titles', [ $plan_groups ] ) );
	}

	/**
	 * WP-CLI strips a bare `--plan` before the command runs, so the command sees no
	 * plan at all. The raw argv is the only place the mistake is still visible.
	 */
	public function test_get_valueless_value_flags_reports_a_bare_plan() {
		$this->assertSame(
			[ '--plan' ],
			Membership_Gates_Migration::get_valueless_value_flags( [ 'wp', 'newspack', 'migrate-membership-gates', '--plan', '--live' ] )
		);
	}

	/**
	 * A --plan that carries its value is the ordinary invocation and must pass.
	 */
	public function test_get_valueless_value_flags_ignores_a_plan_with_a_value() {
		$this->assertSame(
			[],
			Membership_Gates_Migration::get_valueless_value_flags( [ 'wp', 'newspack', 'migrate-membership-gates', '--plan=12', '--live' ] )
		);
	}

	/**
	 * A bare --one-time-duration is stripped the same way, and the run then stops over
	 * a duration the operator did supply.
	 */
	public function test_get_valueless_value_flags_reports_a_bare_one_time_duration() {
		$this->assertSame(
			[ '--one-time-duration' ],
			Membership_Gates_Migration::get_valueless_value_flags( [ 'wp', 'newspack', 'migrate-membership-gates', '--one-time-duration', '--live' ] )
		);
	}

	/**
	 * A duration the one_time_purchase rule cannot store must stop the run: writing an
	 * unrecognised unit would leave a rule that grants nobody access.
	 */
	public function test_migrate_membership_gates_aborts_on_an_unusable_one_time_duration() {
		$migration = new Membership_Gates_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --one-time-duration value' );

		$migration->migrate_membership_gates( [], [ 'one-time-duration' => '1year' ] );
	}

	/**
	 * The guard has to be wired into the command, not merely available: a bare --plan
	 * with --live would otherwise rewrite every gate on the site.
	 */
	public function test_migrate_membership_gates_aborts_on_a_bare_plan_flag() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'migrate-membership-gates', '--plan', '--live' ];
		$migration       = new Membership_Gates_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'require a value but arrived without one' );

		$migration->migrate_membership_gates( [], [ 'live' => true ] );
	}

	/**
	 * PHP's is_numeric() accepts '12.9', which casts to plan 12 — a run narrowed to a plan
	 * the operator never named.
	 */
	public function test_migrate_membership_gates_aborts_on_a_fractional_plan() {
		$migration = new Membership_Gates_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --plan value' );

		$migration->migrate_membership_gates( [], [ 'plan' => '12.9' ] );
	}

	/**
	 * PHP's is_numeric() accepts '1e2', which casts to plan 100.
	 */
	public function test_migrate_membership_gates_aborts_on_an_exponent_plan() {
		$migration = new Membership_Gates_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --plan value' );

		$migration->migrate_membership_gates( [], [ 'plan' => '1e2' ] );
	}

	/**
	 * A digits-only --plan passes both guards and the run proceeds — reaching, in this
	 * harness, the WooCommerce Memberships pre-flight. Without this the two guards
	 * above could pass by rejecting everything.
	 */
	public function test_migrate_membership_gates_accepts_a_digits_only_plan() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'migrate-membership-gates', '--plan=12' ];
		$migration       = new Membership_Gates_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'WooCommerce Memberships is not active' );

		$migration->migrate_membership_gates( [], [ 'plan' => '12' ] );
	}

	/**
	 * WP_CLI::confirm() reads STDIN, and at EOF it exits with status 0 and no message
	 * — so an `ssh host "wp newspack migrate-…"` run would stop at the prompt having
	 * already written every gate before it, and report success. This command had no
	 * prompts at all before, so anyone re-running it the way they ran it the first
	 * time would get a silent partial migration. With no terminal and no --yes, the
	 * prompt must never be asked.
	 */
	public function test_prompt_is_unanswerable_without_a_terminal_or_yes() {
		$this->assertTrue( $this->invoke_private_static( 'prompt_is_unanswerable', [ [], false ] ) );
	}

	/**
	 * --yes is the documented way to run unattended, so it answers the prompt.
	 */
	public function test_prompt_is_answerable_with_yes() {
		$this->assertFalse( $this->invoke_private_static( 'prompt_is_unanswerable', [ [ 'yes' => true ], false ] ) );
	}

	/**
	 * An operator at a terminal can answer, so the prompt is asked as usual.
	 */
	public function test_prompt_is_answerable_at_a_terminal() {
		$this->assertFalse( $this->invoke_private_static( 'prompt_is_unanswerable', [ [], true ] ) );
	}

	/**
	 * The branch that matters is the one PHPUnit could never reach before: STDIN is
	 * never a terminal under test, so the prompt itself went unexercised while the
	 * error path looked covered. With the terminal check passed in, a superseding
	 * group under --live is pinned as reaching the prompt rather than the abort.
	 */
	public function test_confirm_or_error_prompts_when_stdin_is_a_terminal() {
		\WP_CLI::$messages = [];

		$this->invoke_private_static( 'confirm_or_error', [ 'Proceed?', [], true ] );

		$this->assertContains( [ 'confirm', 'Proceed?' ], \WP_CLI::$messages );
	}

	/**
	 * With nothing able to answer, WP_CLI::confirm() would take the default and stop
	 * the run part-way through with no summary. Erroring up front says why.
	 */
	public function test_confirm_or_error_aborts_when_nothing_can_answer() {
		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'STDIN is not a terminal' );

		$this->invoke_private_static( 'confirm_or_error', [ 'Proceed?', [], false ] );
	}

	/**
	 * --yes answers the prompt up front, which is what makes a non-interactive run
	 * possible at all.
	 */
	public function test_confirm_or_error_accepts_yes_without_a_terminal() {
		\WP_CLI::$messages = [];

		$this->invoke_private_static( 'confirm_or_error', [ 'Proceed?', [ 'yes' => true ], false ] );

		$this->assertContains( [ 'confirm', 'Proceed?' ], \WP_CLI::$messages );
	}


	/**
	 * Two published gates can be named alike by hand, and indexing them by title is
	 * last-write-wins: the run would update one and leave the other restricting the
	 * same content, with nothing in the output to show it.
	 */
	public function test_find_duplicate_gate_titles_fires_for_two_gates_sharing_a_title() {
		$gates = [
			[
				'id'    => 11,
				'title' => 'Members',
			],
			[
				'id'    => 12,
				'title' => 'members',
			],
		];

		$this->assertSame( [ 'Members' ], $this->invoke_private_static( 'find_duplicate_gate_titles', [ $gates ] ) );
	}

	/**
	 * Distinct titles are the ordinary case; firing here would refuse every site with
	 * more than one content gate.
	 */
	public function test_find_duplicate_gate_titles_is_empty_for_distinct_titles() {
		$gates = [
			[
				'id'    => 11,
				'title' => 'Members',
			],
			[
				'id'    => 12,
				'title' => 'Insider',
			],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_duplicate_gate_titles', [ $gates ] ) );
	}

	/**
	 * Every dropped-product warning describes a paid access rule, and a mixed group
	 * writes none. Telling an operator the gate would have granted access to every
	 * subscriber describes a rule that was never written.
	 */
	public function test_report_dropped_product_ids_is_silent_for_a_gate_with_no_paid_rule() {
		\WP_CLI::$warnings = [];

		$this->invoke_private_static(
			'report_dropped_product_ids',
			[ 'Paid | Free', [ 'invalid' => [ 0 ] ], false ]
		);

		$this->assertSame( [], \WP_CLI::$warnings );
	}

	/**
	 * The counterpart: the same dropped ID on a group that does write a rule still
	 * warns, so the guard suppresses the false case rather than the warning itself.
	 */
	public function test_report_dropped_product_ids_still_warns_for_a_purchase_group() {
		\WP_CLI::$warnings = [];

		$this->invoke_private_static(
			'report_dropped_product_ids',
			[ 'Paid', [ 'invalid' => [ 0 ] ], true ]
		);

		$this->assertNotEmpty( \WP_CLI::$warnings );
	}
}
