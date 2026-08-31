<?php
/**
 * Characterization tests for the migrate-membership-gates CLI (NPPD-2059).
 *
 * These pin the behavior of the pure mapping/fingerprint/layout-extraction
 * helpers. The map_rules_to_ac_format tests assert the NPPD-2063 translation table
 * (WC content rules → valid AC 'post_types' / 'specific_posts' / taxonomy slugs).
 * The extract_gate_layouts / serialize_gate_inner_blocks tests assert the NPPD-2058
 * behavior: a gate layout is found wherever the wrapper block sits, including nested
 * and reusable blocks, and membership wrappers nested in the result are stripped.
 *
 * NPPD-2064 (content-overlap consolidation) is pinned by the tests below: the decision
 * through its pure helpers — rules_cover, rule_sets_overlap, plan_rule_set_consolidation —
 * and the fold itself through consolidate_plan_groups(), which is what writes the merged
 * group the gate is built from. Only group_plans_by_fingerprint() ahead of it needs
 * WC_Memberships_Membership_Plan and is exercised end-to-end instead. The
 * compute_rules_fingerprint() tests pin the fingerprint's canonicality, which that fix
 * preserves.
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

	// The consolidation tests expand hierarchical terms through
	// Content_Restriction_Control, whose descendant memo is request-scoped and
	// therefore outlives a rolled-back case that reused the same term IDs.
	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

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
		\WP_CLI::reset();
		$this->reset_restriction_cache();
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
	 * Uses invokeArgs() rather than invoke(): several of these methods report through a
	 * by-reference out-parameter, and spreading the argument list would pass those by
	 * value.
	 *
	 * @param string $method_name The method name.
	 * @param array  $arguments   Positional arguments; pass `&$var` for an out-parameter.
	 *
	 * @return mixed The method return value.
	 */
	private function invoke_private_static( string $method_name, array $arguments ) {
		$reflected_method = new \ReflectionMethod( Membership_Gates_Migration::class, $method_name );
		$reflected_method->setAccessible( true );
		return $reflected_method->invokeArgs( null, $arguments );
	}

	/**
	 * Build a minimal stand-in for a WC_Memberships_Membership_Plan_Rule.
	 *
	 * The rule mapping only calls get_content_type(), get_content_type_name() and
	 * get_object_ids(), so the WC Memberships plugin is not needed to exercise it.
	 *
	 * @param string $content_type      The WC content type kind ('post_type' or 'taxonomy').
	 * @param string $content_type_name The WC content type name (e.g. 'post', 'category').
	 * @param int[]  $object_ids        The restricted object IDs.
	 *
	 * @return object A rule-shaped object.
	 */
	private function make_rule( string $content_type, string $content_type_name, array $object_ids ) {
		return new class( $content_type, $content_type_name, $object_ids ) {

			/**
			 * The WC content type kind.
			 *
			 * @var string
			 */
			private $content_type;

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
			 * @param string $content_type      The WC content type kind.
			 * @param string $content_type_name The WC content type name.
			 * @param int[]  $object_ids        The restricted object IDs.
			 */
			public function __construct( string $content_type, string $content_type_name, array $object_ids ) {
				$this->content_type      = $content_type;
				$this->content_type_name = $content_type_name;
				$this->object_ids        = $object_ids;
			}

			/**
			 * Return the WC content type kind ('post_type' or 'taxonomy').
			 *
			 * @return string
			 */
			public function get_content_type() {
				return $this->content_type;
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
	 * safe but over-restricts, so it is dropped and reported too.
	 *
	 * A variation is kept: a gate rule matches a line item on product_id or
	 * variation_id, so the variation ID grants exactly what the plan granted — where
	 * the parent product would also admit buyers of its sibling variations.
	 */
	public function test_resolve_product_ids_drops_ids_a_subscription_rule_must_not_carry() {
		$product   = $this->create_product( 'subscription' );
		$variation = $this->register_product_type( self::factory()->post->create( [ 'post_type' => 'product_variation' ] ), 'subscription_variation' );
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

		$this->assertSame( [ $product, $variation ], $resolved['product_ids'] );
		$this->assertSame(
			[ $product, $variation ],
			$resolved['subscription_ids'],
			'A subscription variation belongs on the subscription rule; a one-time rule would expire access the plan granted for as long as it ran.'
		);
		$this->assertSame( [ 0, -7 ], $resolved['dropped']['invalid'] );
		$this->assertSame( [ $deleted ], $resolved['dropped']['unresolvable'] );
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
	 * A post-type rule targeting specific objects maps to a 'specific_posts' rule
	 * whose value is the stringified object IDs — the slug AC enforcement honours for
	 * individual posts (a raw 'post'/'page' slug would never match any post).
	 */
	public function test_map_rules_to_ac_format_maps_specific_post_type_rule_to_specific_posts() {
		$post_rule = $this->make_rule( 'post_type', 'post', [ 12, 34 ] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $post_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ '12', '34' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * A post-type rule with no object IDs restricts the whole post type, so it maps
	 * to a 'post_types' rule whose value is the post-type slug.
	 */
	public function test_map_rules_to_ac_format_maps_all_posts_rule_to_post_types() {
		$all_posts_rule = $this->make_rule( 'post_type', 'post', [] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $all_posts_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * The post_type vs. taxonomy split relies on the rule's own get_content_type()
	 * discriminator, so a whole-post-type rule for a custom post type (here
	 * 'guest-author') maps to a 'post_types' rule carrying that custom post-type slug
	 * as its value — no hardcoded post-type name list is consulted.
	 */
	public function test_map_rules_to_ac_format_maps_custom_post_type_to_post_types() {
		$guest_author_rule = $this->make_rule( 'post_type', 'guest-author', [] );

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $guest_author_rule ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'guest-author' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Taxonomy rules already use the taxonomy slug as their AC slug (which AC
	 * enforcement resolves via get_taxonomy()), so they pass through unchanged with a
	 * term-ID value list.
	 */
	public function test_map_rules_to_ac_format_keeps_taxonomy_slug_unchanged() {
		$category_rule = $this->make_rule( 'taxonomy', 'category', [ 5, 6 ] );
		$tag_rule      = $this->make_rule( 'taxonomy', 'post_tag', [ 7 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $category_rule, $tag_rule ] ]
		);

		$this->assertSame(
			[
				[
					'slug'  => 'category',
					'value' => [ '5', '6' ],
				],
				[
					'slug'  => 'post_tag',
					'value' => [ '7' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Two rules that map to the same AC slug are merged into one rule with a
	 * de-duplicated, stringified value list.
	 */
	public function test_map_rules_to_ac_format_merges_and_dedupes_object_ids_for_the_same_slug() {
		$first_category_rule  = $this->make_rule( 'taxonomy', 'category', [ 1, 2 ] );
		$second_category_rule = $this->make_rule( 'taxonomy', 'category', [ 2, 3 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $first_category_rule, $second_category_rule ] ]
		);

		$this->assertCount( 1, $mapped_rules, 'Same-slug rules collapse into a single AC rule.' );
		$this->assertSame( 'category', $mapped_rules[0]['slug'] );
		$this->assertSame( [ '1', '2', '3' ], $mapped_rules[0]['value'], 'Object IDs are merged, de-duplicated, and stringified.' );
	}

	/**
	 * A mixed rule set exercises all three mappings and their merge semantics at
	 * once: whole-post-type rules merge their post-type slugs under 'post_types',
	 * specific-object rules (across different post types) merge their IDs under
	 * 'specific_posts', and a taxonomy rule keeps its own slug. The 'post_types'
	 * value is sorted (see the canonicalization test below).
	 */
	public function test_map_rules_to_ac_format_merges_mixed_rule_set_by_target_slug() {
		$all_posts_rule    = $this->make_rule( 'post_type', 'post', [] );
		$all_pages_rule    = $this->make_rule( 'post_type', 'page', [] );
		$specific_page     = $this->make_rule( 'post_type', 'page', [ 5 ] );
		$specific_articles = $this->make_rule( 'post_type', 'post', [ 12, 34 ] );
		$category_rule     = $this->make_rule( 'taxonomy', 'category', [ 8 ] );

		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $all_posts_rule, $all_pages_rule, $specific_page, $specific_articles, $category_rule ] ]
		);

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'page', 'post' ],
				],
				[
					'slug'  => 'specific_posts',
					'value' => [ '5', '12', '34' ],
				],
				[
					'slug'  => 'category',
					'value' => [ '8' ],
				],
			],
			$mapped_rules,
			'Only post_types is canonicalized; specific_posts and taxonomy values keep insertion order (the fingerprint orders those numeric IDs via SORT_NUMERIC).'
		);
	}

	/**
	 * The 'post_types' value is sorted, so two plans restricting the same post types
	 * in a different rule order produce identical mapped output — and therefore the
	 * same grouping fingerprint, so they share one gate instead of splitting into
	 * duplicates. (Post-type slugs are non-numeric, and compute_rules_fingerprint()'s
	 * SORT_NUMERIC pass would otherwise leave their order untouched.)
	 */
	public function test_map_rules_to_ac_format_canonicalizes_post_types_value_order() {
		$posts_then_pages = [
			$this->make_rule( 'post_type', 'post', [] ),
			$this->make_rule( 'post_type', 'page', [] ),
		];
		$pages_then_posts = [
			$this->make_rule( 'post_type', 'page', [] ),
			$this->make_rule( 'post_type', 'post', [] ),
		];

		$mapped_posts_first = $this->invoke_private_static( 'map_rules_to_ac_format', [ $posts_then_pages ] );
		$mapped_pages_first = $this->invoke_private_static( 'map_rules_to_ac_format', [ $pages_then_posts ] );

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'page', 'post' ],
				],
			],
			$mapped_posts_first,
			'post_types values are sorted, so rule order does not change the output.'
		);
		$this->assertSame( $mapped_posts_first, $mapped_pages_first, 'Rule order does not change the mapped output.' );

		$this->assertSame(
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $mapped_posts_first ] ),
			$this->invoke_private_static( 'compute_rules_fingerprint', [ $mapped_pages_first ] ),
			'Identical output yields identical fingerprints, so the plans group into one gate.'
		);
	}

	/**
	 * A plan with no content restriction rules maps to no AC rules, which is what
	 * group_plans_by_fingerprint() reads to skip the plan instead of publishing an
	 * inert gate.
	 */
	public function test_map_rules_to_ac_format_maps_an_empty_rule_set_to_no_rules() {
		$this->assertSame( [], $this->invoke_private_static( 'map_rules_to_ac_format', [ [] ] ) );
	}

	/**
	 * Two rules naming the same whole post type collapse into a single 'post_types'
	 * entry carrying one slug, rather than repeating it.
	 */
	public function test_map_rules_to_ac_format_dedupes_identical_whole_post_type_rules() {
		$mapped_rules = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[
				[
					$this->make_rule( 'post_type', 'post', [] ),
					$this->make_rule( 'post_type', 'post', [] ),
				],
			]
		);

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			],
			$mapped_rules
		);
	}

	/**
	 * Rules with an empty content-type name are dropped entirely.
	 */
	public function test_map_rules_to_ac_format_skips_rules_with_empty_content_type() {
		$empty_rule = $this->make_rule( 'post_type', '', [ 7 ] );

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
	 * A wrapper nested inside another block (here a group) is found and migrated
	 * (NPPD-2058).
	 */
	public function test_extract_gate_layouts_finds_nested_wrapper_blocks() {
		$gate_content = <<<'HTML'
<!-- wp:group --><div class="wp-block-group">
<!-- wp:columns --><div class="wp-block-columns">
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Nested upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
</div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Nested upsell.', $layouts['registration'] );
		$this->assertNull( $layouts['custom_access'], 'No member-content wrapper anywhere means a null custom-access layout.' );
	}

	/**
	 * Members-only content nested inside the non-member wrapper never reaches the
	 * registration layout, however deeply it is buried.
	 *
	 * The two wrappers carry opposite audiences, so this is the one nesting case
	 * that leaks rather than merely losing content: after WooCommerce Memberships is
	 * deactivated the wrapper block type no longer resolves, WP_Block treats it as
	 * static, and its saved inner content prints unconditionally — showing paying
	 * members' copy to the non-members the registration layout is for.
	 */
	public function test_extract_gate_layouts_never_leaks_member_content_into_the_registration_layout() {
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Subscribe to read.</p><!-- /wp:paragraph -->
<!-- wp:group --><div class="wp-block-group">
<!-- wp:paragraph --><p>Before the wrapper.</p><!-- /wp:paragraph -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Members only secret.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>After the wrapper.</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->
<!-- /wp:woocommerce-memberships/non-member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Subscribe to read.', $layouts['registration'] );
		$this->assertStringNotContainsString( 'Members only secret.', $layouts['registration'] );
		$this->assertStringNotContainsString( 'woocommerce-memberships/member-content', $layouts['registration'], 'The wrapper block itself is dropped, not just hidden — an unregistered block type renders its saved inner content as static markup.' );
		// The siblings are what make this test bite twice: dropping a child without
		// dropping its innerContent placeholder shifts the survivors into the wrong
		// slots, so a serializer that filtered innerBlocks alone would reorder these
		// two or index past the end of the array.
		$this->assertStringContainsString( 'Before the wrapper.', $layouts['registration'] );
		$this->assertStringContainsString( 'After the wrapper.', $layouts['registration'] );
		$this->assertLessThan(
			strpos( $layouts['registration'], 'After the wrapper.' ),
			strpos( $layouts['registration'], 'Before the wrapper.' ),
			'The blocks either side of the dropped wrapper keep their document order.'
		);
	}

	/**
	 * A cycle spanning two patterns (A references B, B references A) terminates.
	 *
	 * This is a different path through the visited set than a pattern that references
	 * itself: a guard that only remembered the pattern it is currently in would loop
	 * here, and one that skipped any ref seen anywhere would break the legitimate
	 * repeat case above. Both have to hold at once.
	 */
	public function test_extract_gate_layouts_survives_a_two_pattern_cycle() {
		$pattern_a = $this->create_pattern_post( 'placeholder' );
		$pattern_b = $this->create_pattern_post( 'placeholder' );
		wp_update_post(
			[
				'ID'           => $pattern_a,
				'post_content' => '<!-- wp:block {"ref":' . $pattern_b . '} /-->',
			]
		);
		wp_update_post(
			[
				'ID'           => $pattern_b,
				'post_content' => '<!-- wp:block {"ref":' . $pattern_a . '} /-->'
					. '<!-- wp:woocommerce-memberships/non-member-content -->'
					. '<!-- wp:paragraph --><p>Upsell past the cycle.</p><!-- /wp:paragraph -->'
					. '<!-- /wp:woocommerce-memberships/non-member-content -->',
			]
		);
		$gate_post = $this->create_gate_post( '<!-- wp:block {"ref":' . $pattern_a . '} /-->' );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Upsell past the cycle.', $layouts['registration'] );
	}

	/**
	 * A gate authored as a synced pattern holds its wrappers in a separate wp_block
	 * post; the `core/block` reference is resolved so that content migrates too.
	 */
	public function test_extract_gate_layouts_resolves_reusable_block_references() {
		$pattern_id   = $this->create_pattern_post(
			<<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Pattern upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:woocommerce-memberships/member-content -->
<!-- wp:paragraph --><p>Pattern members-only.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/member-content -->
HTML
		);
		$gate_post = $this->create_gate_post( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Pattern upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'Pattern members-only.', $layouts['custom_access'] );
	}

	/**
	 * The reference guard is scoped to one path of the walk, not the whole gate, so a
	 * pattern placed twice contributes twice — the same "no authored content dropped"
	 * rule that governs repeated inline wrappers.
	 */
	public function test_extract_gate_layouts_keeps_both_copies_of_a_repeated_pattern() {
		$pattern_id = $this->create_pattern_post(
			<<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Reused upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
HTML
		);
		$reference = '<!-- wp:block {"ref":' . $pattern_id . '} /-->';
		$gate_post = $this->create_gate_post( $reference . "\n" . $reference );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertSame( 2, substr_count( $layouts['registration'], 'Reused upsell.' ) );
	}

	/**
	 * A pattern that references itself would otherwise recurse forever. The walk stops
	 * at the repeat and still reaches the wrapper that follows it.
	 */
	public function test_extract_gate_layouts_survives_a_self_referencing_pattern() {
		$pattern_id = $this->create_pattern_post( 'placeholder' );
		wp_update_post(
			[
				'ID'           => $pattern_id,
				'post_content' => '<!-- wp:block {"ref":' . $pattern_id . '} /-->'
					. '<!-- wp:woocommerce-memberships/non-member-content -->'
					. '<!-- wp:paragraph --><p>Upsell past the loop.</p><!-- /wp:paragraph -->'
					. '<!-- /wp:woocommerce-memberships/non-member-content -->',
			]
		);
		$gate_post = $this->create_gate_post( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Upsell past the loop.', $layouts['registration'] );
	}

	/**
	 * An unpublished pattern renders nothing for a reader, so extraction skips it the
	 * same way — but the operator is told, because the gate will migrate short of the
	 * content its author sees in the editor.
	 */
	public function test_extract_gate_layouts_warns_on_an_unpublished_pattern_reference() {
		\WP_CLI::$messages = [];
		$pattern_id        = $this->create_pattern_post(
			<<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Draft upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
HTML
			,
			'draft'
		);
		$gate_post = $this->create_gate_post( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertSame( '', $layouts['registration'] );
		$this->assertNotEmpty( \WP_CLI::$warnings, 'The skipped pattern reference is warned about.' );
		$this->assertStringContainsString( (string) $pattern_id, implode( ' ', \WP_CLI::$warnings ) );
	}

	/**
	 * With no wrapper anywhere in the tree, registration comes back as an empty string
	 * and custom_access as null. apply_layout() reads that distinction to leave the
	 * gate's seeded default layout alone rather than blanking it.
	 */
	public function test_extract_gate_layouts_returns_empty_when_no_wrapper_exists() {
		$gate_post = $this->create_gate_post( '<!-- wp:paragraph --><p>Just an article.</p><!-- /wp:paragraph -->' );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertSame( '', $layouts['registration'] );
		$this->assertNull( $layouts['custom_access'] );
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

		$serialized = $this->invoke_private_static( 'serialize_gate_inner_blocks', [ $inner_blocks, 'woocommerce-memberships/non-member-content', 0 ] );

		$this->assertStringContainsString( 'Keep me.', $serialized );
		$this->assertStringNotContainsString( 'woocommerce-memberships/member-content', $serialized );
		$this->assertStringNotContainsString( 'Drop me.', $serialized );
	}

	/**
	 * A gate whose every content rule carries a slug the evaluator cannot resolve is
	 * reported as unenforceable.
	 *
	 * A raw WooCommerce content-type name ('post') is the canonical shape of an
	 * unresolvable slug: Content_Restriction_Control::rule_matches_post() handles
	 * 'post_types', 'specific_posts' and 'newsletters' by name and treats every other
	 * slug as a taxonomy, so it falls through to get_taxonomy( 'post' ) — which is
	 * null — and the gate matches no post at all.
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
	 * left readable while the rest is gated. That partial leak is reported too, since
	 * reporting such a gate clean would hide the leak until cutover.
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
	 * Rules with an empty value are dropped by get_gate_content_rules(), so a gate
	 * written with two rules can evaluate as having one. The verification reads the written meta,
	 * not the evaluated rules, so the dropped slug is named rather than the gate
	 * passing as clean while the content that rule covered stays readable.
	 */
	public function test_verify_migrated_gate_flags_a_written_rule_that_selects_no_content() {
		$gate_id = $this->create_enforceable_gate(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
				[
					'slug'  => 'category',
					'value' => [],
				],
			]
		);

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules select no content', $issues[0] );
		$this->assertStringContainsString( 'category', $issues[0] );
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
		// A real layout, so the only issue this can produce is the slug one.
		$layouts = [
			'registration'  => '<!-- wp:paragraph --><p>Upsell.</p><!-- /wp:paragraph -->',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts ]
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
		// A real layout, so the only issue this can produce is the slug one.
		$layouts = [
			'registration'  => '<!-- wp:paragraph --><p>Upsell.</p><!-- /wp:paragraph -->',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules do not resolve', $issues[0] );
	}

	/**
	 * A gate whose registration layout extracted to nothing is flagged in dry-run —
	 * the one pass that can see it, for the reason compute_pre_write_issues() gives.
	 */
	public function test_compute_pre_write_issues_flags_an_empty_registration_layout() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts ]
		);

		$this->assertCount( 1, $issues, 'The resolvable slug produces no issue of its own, so the empty layout is the only one.' );
		$this->assertStringContainsString( 'no registration layout content could be extracted', $issues[0] );
	}

	/**
	 * A group with no gate post at all is not flagged for its empty registration
	 * layout. There was no authored copy to lose, so the seeded Newspack default is
	 * the right outcome rather than a warning the operator has to triage.
	 */
	public function test_compute_pre_write_issues_does_not_flag_an_empty_layout_when_no_gate_post_exists() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '',
			'custom_access' => null,
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, false, $layouts, false ]
		);

		$this->assertSame( [], $issues, 'No gate post means no lost content to warn about.' );
	}

	/**
	 * A paid access layout that was found but extracted to nothing is flagged, which
	 * is a different shape from one that was never found: null means the paid mode
	 * never activates, an empty string means it activates over default copy.
	 */
	public function test_compute_pre_write_issues_flags_an_empty_paid_access_layout() {
		$ac_rules = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$layouts  = [
			'registration'  => '<!-- wp:paragraph --><p>Subscribe.</p><!-- /wp:paragraph -->',
			'custom_access' => '',
		];

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $ac_rules, true, $layouts ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'paid access layout extracted to nothing', $issues[0] );
	}

	/**
	 * A synced-pattern reference inside a wrapper is carried into the layout as a
	 * reference, not resolved and inlined.
	 *
	 * The layout renders through WordPress, which resolves the ref itself, so passing
	 * it through keeps the publisher's pattern editable in one place after migration.
	 * The wrapper strip deliberately does not follow it.
	 */
	public function test_extract_gate_layouts_carries_a_pattern_reference_through_a_wrapper() {
		$pattern_id = $this->create_pattern_post( '<!-- wp:paragraph --><p>Shared upsell copy.</p><!-- /wp:paragraph -->' );
		$gate_post  = $this->create_gate_post(
			'<!-- wp:woocommerce-memberships/non-member-content -->'
			. sprintf( '<!-- wp:block {"ref":%d} /-->', $pattern_id )
			. '<!-- /wp:woocommerce-memberships/non-member-content -->'
		);

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( sprintf( '<!-- wp:block {"ref":%d} /-->', $pattern_id ), $layouts['registration'], 'The reference survives verbatim.' );
		$this->assertStringNotContainsString( 'Shared upsell copy.', $layouts['registration'], 'The referenced content is not inlined.' );
	}

	/**
	 * A wrapper nested inside one of the same type is unwrapped, not dropped.
	 *
	 * Both wrappers address the same audience, so the inner one's copy belongs in the
	 * layout being built. Only the opposite wrapper carries content this layout's
	 * readers must not see.
	 */
	public function test_extract_gate_layouts_unwraps_a_nested_wrapper_of_the_same_type() {
		// Two nestings, because the strip takes a different path for each: a direct
		// child of the wrapper is walked as a plain block list, while one inside a
		// group also has an innerContent placeholder map to keep in step.
		$gate_content = <<<'HTML'
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Outer upsell.</p><!-- /wp:paragraph -->
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Directly nested upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
<!-- wp:group --><div class="wp-block-group">
<!-- wp:woocommerce-memberships/non-member-content -->
<!-- wp:paragraph --><p>Grouped upsell.</p><!-- /wp:paragraph -->
<!-- /wp:woocommerce-memberships/non-member-content -->
</div><!-- /wp:group -->
<!-- /wp:woocommerce-memberships/non-member-content -->
HTML;
		$gate_post = $this->create_gate_post( $gate_content );

		$layouts = $this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertStringContainsString( 'Outer upsell.', $layouts['registration'] );
		$this->assertStringContainsString( 'Directly nested upsell.', $layouts['registration'], 'A same-audience wrapper contributes its content rather than losing it.' );
		$this->assertStringContainsString( 'Grouped upsell.', $layouts['registration'], 'The same holds one level down, where placeholders have to be kept in step.' );
		$this->assertStringNotContainsString( 'woocommerce-memberships/non-member-content', $layouts['registration'], 'The wrapper markup itself is still dropped.' );
	}

	/**
	 * A wrapper two pattern hops away is warned about.
	 *
	 * WordPress resolves the whole reference chain at render time, so a wrapper inside
	 * a pattern inside a pattern reaches the reader exactly like one hop does.
	 */
	public function test_extract_gate_layouts_warns_about_a_wrapper_behind_chained_patterns() {
		$inner_pattern_id = $this->create_pattern_post(
			'<!-- wp:woocommerce-memberships/member-content -->'
			. '<!-- wp:paragraph --><p>Members only secret.</p><!-- /wp:paragraph -->'
			. '<!-- /wp:woocommerce-memberships/member-content -->'
		);
		$outer_pattern_id = $this->create_pattern_post( sprintf( '<!-- wp:block {"ref":%d} /-->', $inner_pattern_id ) );
		$gate_post        = $this->create_gate_post(
			'<!-- wp:woocommerce-memberships/non-member-content -->'
			. sprintf( '<!-- wp:block {"ref":%d} /-->', $outer_pattern_id )
			. '<!-- /wp:woocommerce-memberships/non-member-content -->'
		);

		$this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertNotEmpty( \WP_CLI::$warnings, 'The wrapper behind two hops is warned about.' );
		$this->assertStringContainsString( 'member-content', implode( ' ', \WP_CLI::$warnings ) );
	}

	/**
	 * A pattern carried into a layout that holds a membership wrapper is warned about.
	 *
	 * The strip cannot reach inside a reference, so the wrapper survives into the
	 * layout. Once WooCommerce Memberships is deactivated an unregistered block type
	 * prints its saved inner content as static markup, which puts members-only copy
	 * in front of the non-members the registration layout is for. The warning is the
	 * only place this shape is visible.
	 */
	public function test_extract_gate_layouts_warns_when_a_carried_pattern_holds_a_wrapper() {
		$pattern_id = $this->create_pattern_post(
			'<!-- wp:woocommerce-memberships/member-content -->'
			. '<!-- wp:paragraph --><p>Members only secret.</p><!-- /wp:paragraph -->'
			. '<!-- /wp:woocommerce-memberships/member-content -->'
		);
		$gate_post  = $this->create_gate_post(
			'<!-- wp:woocommerce-memberships/non-member-content -->'
			. sprintf( '<!-- wp:block {"ref":%d} /-->', $pattern_id )
			. '<!-- /wp:woocommerce-memberships/non-member-content -->'
		);

		$this->invoke_private_static( 'extract_gate_layouts', [ $gate_post ] );

		$this->assertNotEmpty( \WP_CLI::$warnings, 'The carried wrapper is warned about.' );
		$warning_text = implode( ' ', \WP_CLI::$warnings );
		$this->assertStringContainsString( (string) $pattern_id, $warning_text, 'The warning names the pattern to edit.' );
		$this->assertStringContainsString( 'member-content', $warning_text, 'The warning names the wrapper found.' );
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
			[ $ac_rules, true, $layouts ]
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'paid access mode will not be activated', $issues[0] );
	}

	/**
	 * A WC rule naming a taxonomy with no terms maps to a rule with an empty value,
	 * which Content_Rules::get_gate_content_rules() drops at read time — so it gates
	 * nothing while still being counted in the summary. The dry run says so before the
	 * operator commits to --live, in both shapes: on its own the gate would cover no
	 * content at all, and alongside a rule that does resolve it is a partial leak.
	 */
	public function test_compute_pre_write_issues_flags_rules_that_select_no_content() {
		$layouts = [
			'registration'  => '<p>Register.</p>',
			'custom_access' => null,
		];

		$whole_taxonomy_only = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[ [ $this->make_rule( 'taxonomy', 'category', [] ) ] ]
		);
		$this->assertSame(
			[
				[
					'slug'  => 'category',
					'value' => [],
				],
			],
			$whole_taxonomy_only,
			'A term-less taxonomy rule still maps to a rule the evaluator will never see.'
		);

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $whole_taxonomy_only, false, $layouts ]
		);
		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its content rules select any content', $issues[0] );
		$this->assertStringContainsString( 'category', $issues[0] );

		$mixed = $this->invoke_private_static(
			'map_rules_to_ac_format',
			[
				[
					$this->make_rule( 'taxonomy', 'category', [] ),
					$this->make_rule( 'post_type', 'post', [] ),
				],
			]
		);

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $mixed, false, $layouts ]
		);
		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( '1 of its 2 content rules select no content', $issues[0] );
		$this->assertStringContainsString( 'category', $issues[0], 'The dropped slug is named so the operator knows what stays ungated.' );
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
				[ $ac_rules, false, $layouts ]
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
				[ $ac_rules, true, $layouts ]
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
	 * Create a synced-pattern (wp_block) post for `core/block` reference tests.
	 *
	 * @param string $content The block markup.
	 * @param string $status  Post status; 'draft' stands in for a pattern the walker
	 *                        must skip.
	 *
	 * @return int The wp_block post ID.
	 */
	private function create_pattern_post( string $content, string $status = 'publish' ): int {
		return self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_content' => $content,
				'post_status'  => $status,
			]
		);
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
			$this->make_rule( 'post_type', 'post', [] ),
			$this->make_rule( 'post_type', Subscription_Lists::CPT, [ 21, 22 ] ),
		];

		$mapped_rules = $this->invoke_private_static( 'map_rules_to_ac_format', [ $rules ] );

		$this->assertCount( 1, $mapped_rules );
		$this->assertSame( 'post_types', $mapped_rules[0]['slug'] );
		$this->assertSame( [ 'post' ], $mapped_rules[0]['value'] );
	}

	/**
	 * A plan restricting only newsletter lists maps to no content rules at all, which
	 * is correct — but it is not the same as a plan that restricts nothing, and the
	 * operator needs to know where it went.
	 */
	public function test_plan_has_newsletter_rules_distinguishes_the_skip_reason() {
		$this->assertTrue(
			$this->invoke_private_static( 'plan_has_newsletter_rules', [ [ $this->make_rule( 'post_type', Subscription_Lists::CPT, [ 21 ] ) ] ] )
		);
		$this->assertFalse(
			$this->invoke_private_static( 'plan_has_newsletter_rules', [ [ $this->make_rule( 'post_type', 'post', [] ) ] ] )
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
	 * error path looked covered. With the terminal check and the answer passed in, a
	 * superseding group under --live is pinned as reaching the prompt rather than the
	 * abort — and a "y" lets the run continue.
	 */
	public function test_confirm_or_error_prompts_when_stdin_is_a_terminal() {
		\WP_CLI::reset();

		$this->invoke_private_static( 'confirm_or_error', [ 'Proceed?', [], true, fn() => "y\n" ] );

		$this->assertContains( 'Proceed? [y/n] ', \WP_CLI::$logs );
	}

	/**
	 * Declining must not report success. This command is meant to be chained with the
	 * WooCommerce Memberships deactivation, and WP_CLI::confirm() exits 0 on "n" — so
	 * `migrate-membership-gates --live && wp plugin deactivate woocommerce-memberships`
	 * would take away the only thing restricting the content, with no gate written at
	 * all. Erroring is what stops the chain.
	 */
	public function test_confirm_or_error_aborts_when_the_operator_declines() {
		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'do not deactivate WooCommerce Memberships' );

		$this->invoke_private_static( 'confirm_or_error', [ 'Proceed?', [], true, fn() => "n\n" ] );
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
	 * possible at all — without reading an answer nothing is there to give.
	 */
	public function test_confirm_or_error_accepts_yes_without_a_terminal() {
		$this->invoke_private_static(
			'confirm_or_error',
			[
				'Proceed?',
				[ 'yes' => true ],
				false,
				function () {
					$this->fail( '--yes must answer the prompt without reading STDIN.' );
				},
			]
		);

		$this->assertTrue( true, 'Reaching here is the assertion: the prompt was never asked.' );
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

	/**
	 * NPPD-2063: a taxonomy rule carrying no term IDs is WooCommerce Memberships'
	 * spelling of "every term of this taxonomy", and it has no faithful Access
	 * Control equivalent.
	 *
	 * Mapping it produces a taxonomy slug with an empty value, which
	 * Content_Rules::get_gate_content_rules() filters out on read — so the rule
	 * vanishes between write and evaluation and the gate fails open over everything
	 * it covered, while verify_migrated_gate() still reports the gate as fine as long
	 * as one other rule survived. Naming these lets the caller refuse the plan
	 * instead of migrating a gate that under-restricts silently.
	 */
	public function test_whole_taxonomy_rules_are_identified_rather_than_mapped_to_an_empty_value() {
		$whole_category_taxonomy = $this->make_rule( 'taxonomy', 'category', [] );
		$named_tags              = $this->make_rule( 'taxonomy', 'post_tag', [ 7, 8 ] );
		$whole_post_type         = $this->make_rule( 'post_type', 'post', [] );

		$this->assertSame(
			[ 'category' ],
			$this->invoke_private_static( 'whole_taxonomy_rule_names', [ [ $whole_category_taxonomy, $named_tags, $whole_post_type ] ] ),
			'Only the term-less taxonomy rule is unbounded: a taxonomy rule naming terms is expressible, and a term-less POST TYPE rule is the legitimate "post_types" shape.'
		);

		$this->assertSame(
			[],
			$this->invoke_private_static( 'whole_taxonomy_rule_names', [ [ $named_tags, $whole_post_type ] ] ),
			'A plan with nothing unbounded migrates normally.'
		);
	}

	/**
	 * NPPD-2063: the mapping still emits the empty value for such a rule, which is
	 * why the caller has to refuse the plan before mapping rather than after.
	 *
	 * Pinning this keeps the reason for the pre-mapping check visible: if the mapping
	 * is ever changed to drop or expand the rule instead, this is where that shows up.
	 */
	public function test_a_whole_taxonomy_rule_still_maps_to_a_value_the_reader_discards() {
		$mapped = $this->invoke_private_static( 'map_rules_to_ac_format', [ [ $this->make_rule( 'taxonomy', 'category', [] ) ] ] );

		$this->assertSame(
			[
				[
					'slug'  => 'category',
					'value' => [],
				],
			],
			$mapped
		);
	}

	/**
	 * A rule set covers another when every one of the other's rules has a same-slug
	 * rule here whose value contains it. Coverage is directional, and an empty
	 * (site-wide) rule set is never a subset — folding a site-wide gate into anything
	 * would hand its whole audience to another plan's product list.
	 */
	public function test_rules_cover_is_directional_set_containment() {
		$category_five           = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$category_five_six       = [
			[
				'slug'  => 'category',
				'value' => [ '5', '6' ],
			],
		];
		$category_plus_all_posts = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];

		$this->assertTrue( $this->invoke_private_static( 'rules_cover', [ $category_five_six, $category_five ] ) );
		$this->assertFalse( $this->invoke_private_static( 'rules_cover', [ $category_five, $category_five_six ] ) );
		$this->assertTrue( $this->invoke_private_static( 'rules_cover', [ $category_plus_all_posts, $category_five ] ) );
		$this->assertFalse( $this->invoke_private_static( 'rules_cover', [ $category_five, $category_plus_all_posts ] ) );
		$this->assertFalse(
			$this->invoke_private_static( 'rules_cover', [ $category_plus_all_posts, [] ] ),
			'An empty rule set gates nothing, so it is never folded into another group.'
		);
	}

	/**
	 * Two rule sets that share a category but each add a different tag overlap without
	 * either covering the other. One gate would need a single product list for two
	 * disjoint entitlements, so they stay separate and the denial risk is reported
	 * rather than merged away.
	 */
	public function test_plan_rule_set_consolidation_flags_overlap_it_cannot_merge() {
		$category_and_tag_seven = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_tag',
				'value' => [ '7' ],
			],
		];
		$category_and_tag_eight = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_tag',
				'value' => [ '8' ],
			],
		];

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ [ $category_and_tag_seven, $category_and_tag_eight ], [ true, true ] ]
		);

		$this->assertSame( [], $plan['absorbed_by'] );
		$this->assertSame( [ [ 0, 1 ] ], $plan['overlaps'] );
	}

	/**
	 * A signup group is never folded into a purchase superset, even when its content is
	 * a strict subset. The merged gate would carry the purchase group's custom_access,
	 * so every registered reader who reaches the signup plan's content for free today
	 * would be asked for a subscription tomorrow.
	 */
	public function test_plan_rule_set_consolidation_does_not_merge_across_the_purchase_boundary() {
		$signup_category_only    = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$purchase_all_posts_too  = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];

		$plan = $this->invoke_private_static(
			'plan_rule_set_consolidation',
			[ [ $signup_category_only, $purchase_all_posts_too ], [ false, true ] ]
		);

		$this->assertSame( [], $plan['absorbed_by'] );
		$this->assertSame( [ [ 0, 1 ] ], $plan['overlaps'], 'The cross-boundary overlap is reported instead.' );
	}

	/**
	 * A gate group of one plan, gating the given terms of one taxonomy.
	 *
	 * @param string $name          Plan name, which becomes part of the gate title.
	 * @param int[]  $term_ids      Category term IDs the plan gates.
	 * @param int    $product_id    The plan's access product.
	 * @param string $taxonomy      Taxonomy slug for the rule.
	 * @param string $access_method 'purchase' or 'signup'.
	 *
	 * @return array[] A single-plan group in group_plans_by_fingerprint()'s shape.
	 */
	private function make_taxonomy_plan_group( string $name, array $term_ids, int $product_id, string $taxonomy = 'category', string $access_method = 'purchase' ): array {
		return $this->make_plan_group(
			$name,
			[
				[
					'slug'  => $taxonomy,
					'value' => array_map( 'strval', $term_ids ),
				],
			],
			$product_id,
			$access_method
		);
	}

	/**
	 * The carve-out repairs an overlap the merge path cannot touch: coverage is decided
	 * per slug, so a whole-post-type plan never "covers" a category plan, and the two
	 * would otherwise be written as gates that deny both plans on the shared posts.
	 * The broad gate excludes the narrow one's content, and the narrow gate takes on
	 * the broad plan's product so its members are not turned away inside the carve-out.
	 */
	public function test_consolidate_plan_groups_repairs_a_cross_slug_overlap_with_a_carve_out() {
		$category  = self::factory()->category->create();
		$broad_id  = $this->create_product( 'subscription' );
		$narrow_id = $this->create_product( 'subscription' );
		// The overlap is decided from the rules alone, so the operator is told how many
		// posts actually carry both — the number that tells a repair from a no-op.
		self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_category' => [ $category ],
			]
		);
		$groups = [
			$this->make_plan_group(
				'All Posts',
				[
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				$broad_id
			),
			$this->make_taxonomy_plan_group( 'Premium Section', [ $category ], $narrow_id ),
		];

		$widened    = [];
		$overlaps   = [];
		$carved     = [];
		$carve_outs = [];
		$merged     = $this->invoke_private_static(
			'consolidate_plan_groups',
			[ $groups, &$widened, &$overlaps, &$carved, &$carve_outs ]
		);

		$this->assertCount( 2, $merged, 'A carve-out keeps both gates; it is the alternative to merging them.' );
		$this->assertSame( [], $overlaps, 'A repaired overlap is no longer put to the operator as unrepairable.' );
		$this->assertSame(
			[ '"Premium Section" out of "All Posts" (1 post(s))' ],
			$carved,
			'The pair is a suspected overlap, so the operator is told how much content the carve-out actually covers.'
		);

		$this->assertSame(
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
				[
					'slug'      => 'category',
					'value'     => [ (string) $category ],
					'exclusion' => true,
				],
			],
			$merged[0][0]['ac_rules'],
			'The broad gate keeps its own rule and gains the narrow gate\'s content as an exclusion.'
		);

		$this->assertSame( 1, $carve_outs[0]['narrow'] );
		$this->assertSame( 0, $carve_outs[0]['broad'] );
		$this->assertSame( [ $broad_id ], $carve_outs[0]['product_ids'], 'The carved-out gate takes on the excluding plan\'s product.' );
	}

	/**
	 * The transfer is only half the promise: the carved-out gate has to end up granting
	 * the products, and a one-time product becomes a rule only with an access length.
	 * The narrow plan here grants on a subscription and has no length of its own, so
	 * carrying the products without the excluding plan's length would drop the one-time
	 * entitlement entirely — and every reader who bought that product would be denied on
	 * the posts the carve-out just took off the broad gate.
	 */
	public function test_a_carve_out_carries_the_access_length_its_products_need() {
		$category        = self::factory()->category->create();
		$one_time_id     = $this->create_product( 'simple' );
		$subscription_id = $this->create_product( 'subscription' );

		$broad  = $this->make_plan_group(
			'All Posts',
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			],
			$one_time_id,
			'purchase',
			0,
			$this->duration( 0, 'forever' )
		);
		$narrow = $this->make_taxonomy_plan_group( 'Premium Section', [ $category ], $subscription_id );

		$carved     = [];
		$carve_outs = [];
		$widened    = [];
		$overlaps   = [];
		$merged     = $this->invoke_private_static(
			'consolidate_plan_groups',
			[ [ $broad, $narrow ], &$widened, &$overlaps, &$carved, &$carve_outs ]
		);

		$carried  = $this->invoke_private_static( 'carried_access_by_group', [ $carve_outs ] );
		$products = $this->invoke_private_static( 'resolve_product_ids', [ $merged[1], $carried[1]['product_ids'] ] );
		$duration = $this->invoke_private_static( 'resolve_group_duration', [ $merged[1], null, $carried[1]['sources'] ] );

		$this->assertSame(
			[
				[
					[
						'slug'  => 'subscription',
						'value' => [ $subscription_id ],
					],
				],
				[
					[
						'slug'  => 'one_time_purchase',
						'value' => [
							'product_ids'    => [ $one_time_id ],
							'duration_value' => 0,
							'duration_unit'  => 'forever',
						],
					],
				],
			],
			$this->invoke_private_static( 'build_access_rules', [ $products, $duration['duration'] ] ),
			'The carved-out gate grants its own subscription and the excluding plan\'s one-time product, for as long as that plan granted it.'
		);
	}

	/**
	 * An exclusion takes content off the broad gate and leaves the carved-out gate as
	 * the only thing over it, so the repair is only sound while that gate holds. Where
	 * it could not be written — a failed creation, or a purchase group with no paid
	 * layout to activate — keeping the exclusion would leave paid content behind free
	 * registration or behind nothing. Dropping it puts the run back where it started.
	 */
	public function test_an_exclusion_is_dropped_when_its_carved_out_gate_does_not_hold() {
		$post_types = [
			'slug'  => 'post_types',
			'value' => [ 'post' ],
		];
		$exclusion  = [
			'slug'      => 'category',
			'value'     => [ '5' ],
			'exclusion' => true,
		];
		$carve_outs = [
			[
				'narrow' => 1,
				'broad'  => 0,
				'rules'  => [ $exclusion ],
			],
		];

		\WP_CLI::reset();
		$this->assertSame(
			[ $post_types ],
			$this->invoke_private_static(
				'drop_unheld_carve_outs',
				[ [ $post_types, $exclusion ], $carve_outs, 0, [ 1 => false ], [ 'All Posts', 'Premium Section' ] ]
			),
			'The broad gate goes on covering the content its counterpart cannot hold.'
		);
		$this->assertStringContainsString( 'the overlap the carve-out repaired is back', strtolower( implode( ' ', \WP_CLI::$warnings ) ) );

		$this->assertSame(
			[ $post_types, $exclusion ],
			$this->invoke_private_static(
				'drop_unheld_carve_outs',
				[ [ $post_types, $exclusion ], $carve_outs, 0, [ 1 => true ], [ 'All Posts', 'Premium Section' ] ]
			),
			'A gate that will enforce keeps the repair.'
		);
	}

	/**
	 * The pre-flight compares what the rules would name against the products the group
	 * holds, rather than asking only whether the rule list came back non-empty. A
	 * one-time product with no access length writes no rule, and a surviving
	 * subscription rule keeps the list non-empty — so without this the entitlement is
	 * dropped behind a rule list that looks healthy.
	 */
	public function test_find_groups_with_unwritten_products_sees_past_a_surviving_subscription_rule() {
		$plan_groups = [ 'mixed' => [ array_merge( $this->make_group_plan( 'purchase' ), [ 'name' => 'Mixed' ] ) ] ];
		$products    = [
			'mixed' => [
				'product_ids'      => [ 42, 36 ],
				'subscription_ids' => [ 42 ],
				'one_time_ids'     => [ 36 ],
			],
		];

		$this->assertSame(
			[ 'Mixed' => [ 36 ] ],
			$this->invoke_private_static(
				'find_groups_with_unwritten_products',
				[ $plan_groups, $products, [ 'mixed' => [ 'duration' => null ] ] ]
			),
			'The one-time product has no rule to appear in, so it is named before anything is written.'
		);
		$this->assertSame(
			[],
			$this->invoke_private_static(
				'find_groups_with_unwritten_products',
				[ $plan_groups, $products, [ 'mixed' => [ 'duration' => $this->duration( 0, 'forever' ) ] ] ]
			),
			'With a length to write, both products are named by a rule.'
		);
	}

	/**
	 * A carve-out copies the narrow group's rules onto the broad gate as exclusions, and
	 * the evaluator answers "does not match" for every post of a slug it cannot resolve.
	 * As an inclusion that is harmless; as an exclusion it reads as "every post is
	 * carved out", and get_post_gates() then skips the broad gate site-wide — the whole
	 * paywall, feeds included. The rule has to be checked before it changes role.
	 */
	public function test_carve_out_direction_refuses_a_narrow_rule_the_evaluator_cannot_resolve() {
		$post_types = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$dead_taxonomy = [
			[
				'slug'  => 'taxonomy_from_a_deactivated_plugin',
				'value' => [ '5' ],
			],
		];

		$this->assertNull( $this->invoke_private_static( 'carve_out_direction', [ $post_types, $dead_taxonomy ] ) );
		$this->assertNull( $this->invoke_private_static( 'carve_out_direction', [ $dead_taxonomy, $post_types ] ) );
	}

	/**
	 * Two registration plans have nothing for a carve-out to repair: either gate admits
	 * any logged-in reader, so neither can lock the other's members out, and a signup
	 * plan carries no access products to transfer. Writing one would rewrite a gate's
	 * content rules, ask the operator to approve it, and describe a paid stake that
	 * cannot exist.
	 */
	public function test_consolidate_plan_groups_does_not_carve_two_registration_groups() {
		$category = self::factory()->category->create();

		\WP_CLI::reset();
		$widened    = [];
		$overlaps   = [];
		$carved     = [];
		$carve_outs = [];
		$this->invoke_private_static(
			'consolidate_plan_groups',
			[
				[
					$this->make_plan_group(
						'All Posts Signup',
						[
							[
								'slug'  => 'post_types',
								'value' => [ 'post' ],
							],
						],
						0,
						'signup'
					),
					$this->make_taxonomy_plan_group( 'Section Signup', [ $category ], 0, 'category', 'signup' ),
				],
				&$widened,
				&$overlaps,
				&$carved,
				&$carve_outs,
			]
		);

		$this->assertSame( [], $carved, 'There is nothing to repair, so nothing is rewritten.' );
		$this->assertSame( [], $overlaps, 'Neither gate can deny the other\'s members, so the operator is not asked to answer for it.' );
		$warning = implode( ' ', \WP_CLI::$warnings );
		$this->assertStringContainsString( 'no reader is denied at either gate', $warning );
		$this->assertStringNotContainsString( 'access products', $warning );
	}

	/**
	 * A carve-out excuses the narrow group's content from the broad gate, so a signup
	 * group carved out of a purchase gate — or the reverse — hands content a reader
	 * reaches today by registering to a gate that demands a subscription. Absorption
	 * refuses the same crossing, for the same reason.
	 */
	public function test_consolidate_plan_groups_will_not_carve_across_the_purchase_boundary() {
		$signup_wide = $this->make_plan_group(
			'Registration Wall',
			[
				[
					'slug'  => 'post_types',
					'value' => [ 'post' ],
				],
			],
			0,
			'signup'
		);
		$paid_tag    = $this->make_plan_group(
			'Premium Tag',
			[
				[
					'slug'  => 'post_tag',
					'value' => [ '32' ],
				],
			],
			$this->create_product( 'subscription' )
		);

		\WP_CLI::reset();
		$widened         = [];
		$overlaps        = [];
		$carved          = [];
		$carved_products = [];
		$this->invoke_private_static(
			'consolidate_plan_groups',
			[ [ $signup_wide, $paid_tag ], &$widened, &$overlaps, &$carved, &$carved_products ]
		);

		$this->assertSame( [], $carved, 'The pair is left alone rather than carved.' );
		$this->assertSame( [ '"Registration Wall" against "Premium Tag"' ], $overlaps );
	}

	/**
	 * Two rules of one slug cannot live on a gate as an inclusion and an exclusion: the
	 * wizard renders one row per slug and writes an edit to every rule carrying it, so
	 * the gate would be uneditable afterwards. Nested same-taxonomy tiers therefore
	 * stay on the merge path, where they are already handled.
	 */
	public function test_carve_out_direction_refuses_two_rules_of_the_same_slug() {
		// The broad side is decidable here — it is the one gating whole post types — so
		// only the shared `category` slug stands between this pair and a carve-out.
		$this->assertNull(
			$this->invoke_private_static(
				'carve_out_direction',
				[
					[
						[
							'slug'  => 'post_types',
							'value' => [ 'post' ],
						],
						[
							'slug'  => 'category',
							'value' => [ '5' ],
						],
					],
					[
						[
							'slug'  => 'category',
							'value' => [ '9' ],
						],
					],
				]
			)
		);
	}

	/**
	 * `specific_posts` is an inclusion override evaluated ahead of exclusions, so a
	 * carve-out cannot remove a post it names — on either side of the pair.
	 */
	public function test_carve_out_direction_refuses_specific_posts_on_either_side() {
		$post_types = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$specific   = [
			[
				'slug'  => 'specific_posts',
				'value' => [ '77' ],
			],
		];

		$this->assertNull( $this->invoke_private_static( 'carve_out_direction', [ $post_types, $specific ] ) );
		$this->assertNull( $this->invoke_private_static( 'carve_out_direction', [ $specific, $post_types ] ) );
	}

	/**
	 * The post-type side hosts the exclusion, whichever order the pair arrives in, and
	 * a pair with post types on both sides or neither has no decidable broad side.
	 */
	public function test_carve_out_direction_names_the_post_type_side() {
		$post_types = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$category   = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$tag        = [
			[
				'slug'  => 'post_tag',
				'value' => [ '9' ],
			],
		];

		$this->assertSame( 'a', $this->invoke_private_static( 'carve_out_direction', [ $post_types, $category ] ) );
		$this->assertSame( 'b', $this->invoke_private_static( 'carve_out_direction', [ $category, $post_types ] ) );
		$this->assertNull( $this->invoke_private_static( 'carve_out_direction', [ $category, $tag ] ) );
		$this->assertNull( $this->invoke_private_static( 'carve_out_direction', [ $post_types, $post_types ] ) );
	}

	/**
	 * Only the overlap warnings and their ordering read a plan's member count, and
	 * `get_memberships_count()` pulls every active membership ID into PHP to produce it.
	 * So it is resolved on first read rather than while grouping, and cached per plan —
	 * two descriptors on one plan, read twice, cost a single lookup.
	 */
	public function test_plan_member_count_is_resolved_lazily_and_cached_per_plan() {
		require_once __DIR__ . '/../../mocks/wc-memberships-plan-lookup-mock.php';

		global $newspack_mock_counted_plans, $newspack_mock_plan_lookups;
		$newspack_mock_counted_plans = [ 4242 => new \Newspack_Mock_Counted_Membership_Plan( 4242, 37 ) ];
		$newspack_mock_plan_lookups  = [];

		$reflected_member_counts = new \ReflectionProperty( Membership_Gates_Migration::class, 'member_counts' );
		$reflected_member_counts->setAccessible( true );
		$reflected_member_counts->setValue( null, [] );

		$two_plans_one_membership_plan = [ [ 'pid' => 4242 ], [ 'pid' => 4242 ] ];

		$this->assertSame( 74, $this->invoke_private_static( 'group_member_count', [ $two_plans_one_membership_plan ] ) );
		$this->assertSame( 74, $this->invoke_private_static( 'group_member_count', [ $two_plans_one_membership_plan ] ) );
		$this->assertSame( [ 4242 ], $newspack_mock_plan_lookups, 'The plan is looked up once, however often the count is read.' );

		$newspack_mock_plan_lookups = [];
		$this->assertSame(
			5,
			$this->invoke_private_static(
				'group_member_count',
				[
					[
						[
							'pid'          => 4242,
							'member_count' => 5,
						],
					],
				] 
			)
		);
		$this->assertSame( [], $newspack_mock_plan_lookups, 'A descriptor carrying the count is taken at its word.' );
	}

	/**
	 * A gate group of one plan with the given content rules.
	 *
	 * @param string     $name              Plan name, which becomes the gate title.
	 * @param array[]    $ac_rules          The plan's AC-format content rules.
	 * @param int        $product_id        The plan's access product, or 0 for a signup plan.
	 * @param string     $access_method     'purchase' or 'signup'.
	 * @param int        $member_count      Active memberships on the plan.
	 * @param array|null $one_time_duration The plan's own access length, as
	 *                                      derive_one_time_duration() reads it.
	 *
	 * @return array[] A single-plan group in group_plans_by_fingerprint()'s shape.
	 */
	private function make_plan_group( string $name, array $ac_rules, int $product_id, string $access_method = 'purchase', int $member_count = 0, ?array $one_time_duration = null ): array {
		return [
			[
				'pid'               => 0,
				'name'              => $name,
				'member_count'      => $member_count,
				'access_method'     => $access_method,
				'ac_rules'          => $ac_rules,
				'product_ids'       => $product_id ? [ $product_id ] : [],
				'one_time_duration' => $one_time_duration,
			],
		];
	}

	/**
	 * A plan gating a parent category and one gating its child are the commonest shape
	 * of this bug. The evaluator expands a term to its descendants
	 * (Content_Restriction_Control::expand_hierarchical_terms()), so the parent plan
	 * already gates the child's posts — comparing the stored term IDs alone would read
	 * them as disjoint and leave two gates with disjoint product lists.
	 *
	 * The third plan names both terms. Expansion makes it and the parent-only plan cover
	 * each other, which strict coverage would refuse to absorb in either direction, so
	 * the tie has to break rather than leave the two split.
	 */
	public function test_consolidate_plan_groups_absorbs_a_child_category_into_its_parent() {
		$parent_term = self::factory()->category->create();
		$child_term  = self::factory()->category->create( [ 'parent' => $parent_term ] );
		$child_product  = $this->create_product( 'subscription' );
		$parent_product = $this->create_product( 'subscription' );
		$both_product   = $this->create_product( 'subscription' );

		\WP_CLI::reset();
		$merged = $this->invoke_private_static(
			'consolidate_plan_groups',
			[
				[
					$this->make_taxonomy_plan_group( 'Child Only', [ $child_term ], $child_product ),
					$this->make_taxonomy_plan_group( 'Parent Wide', [ $parent_term ], $parent_product ),
					$this->make_taxonomy_plan_group( 'Parent And Child', [ $parent_term, $child_term ], $both_product ),
				],
			]
		);

		$this->assertCount( 1, $merged, 'All three gate the same content once the parent term is expanded.' );
		// Folding widens paid access, and --live gates that behind one confirmation built
		// from these announcements. A silent fold would widen access with nothing to answer.
		$this->assertCount( 2, \WP_CLI::$warnings, 'Each fold is announced to the operator.' );
		$this->assertStringContainsString( 'Child Only', implode( ' ', \WP_CLI::$warnings ) );
		$this->assertStringContainsString( 'Parent And Child', implode( ' ', \WP_CLI::$warnings ) );
		$this->assertSame(
			[ $parent_product, $child_product, $both_product ],
			$this->invoke_private_static( 'resolve_product_ids', [ $merged[0] ] )['product_ids'],
			'Every plan\'s product lands on the one gate, so no plan\'s members are denied.'
		);
	}

	/**
	 * The fold itself, not just the decision: three nested tiers collapse to one group
	 * carrying all three plans, and that group's products are the union of theirs. This
	 * is the shape the gate is built from, and it is where an absorbed group folded into
	 * a root that no longer seeds a gate would fatal on a missing key.
	 */
	public function test_consolidate_plan_groups_folds_nested_tiers_into_one_group() {
		// Real, unrelated categories: the nesting here is in the rule values, not in the
		// term hierarchy, and a bare term ID would collide with another case's fixtures
		// in the descendant memo.
		$first  = self::factory()->category->create();
		$second = self::factory()->category->create();
		$third  = self::factory()->category->create();

		$basic  = $this->create_product( 'subscription' );
		$plus   = $this->create_product( 'subscription' );
		$top    = $this->create_product( 'subscription' );
		$groups = [
			$this->make_taxonomy_plan_group( 'Basic', [ $first ], $basic ),
			$this->make_taxonomy_plan_group( 'Plus', [ $first, $second ], $plus ),
			$this->make_taxonomy_plan_group( 'Top', [ $first, $second, $third ], $top ),
		];

		$merged = $this->invoke_private_static( 'consolidate_plan_groups', [ $groups ] );

		$this->assertCount( 1, $merged, 'Three nested tiers gate one another\'s content, so one gate covers them.' );
		$this->assertSame(
			[ 'Top', 'Basic', 'Plus' ],
			array_column( $merged[0], 'name' ),
			'The widest tier seeds the group and the narrower ones fold into it.'
		);
		$this->assertSame(
			[ $top, $basic, $plus ],
			$this->invoke_private_static( 'resolve_product_ids', [ $merged[0] ] )['product_ids'],
			'The merged gate carries every folded plan\'s product, which is what keeps their members admitted.'
		);
	}

	/**
	 * An overlap that neither merging nor a carve-out can repair is handed back to the
	 * caller, not just warned about. It is the denial this command exists to prevent,
	 * and gates stay inert until Memberships is deactivated — so a run that writes the
	 * split looks clean and the warning is long gone by the time a reader is turned
	 * away. The caller puts it to the pre-flight prompt instead, and the message
	 * carries each side's member count so the operator can size the risk.
	 *
	 * Two category sets that share a term without either containing the other: they
	 * overlap, neither covers the other, and a carve-out would need both an inclusion
	 * and an exclusion of `category` on one gate, which the wizard cannot edit.
	 */
	public function test_consolidate_plan_groups_hands_unmergeable_overlaps_to_the_caller() {
		$shared        = self::factory()->category->create();
		$news          = $this->make_plan_group(
			'Newsroom',
			[
				[
					'slug'  => 'category',
					'value' => array_map( 'strval', [ $shared, self::factory()->category->create() ] ),
				],
			],
			$this->create_product( 'subscription' ),
			'purchase',
			120
		);
		$investigations = $this->make_plan_group(
			'Investigations',
			[
				[
					'slug'  => 'category',
					'value' => array_map( 'strval', [ $shared, self::factory()->category->create() ] ),
				],
			],
			$this->create_product( 'subscription' )
		);

		\WP_CLI::reset();
		$widened  = [];
		$overlaps = [];
		$merged   = $this->invoke_private_static( 'consolidate_plan_groups', [ [ $news, $investigations ], &$widened, &$overlaps ] );

		$this->assertCount( 2, $merged, 'Neither rule set covers the other, so one gate cannot carry both product lists.' );
		$this->assertSame( [ '"Newsroom" against "Investigations"' ], $overlaps );
		$this->assertStringContainsString( '120 active member(s)', implode( ' ', \WP_CLI::$warnings ) );
	}

	/**
	 * The partition must not move with the order the plans arrive in. Plans are read ID
	 * ascending, so a plan published between the approved dry run and the --live run
	 * reshuffles the input — and with a positional tie-break that would silently change
	 * which gate an absorbed plan's products land on, and therefore who is denied.
	 */
	public function test_consolidate_plan_groups_partition_does_not_depend_on_input_order() {
		$shared    = self::factory()->category->create();
		$culture   = self::factory()->category->create();
		$investing = self::factory()->category->create();

		// Two covering roots that tie on rule count and cover neither each other nor the
		// same content. Which one takes 'Narrow' — and therefore whose product list its
		// members end up behind — is the whole question.
		$narrow  = $this->make_taxonomy_plan_group( 'Narrow', [ $shared ], $this->create_product( 'subscription' ) );
		$root_a  = $this->make_taxonomy_plan_group( 'Root A', [ $shared, $culture ], $this->create_product( 'subscription' ) );
		$root_b  = $this->make_taxonomy_plan_group( 'Root B', [ $shared, $investing ], $this->create_product( 'subscription' ) );
		$titles  = function ( array $groups ) {
			$gate_titles = array_map(
				fn( $group ) => $this->invoke_private_static( 'gate_title', [ $group ] ),
				$this->invoke_private_static( 'consolidate_plan_groups', [ $groups ] )
			);
			sort( $gate_titles );
			return $gate_titles;
		};

		$this->assertSame(
			$titles( [ $narrow, $root_a, $root_b ] ),
			$titles( [ $root_b, $root_a, $narrow ] ),
			'Equally sized covering roots tie on rule count; the tie breaks on what they gate, not on where they sit.'
		);
	}

	/**
	 * A signup fold widens no paid access — neither gate carries an access product — so
	 * the operator is not asked to approve a stake that does not exist. The prompt is
	 * built from these lines, and a wrong stake is as likely to abort a harmless merge
	 * as to wave a real one through.
	 */
	public function test_consolidate_plan_groups_does_not_claim_paid_widening_for_a_signup_fold() {
		$parent_term = self::factory()->category->create();
		$child_term  = self::factory()->category->create( [ 'parent' => $parent_term ] );

		\WP_CLI::reset();
		$this->invoke_private_static(
			'consolidate_plan_groups',
			[
				[
					$this->make_plan_group(
						'Child Signup',
						[
							[
								'slug'  => 'category',
								'value' => [ (string) $child_term ],
							],
						],
						0,
						'signup' 
					),
					$this->make_plan_group(
						'Parent Signup',
						[
							[
								'slug'  => 'category',
								'value' => [ (string) $parent_term ],
							],
						],
						0,
						'signup' 
					),
				],
			]
		);

		$warning = implode( ' ', \WP_CLI::$warnings );
		$this->assertStringContainsString( 'Consolidating "Child Signup" into "Parent Signup"', $warning );
		$this->assertStringNotContainsString( 'access products', $warning );
	}

	/**
	 * Overlap detection is deliberately conservative, because a miss costs a reader
	 * their access while a false positive costs one warning. A whole-post-type rule
	 * therefore counts as overlapping any narrower rule, which cannot be resolved
	 * without querying the posts. Rules on different taxonomies, with no post-type rule
	 * to connect them, do not.
	 */
	public function test_rule_sets_overlap_errs_towards_reporting() {
		$all_posts      = [
			[
				'slug'  => 'post_types',
				'value' => [ 'post' ],
			],
		];
		$category_five  = [
			[
				'slug'  => 'category',
				'value' => [ '5' ],
			],
		];
		$specific_posts = [
			[
				'slug'  => 'specific_posts',
				'value' => [ '42' ],
			],
		];
		$tag_seven      = [
			[
				'slug'  => 'post_tag',
				'value' => [ '7' ],
			],
		];

		$this->assertTrue( $this->invoke_private_static( 'rule_sets_overlap', [ $all_posts, $category_five ] ) );
		$this->assertTrue( $this->invoke_private_static( 'rule_sets_overlap', [ $all_posts, $specific_posts ] ) );
		$this->assertFalse( $this->invoke_private_static( 'rule_sets_overlap', [ $category_five, $tag_seven ] ) );
	}

	/**
	 * A purchase group for which no paid access rule can be built is named so the
	 * caller can refuse the live run. Its gate would activate paid access carrying no
	 * access rule, asking for no purchase at all — the one failure that is worse than
	 * not migrating, because it publishes the content to every registered reader.
	 *
	 * The last group is the reason the guard asks build_access_rules() rather than
	 * testing the product list: it holds a product that reached neither paid bucket, so
	 * a product-list test would pass it and the gate would publish open.
	 */
	public function test_find_paid_groups_without_access_rules_names_only_the_open_gates() {
		$named             = function ( string $access_method, string $name ) {
			return [ array_merge( $this->make_group_plan( $access_method ), [ 'name' => $name ] ) ];
		};
		$plan_groups       = [
			'paid-empty'      => $named( 'purchase', 'Paid Without Products' ),
			'paid-ok'         => $named( 'purchase', 'Paid With Products' ),
			'signup-only'     => $named( 'signup', 'Signup Only' ),
			'paid-unbucketed' => $named( 'purchase', 'Paid With Unclassifiable Product' ),
		];
		$products_by_group = [
			'paid-empty'      => [
				'product_ids'      => [],
				'subscription_ids' => [],
				'one_time_ids'     => [],
			],
			'paid-ok'         => [
				'product_ids'      => [ 42 ],
				'subscription_ids' => [ 42 ],
				'one_time_ids'     => [],
			],
			'signup-only'     => [
				'product_ids'      => [],
				'subscription_ids' => [],
				'one_time_ids'     => [],
			],
			'paid-unbucketed' => [
				'product_ids'      => [ 77 ],
				'subscription_ids' => [],
				'one_time_ids'     => [],
			],
		];
		$no_duration       = [ 'duration' => null ];

		$this->assertSame(
			[ 'Paid Without Products', 'Paid With Unclassifiable Product' ],
			$this->invoke_private_static(
				'find_paid_groups_without_access_rules',
				[
					$plan_groups,
					$products_by_group,
					array_fill_keys( array_keys( $plan_groups ), $no_duration ),
				]
			),
			'A signup group writes no paid rule to begin with; the two purchase groups that would emit no access rule are named.'
		);
	}
}
