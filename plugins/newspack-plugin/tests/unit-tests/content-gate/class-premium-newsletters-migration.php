<?php
/**
 * Tests for the migrate-premium-newsletters CLI (NPPD-2079).
 *
 * WooCommerce Memberships is absent from this harness, so plan objects cannot be
 * built. These tests cover the helpers that do not need one: rule extraction,
 * fingerprinting, the purchase rule, auto-signup derivation, and gate
 * verification. Grouping and product consolidation depend on
 * WC_Memberships_Membership_Plan and are exercised end-to-end against real
 * WooCommerce Memberships instead.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\CLI\Premium_Newsletters_Migration;
use Newspack\Newsletters\Subscription_Lists;

// The trait has to be defined before the class that uses it. Production load order
// comes from CLI\Initializer; a test requiring the class directly supplies it here.
require_once dirname( __DIR__, 3 ) . '/includes/cli/trait-one-time-purchase-migration.php';
require_once dirname( __DIR__, 3 ) . '/includes/cli/class-premium-newsletters-migration.php';

/**
 * Tests for the migrate-premium-newsletters helpers.
 */
class Test_Premium_Newsletters_Migration extends \WP_UnitTestCase {

	/**
	 * A newsletter list post ID.
	 *
	 * @var int
	 */
	private $list_a;

	/**
	 * A second newsletter list post ID.
	 *
	 * @var int
	 */
	private $list_b;

	/**
	 * A published WooCommerce subscription product post ID.
	 *
	 * A subscription rather than a plain product because the payload builder now
	 * routes a group's products by kind: the fixtures below were written when every
	 * product became a subscription rule, and registering the mock as a subscription
	 * is what keeps each of them exercising that rule.
	 *
	 * @var int
	 */
	private $product;

	/**
	 * The mock product database as it stood before this test, restored afterwards.
	 *
	 * The mock builder writes into a global keyed by product ID, and the
	 * IDs here come from the post factory — so without this a fixture could land on
	 * an ID another test class hardcodes, and outlive the test that registered it.
	 *
	 * @var array|null
	 */
	private $original_products_database;

	/**
	 * Load the mocks once for the class. Deferred to set_up_before_class() rather
	 * than a file-scope require because PHPUnit loads every test file before the run
	 * starts: a file-scope require would define Subscription_List and
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
	 * Register the list post type, create two lists, and reset the WP_CLI mock's
	 * recorded output so assertions in one test cannot see another's messages.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type( Subscription_Lists::CPT, [ 'public' => false ] );
		$this->list_a = self::factory()->post->create( [ 'post_type' => Subscription_Lists::CPT ] );
		$this->list_b = self::factory()->post->create( [ 'post_type' => Subscription_Lists::CPT ] );
		global $products_database;
		$this->original_products_database = $products_database;
		$this->product                    = $this->create_product( 'subscription' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw argv, kept verbatim so tear_down() can restore it.
		$this->original_argv = $_SERVER['argv'] ?? null;
		\WP_CLI::reset();
	}

	/**
	 * Unregister the list post type so it does not leak into other test classes, and
	 * put back the argument vector the bare-flag tests overwrite.
	 */
	public function tear_down() {
		global $products_database;
		$products_database = $this->original_products_database;
		if ( null === $this->original_argv ) {
			unset( $_SERVER['argv'] );
		} else {
			$_SERVER['argv'] = $this->original_argv;
		}
		unregister_post_type( Subscription_Lists::CPT );
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
		$reflected_method = new \ReflectionMethod( Premium_Newsletters_Migration::class, $method_name );
		$reflected_method->setAccessible( true );
		return $reflected_method->invoke( null, ...$arguments );
	}

	/**
	 * Register a WooCommerce mock product for a post the factory already made.
	 *
	 * The migration asks wc_get_product() which rule can carry a product, and a post
	 * the mock database has never heard of comes back as false — which classifies as
	 * one-time. Registering the mock is what lets a fixture say which kind it is.
	 *
	 * @param int    $product_id The product or variation post ID.
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
	 * @param string $type      The WooCommerce product type.
	 * @param string $post_type The WordPress post type, for variation fixtures.
	 * @param array  $post_args Extra arguments for the post factory.
	 *
	 * @return int The product post ID.
	 */
	private function create_product( string $type, string $post_type = 'product', array $post_args = [] ): int {
		$product_id = self::factory()->post->create( array_merge( [ 'post_type' => $post_type ], $post_args ) );
		return $this->register_product_type( $product_id, $type );
	}

	/**
	 * Build a minimal stand-in for a WC_Memberships_Membership_Plan_Rule.
	 *
	 * The extraction only calls get_content_type_name() and get_object_ids(), so
	 * WooCommerce Memberships is not needed to exercise it.
	 *
	 * @param string $content_type_name The WC content type name.
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
	 * Build a plan-group descriptor carrying just the access method
	 * group_requires_purchase() inspects.
	 *
	 * @param string $access_method The WCM plan access method.
	 *
	 * @return array
	 */
	private function make_group_plan( string $access_method ): array {
		return [
			'pid'           => 0,
			'name'          => 'Plan',
			'access_method' => $access_method,
			'list_ids'      => [],
			'product_ids'   => [],
		];
	}

	/**
	 * Only newsletter-list rules contribute list IDs. A plan restricting posts and
	 * categories alongside its lists must not drag those object IDs into the
	 * premium gate, where they would be read as list IDs.
	 */
	public function test_extract_list_ids_ignores_non_newsletter_rules() {
		$rules = [
			$this->make_rule( 'post', [ 11, 12 ] ),
			$this->make_rule( Subscription_Lists::CPT, [ 21, 22 ] ),
			$this->make_rule( 'category', [ 31 ] ),
		];

		$this->assertSame( [ 21, 22 ], $this->invoke_private_static( 'extract_list_ids', [ $rules ] ) );
	}

	/**
	 * A plan can carry several newsletter-list rules. Their IDs merge into one set,
	 * deduplicated, because the gate holds a single 'newsletters' rule.
	 */
	public function test_extract_list_ids_merges_and_dedupes_across_rules() {
		$rules = [
			$this->make_rule( Subscription_Lists::CPT, [ 21, 22 ] ),
			$this->make_rule( Subscription_Lists::CPT, [ 22, 23 ] ),
		];

		$this->assertSame( [ 21, 22, 23 ], $this->invoke_private_static( 'extract_list_ids', [ $rules ] ) );
	}

	/**
	 * A plan with no newsletter-list rule yields nothing, which is what marks it as
	 * out of scope for this command.
	 */
	public function test_extract_list_ids_returns_empty_without_newsletter_rules() {
		$rules = [ $this->make_rule( 'post', [ 11 ] ) ];

		$this->assertSame( [], $this->invoke_private_static( 'extract_list_ids', [ $rules ] ) );
	}

	/**
	 * Two plans restricting the same lists must share a gate however WC ordered the
	 * rules, so the fingerprint is order-independent.
	 */
	public function test_compute_list_fingerprint_is_independent_of_order() {
		$this->assertSame(
			$this->invoke_private_static( 'compute_list_fingerprint', [ [ 21, 22, 23 ] ] ),
			$this->invoke_private_static( 'compute_list_fingerprint', [ [ 23, 21, 22 ] ] )
		);
	}

	/**
	 * Plans restricting different lists must not collapse into one gate.
	 */
	public function test_compute_list_fingerprint_differs_for_different_list_sets() {
		$this->assertNotSame(
			$this->invoke_private_static( 'compute_list_fingerprint', [ [ 21, 22 ] ] ),
			$this->invoke_private_static( 'compute_list_fingerprint', [ [ 21, 23 ] ] )
		);
	}

	/**
	 * A group is purchase-gated only when every plan requires a purchase. The two
	 * gate modes AND for a logged-in reader, while WooCommerce Memberships grants
	 * access from either plan, so a mixed group stays registration-gated and the
	 * free-signup plan's members keep their lists at cutover.
	 */
	public function test_group_requires_purchase_only_when_every_plan_is_purchase() {
		$all_purchase = [ $this->make_group_plan( 'purchase' ), $this->make_group_plan( 'purchase' ) ];
		$mixed        = [ $this->make_group_plan( 'purchase' ), $this->make_group_plan( 'signup' ) ];
		$all_signup   = [ $this->make_group_plan( 'signup' ) ];

		$this->assertTrue( $this->invoke_private_static( 'group_requires_purchase', [ $all_purchase ] ) );
		$this->assertFalse( $this->invoke_private_static( 'group_requires_purchase', [ $mixed ] ) );
		$this->assertFalse( $this->invoke_private_static( 'group_requires_purchase', [ $all_signup ] ) );
	}

	/**
	 * Build a plan-group descriptor of the shape group_plans_by_lists() produces.
	 *
	 * @param string     $access_method     The WCM plan access method.
	 * @param int[]      $product_ids       The plan's product IDs.
	 * @param int[]      $list_ids          The lists the plan restricts.
	 * @param string     $name              The plan name.
	 * @param array|null $one_time_duration The plan's own access length, as
	 *                                      derive_one_time_duration() reads it. Null
	 *                                      stands for a plan whose access ends on a
	 *                                      fixed calendar date.
	 *
	 * @return array
	 */
	private function make_payload_plan( string $access_method, array $product_ids = [], array $list_ids = [], string $name = 'Plan', ?array $one_time_duration = null ): array {
		return [
			'pid'               => 0,
			'name'              => $name,
			'access_method'     => $access_method,
			'list_ids'          => $list_ids,
			'product_ids'       => $product_ids,
			'one_time_duration' => $one_time_duration,
		];
	}

	/**
	 * A premium newsletter gate carries one content rule, so 'any' and 'all' agree on
	 * it today — but the match mode is written, not defaulted, and 'all' would restrict
	 * only posts on every listed list if a second rule ever joined. Pin 'any'.
	 */
	public function test_build_gate_payload_matches_content_rules_on_any() {
		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'signup', [], [ $this->list_a ] ) ] ]
		);

		$this->assertSame( 'any', $payload['content_rules_match'] );
		$this->assertSame(
			[
				[
					'slug'  => 'newsletters',
					'value' => [ (string) $this->list_a ],
				],
			],
			$payload['content_rules']
		);
	}

	/**
	 * Registration mode is activated for every migrated group, purchase or signup:
	 * every plan that reaches here grants membership to an account, so requiring one
	 * never shuts out a reader the plan admitted.
	 */
	public function test_build_gate_payload_always_activates_registration() {
		$signup   = $this->invoke_private_static( 'build_gate_payload', [ [ $this->make_payload_plan( 'signup' ) ] ] );
		$purchase = $this->invoke_private_static( 'build_gate_payload', [ [ $this->make_payload_plan( 'purchase', [ $this->product ] ) ] ] );

		$this->assertSame( [ 'active' => true ], $signup['registration'] );
		$this->assertSame( [ 'active' => true ], $purchase['registration'] );
	}

	/**
	 * A group holding both a purchase plan and a signup plan migrates
	 * registration-only. The gate's two modes AND for a logged-in reader, so writing
	 * the purchase plan's products here would demand a subscription from the signup
	 * plan's members, who WooCommerce Memberships admitted for free.
	 */
	public function test_build_gate_payload_keeps_a_mixed_group_registration_only() {
		$group = [
			$this->make_payload_plan( 'purchase', [ $this->product ], [ $this->list_a ], 'Paid' ),
			$this->make_payload_plan( 'signup', [], [ $this->list_a ], 'Free' ),
		];

		$payload = $this->invoke_private_static( 'build_gate_payload', [ $group ] );

		$this->assertFalse( $payload['has_purchase'] );
		$this->assertSame( 'signup', $payload['access_type'] );
		$this->assertFalse( $payload['custom_access']['active'] );
		$this->assertSame( [], $payload['custom_access']['access_rules'] );
		$this->assertSame( 'Paid | Free', $payload['title'] );
	}

	/**
	 * Both of the checks that would otherwise catch an unenforced paywall sit behind
	 * the group requiring a purchase, which a mixed group does not — so the paid
	 * plan's requirement is dropped with nothing said. Naming the plan is the only
	 * signal the operator gets that lists someone was paying for stop being paid-only
	 * at cutover.
	 */
	public function test_build_gate_payload_names_the_paid_plan_a_mixed_group_drops() {
		$group = [
			$this->make_payload_plan( 'purchase', [ $this->product ], [ $this->list_a ], 'Paid' ),
			$this->make_payload_plan( 'signup', [], [ $this->list_a ], 'Free' ),
		];

		$payload = $this->invoke_private_static( 'build_gate_payload', [ $group ] );

		$this->assertSame( [ 'Paid' ], $payload['dropped_paywalls'] );
	}

	/**
	 * A group whose plans all require a purchase keeps its paywall, so there is
	 * nothing to report. Firing here would warn on every ordinary paid gate.
	 */
	public function test_build_gate_payload_reports_no_dropped_paywall_for_a_purchase_group() {
		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $this->product ], [ $this->list_a ], 'Paid' ) ] ]
		);

		$this->assertSame( [], $payload['dropped_paywalls'] );
	}

	/**
	 * A purchase group writes its products as a single subscription rule, in the
	 * grouped shape Access_Rules::normalize_rules() expects.
	 */
	public function test_build_gate_payload_writes_a_subscription_rule_for_a_purchase_group() {
		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $this->product ], [ $this->list_a ] ) ] ]
		);

		$this->assertTrue( $payload['custom_access']['active'] );
		$this->assertSame(
			[
				[
					[
						'slug'  => 'subscription',
						'value' => [ $this->product ],
					],
				],
			],
			$payload['custom_access']['access_rules']
		);
	}

	/**
	 * A garbage `_product_ids` entry normalizes to 0, and a rule value of 0 grants the
	 * gate to every paying reader — WC_Subscription::has_product() matches a line item
	 * whose variation_id is 0, which every simple-product line item's is. It must never
	 * reach access_rules. A negative ID is dropped for the same reason: absint() would
	 * have turned it into a different, real product ID.
	 */
	public function test_build_gate_payload_never_writes_a_non_positive_product_id() {
		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $this->product, 0, -7 ], [ $this->list_a ] ) ] ]
		);

		$this->assertSame( [ $this->product ], $payload['product_ids'] );
		$this->assertSame( [ $this->product ], $payload['custom_access']['access_rules'][0][0]['value'] );
		$this->assertSame( [ 0, -7 ], $payload['dropped_product_ids']['invalid'] );
	}

	/**
	 * A deleted product leaves a rule nothing can satisfy. That fails safe, but it
	 * restricts readers the plan admitted, so the ID is dropped and reported rather
	 * than written and forgotten.
	 */
	public function test_build_gate_payload_never_writes_a_deleted_product_id() {
		$deleted = self::factory()->post->create( [ 'post_type' => 'product' ] );
		wp_delete_post( $deleted, true );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $this->product, $deleted ], [ $this->list_a ] ) ] ]
		);

		$this->assertSame( [ $this->product ], $payload['product_ids'] );
		$this->assertSame( [ $this->product ], $payload['custom_access']['access_rules'][0][0]['value'] );
		$this->assertSame( [ $deleted ], $payload['dropped_product_ids']['unresolvable'] );
	}

	/**
	 * A plan granting membership on purchase of a variation must migrate to a rule
	 * naming that variation. WC_Subscription::has_product() matches a line item on
	 * either product_id or variation_id, so the variation ID admits exactly the
	 * readers the plan admitted.
	 *
	 * This fails under both alternatives: dropping the variation leaves access_rules
	 * empty, and substituting the parent product would also admit holders of its
	 * sibling variations, whom the plan never granted.
	 */
	public function test_build_gate_payload_keeps_a_variation_id_rather_than_its_parent() {
		$parent    = $this->create_product( 'variable-subscription' );
		$variation = $this->create_product( 'subscription_variation', 'product_variation', [ 'post_parent' => $parent ] );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $variation ], [ $this->list_a ] ) ] ]
		);

		$this->assertSame( [ $variation ], $payload['product_ids'] );
		$this->assertSame( [ $variation ], $payload['custom_access']['access_rules'][0][0]['value'] );
		$this->assertSame( [ $variation ], $payload['variation_ids'] );
		$this->assertNotContains( $parent, $payload['product_ids'] );
	}

	/**
	 * A variation alongside other products is kept too. This is the case nothing used
	 * to warn about: the rule stayed non-empty, so compute_pre_write_issues() saw no
	 * problem while the variation's holders lost the list at cutover.
	 */
	public function test_build_gate_payload_keeps_a_variation_id_alongside_other_products() {
		$variation = $this->create_product( 'subscription_variation', 'product_variation' );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $this->product, $variation ], [ $this->list_a ] ) ] ]
		);

		$this->assertSame( [ $this->product, $variation ], $payload['product_ids'] );
		$this->assertSame( [ $this->product, $variation ], $payload['custom_access']['access_rules'][0][0]['value'] );
		$this->assertSame( [ $variation ], $payload['variation_ids'] );
	}

	/**
	 * When dropping leaves a purchase group with nothing, the payload must fall back to
	 * the empty-access_rules shape rather than writing a rule with an empty value: an
	 * empty product list makes Access_Rules::has_active_subscription() grant any active
	 * subscription. The empty shape is what compute_pre_write_issues() and
	 * verify_migrated_gate() flag.
	 */
	public function test_build_gate_payload_writes_no_access_rules_when_every_product_is_dropped() {
		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ 0 ], [ $this->list_a ] ) ] ]
		);

		$this->assertSame( [], $payload['product_ids'] );
		$this->assertTrue( $payload['custom_access']['active'] );
		$this->assertSame( [], $payload['custom_access']['access_rules'] );

		$issues = $this->invoke_private_static(
			'compute_pre_write_issues',
			[ $payload['list_ids'], $payload['has_purchase'], $payload['product_ids'] ]
		);
		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * A month, as the plan descriptors and the rule value both spell it.
	 *
	 * @param int    $value The duration amount.
	 * @param string $unit  The duration unit.
	 *
	 * @return array A duration pair.
	 */
	private function duration( int $value, string $unit ): array {
		return [
			'duration_value' => $value,
			'duration_unit'  => $unit,
		];
	}

	/**
	 * Build a duck-typed stand-in for a WC_Memberships_Membership_Plan.
	 *
	 * The derivation takes its plan untyped and calls four accessors, so the access
	 * length can be exercised without WooCommerce Memberships.
	 *
	 * @param string $length_type       The plan's access length type.
	 * @param bool   $has_access_length Whether the plan bounds its access at all.
	 * @param int    $amount            The access length amount.
	 * @param string $period            The access length period.
	 *
	 * @return object A plan-shaped object.
	 */
	private function make_access_length_plan( string $length_type, bool $has_access_length, int $amount = 0, string $period = '' ) {
		return new class( $length_type, $has_access_length, $amount, $period ) {

			/**
			 * The access length type.
			 *
			 * @var string
			 */
			private $length_type;

			/**
			 * Whether the plan bounds its access.
			 *
			 * @var bool
			 */
			private $has_access_length;

			/**
			 * The access length amount.
			 *
			 * @var int
			 */
			private $amount;

			/**
			 * The access length period.
			 *
			 * @var string
			 */
			private $period;

			/**
			 * Constructor.
			 *
			 * @param string $length_type       The access length type.
			 * @param bool   $has_access_length Whether the plan bounds its access.
			 * @param int    $amount            The access length amount.
			 * @param string $period            The access length period.
			 */
			public function __construct( string $length_type, bool $has_access_length, int $amount, string $period ) {
				$this->length_type       = $length_type;
				$this->has_access_length = $has_access_length;
				$this->amount            = $amount;
				$this->period            = $period;
			}

			/**
			 * Return the access length type.
			 *
			 * @return string
			 */
			public function get_access_length_type() {
				return $this->length_type;
			}

			/**
			 * Return whether the plan bounds its access.
			 *
			 * @return bool
			 */
			public function has_access_length() {
				return $this->has_access_length;
			}

			/**
			 * Return the access length amount.
			 *
			 * @return int
			 */
			public function get_access_length_amount() {
				return $this->amount;
			}

			/**
			 * Return the access length period.
			 *
			 * @return string
			 */
			public function get_access_length_period() {
				return $this->period;
			}
		};
	}

	/**
	 * A plan granting on subscription products only needs the subscription rule and
	 * nothing else. A second rule group would be OR'd in, so an unwanted one-time
	 * group here would hand the lists to anyone who ever bought the product once.
	 */
	public function test_build_gate_payload_writes_only_a_subscription_group_for_subscription_products() {
		$variable = $this->create_product( 'variable-subscription' );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $this->product, $variable ], [ $this->list_a ] ) ] ]
		);

		$this->assertSame( [ $this->product, $variable ], $payload['subscription_ids'] );
		$this->assertSame( [], $payload['one_time_ids'] );
		$this->assertCount( 1, $payload['custom_access']['access_rules'] );
		$this->assertSame(
			[
				[
					'slug'  => 'subscription',
					'value' => [ $this->product, $variable ],
				],
			],
			$payload['custom_access']['access_rules'][0]
		);
	}

	/**
	 * A plan granting on a product bought once must migrate to the one-time rule,
	 * carrying the plan's own access length. The subscription rule is the condition
	 * such a buyer can never satisfy — it is what this whole split exists to stop
	 * being written for them.
	 */
	public function test_build_gate_payload_writes_only_a_one_time_group_for_one_time_products() {
		$one_time = $this->create_product( 'simple' );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[
				[
					$this->make_payload_plan( 'purchase', [ $one_time ], [ $this->list_a ], 'Prepaid', $this->duration( 12, 'months' ) ),
				],
			]
		);

		$this->assertSame( [], $payload['subscription_ids'] );
		$this->assertSame( [ $one_time ], $payload['one_time_ids'] );
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
			$payload['custom_access']['access_rules']
		);
	}

	/**
	 * The case the split exists for. A plan grants membership on any of its products,
	 * so a plan holding both kinds must produce two rule groups: access rule groups
	 * are OR'd while the rules inside one are AND'd, so flattening them into a single
	 * group would demand a subscription AND a one-time purchase, and admit nobody.
	 */
	public function test_build_gate_payload_writes_two_rule_groups_when_a_plan_grants_on_both_kinds() {
		$one_time = $this->create_product( 'simple' );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[
				[
					$this->make_payload_plan( 'purchase', [ $this->product, $one_time ], [ $this->list_a ], 'Premium', $this->duration( 90, 'days' ) ),
				],
			]
		);

		$access_rules = $payload['custom_access']['access_rules'];

		$this->assertCount( 2, $access_rules );
		$this->assertCount( 1, $access_rules[0], 'Rules within a group are AND-ed, so each kind gets a group of its own.' );
		$this->assertCount( 1, $access_rules[1], 'Rules within a group are AND-ed, so each kind gets a group of its own.' );
		$this->assertSame(
			[
				'slug'  => 'subscription',
				'value' => [ $this->product ],
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
	 * missing its duration is not a stricter rule but an unreadable one. The payload
	 * leaves the group out and reports the plan, which is what the command stops the
	 * run over.
	 */
	public function test_build_gate_payload_writes_no_one_time_group_without_a_duration() {
		$one_time = $this->create_product( 'simple' );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ $one_time ], [ $this->list_a ], 'Fixed end date' ) ] ]
		);

		$this->assertSame( [ $one_time ], $payload['one_time_ids'] );
		$this->assertNull( $payload['one_time_duration'] );
		$this->assertSame( [], $payload['custom_access']['access_rules'] );
		$this->assertSame( [ 'Fixed end date' ], $payload['duration_plans'] );
	}

	/**
	 * --one-time-duration exists for the plan whose access ends on a calendar date,
	 * which has no duration to read. The operator's value must reach the rule, or the
	 * flag is a no-op that only silences the error.
	 */
	public function test_build_gate_payload_lets_an_override_supply_a_missing_duration() {
		$one_time = $this->create_product( 'simple' );

		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[
				[ $this->make_payload_plan( 'purchase', [ $one_time ], [ $this->list_a ], 'Fixed end date' ) ],
				$this->duration( 0, 'forever' ),
			]
		);

		$this->assertSame( $this->duration( 0, 'forever' ), $payload['one_time_duration'] );
		$this->assertSame(
			[
				'product_ids'    => [ $one_time ],
				'duration_value' => 0,
				'duration_unit'  => 'forever',
			],
			$payload['custom_access']['access_rules'][0][0]['value']
		);
	}

	/**
	 * Access that ends on a fixed calendar date is not a duration from the purchase:
	 * the same product bought a year apart would grant a year of access or none. The
	 * derivation refuses it rather than inventing a length, and the command stops.
	 */
	public function test_derive_one_time_duration_refuses_a_fixed_end_date() {
		$plan = $this->make_access_length_plan( 'fixed', true, 6, 'months' );

		$this->assertNull( $this->invoke_private_static( 'derive_one_time_duration', [ $plan ] ) );
	}

	/**
	 * A plan that never expires grants forever, so the rule says so. Reading it as
	 * "no length" and refusing the run would stop the most ordinary plan of all.
	 */
	public function test_derive_one_time_duration_reads_an_unlimited_plan_as_forever() {
		$plan = $this->make_access_length_plan( 'relative', false );

		$this->assertSame(
			$this->duration( 0, 'forever' ),
			$this->invoke_private_static( 'derive_one_time_duration', [ $plan ] )
		);
	}

	/**
	 * Days and months are the units the rule evaluates, so they carry across as they
	 * are — no arithmetic to get wrong.
	 */
	public function test_derive_one_time_duration_keeps_days_and_months_unchanged() {
		$this->assertSame(
			$this->duration( 30, 'days' ),
			$this->invoke_private_static( 'derive_one_time_duration', [ $this->make_access_length_plan( 'relative', true, 30, 'days' ) ] )
		);
		$this->assertSame(
			$this->duration( 6, 'months' ),
			$this->invoke_private_static( 'derive_one_time_duration', [ $this->make_access_length_plan( 'relative', true, 6, 'months' ) ] )
		);
	}

	/**
	 * WooCommerce Memberships offers weeks and the gate rule does not, so weeks are
	 * converted rather than dropped. A week is exactly 7 days, so nothing is
	 * approximated and no reader gets a shorter grant than the plan gave them.
	 */
	public function test_derive_one_time_duration_converts_weeks_to_days() {
		$plan = $this->make_access_length_plan( 'relative', true, 3, 'weeks' );

		$this->assertSame(
			$this->duration( 21, 'days' ),
			$this->invoke_private_static( 'derive_one_time_duration', [ $plan ] )
		);
	}

	/**
	 * Years convert the same way, at 12 months apiece. Storing the amount with an
	 * unrecognised unit would write a rule the evaluator cannot read.
	 */
	public function test_derive_one_time_duration_converts_years_to_months() {
		$plan = $this->make_access_length_plan( 'relative', true, 2, 'years' );

		$this->assertSame(
			$this->duration( 24, 'months' ),
			$this->invoke_private_static( 'derive_one_time_duration', [ $plan ] )
		);
	}

	/**
	 * The three shapes the rule can store are the three the flag accepts.
	 */
	public function test_parse_one_time_duration_accepts_the_units_the_rule_evaluates() {
		$this->assertSame( $this->duration( 0, 'forever' ), $this->invoke_private_static( 'parse_one_time_duration', [ 'forever' ] ) );
		$this->assertSame( $this->duration( 90, 'days' ), $this->invoke_private_static( 'parse_one_time_duration', [ '90days' ] ) );
		$this->assertSame( $this->duration( 12, 'months' ), $this->invoke_private_static( 'parse_one_time_duration', [ '12months' ] ) );
	}

	/**
	 * Everything else is refused rather than approximated. "1year" and "abc" would
	 * store a unit the evaluator does not recognise, and "0days" a rule that expires
	 * the moment it is bought — either way a gate nobody can satisfy, written from a
	 * flag the operator believed had worked.
	 */
	public function test_parse_one_time_duration_refuses_anything_it_cannot_store() {
		$this->assertNull( $this->invoke_private_static( 'parse_one_time_duration', [ '1year' ] ) );
		$this->assertNull( $this->invoke_private_static( 'parse_one_time_duration', [ '0days' ] ) );
		$this->assertNull( $this->invoke_private_static( 'parse_one_time_duration', [ 'abc' ] ) );
		$this->assertNull( $this->invoke_private_static( 'parse_one_time_duration', [ '' ] ) );
	}

	/**
	 * The gate stores one duration for a group that may hold several plans.
	 * WooCommerce Memberships grants access from any one of them, so the shortest
	 * would take the list from readers the plans admitted — the longest wins, and the
	 * choice is reported rather than made silently.
	 */
	public function test_resolve_group_duration_keeps_the_longest_and_says_so() {
		$monthly = $this->create_product( 'simple' );
		$yearly  = $this->create_product( 'simple' );

		$resolved = $this->invoke_private_static(
			'resolve_group_duration',
			[
				[
					$this->make_payload_plan( 'purchase', [ $monthly ], [ $this->list_a ], 'Monthly', $this->duration( 1, 'months' ) ),
					$this->make_payload_plan( 'purchase', [ $yearly ], [ $this->list_a ], 'Yearly', $this->duration( 24, 'months' ) ),
				],
				null,
			]
		);

		$this->assertSame( $this->duration( 24, 'months' ), $resolved['duration'] );
		$this->assertSame( [ 'Monthly', 'Yearly' ], $resolved['plans'] );
		$this->assertStringContainsString( '1 months', $resolved['conflict'] );
		$this->assertStringContainsString( 'keeps the longest (24 months)', $resolved['conflict'] );
	}

	/**
	 * A plan that grants only on subscriptions has no say in how long a one-time
	 * purchase lasts. Letting it vote would drag its null duration into the ballot
	 * and stop a run that has no ambiguity in it at all.
	 */
	public function test_resolve_group_duration_ignores_a_plan_with_no_one_time_product() {
		$one_time = $this->create_product( 'simple' );

		$resolved = $this->invoke_private_static(
			'resolve_group_duration',
			[
				[
					$this->make_payload_plan( 'purchase', [ $this->product ], [ $this->list_a ], 'Subscribers' ),
					$this->make_payload_plan( 'purchase', [ $one_time ], [ $this->list_a ], 'Prepaid', $this->duration( 90, 'days' ) ),
				],
				null,
			]
		);

		$this->assertSame( [ 'Prepaid' ], $resolved['plans'] );
		$this->assertSame( $this->duration( 90, 'days' ), $resolved['duration'] );
		$this->assertNull( $resolved['conflict'] );
	}

	/**
	 * One plan with no readable length makes the whole group unresolvable: the gate
	 * holds a single duration, so picking the other plan's would grant this plan's
	 * buyers a length nobody chose. The plan is named because the command stops the
	 * run and the operator has to know which one to pass --one-time-duration for.
	 */
	public function test_resolve_group_duration_is_null_when_a_plan_has_no_readable_length() {
		$fixed  = $this->create_product( 'simple' );
		$prepay = $this->create_product( 'simple' );

		$resolved = $this->invoke_private_static(
			'resolve_group_duration',
			[
				[
					$this->make_payload_plan( 'purchase', [ $prepay ], [ $this->list_a ], 'Prepaid', $this->duration( 90, 'days' ) ),
					$this->make_payload_plan( 'purchase', [ $fixed ], [ $this->list_a ], 'Ends on a date' ),
				],
				null,
			]
		);

		$this->assertNull( $resolved['duration'] );
		$this->assertContains( 'Ends on a date', $resolved['plans'] );
	}

	/**
	 * Resolve a list's public (ESP) ID the same way the derivation does, rather than
	 * hardcoding the mock's ID format.
	 *
	 * @param int $list_id The list post ID.
	 *
	 * @return string The public list ID.
	 */
	private function public_id_for( int $list_id ): string {
		return ( new \Newspack\Newsletters\Subscription_List( $list_id ) )->get_public_id();
	}

	/**
	 * Put the given lists in the post-checkout signup modal.
	 *
	 * @param int[] $list_ids List post IDs.
	 */
	private function set_signup_modal_lists( array $list_ids ) {
		update_option( 'newspack_reader_activation_use_custom_lists', 1 );
		update_option(
			'newspack_reader_activation_newsletter_lists',
			array_map( fn( $list_id ) => [ 'id' => $this->public_id_for( $list_id ) ], $list_ids )
		);
	}

	/**
	 * A list outside the signup modal was auto-subscribed on membership activation
	 * before Access Control, so auto-signup carries that behavior forward.
	 */
	public function test_derive_auto_signup_is_on_when_no_list_is_in_the_signup_modal() {
		$this->set_signup_modal_lists( [] );

		$derived = $this->invoke_private_static( 'derive_auto_signup', [ [ $this->list_a, $this->list_b ] ] );

		$this->assertTrue( $derived['value'] );
		$this->assertSame( [ $this->list_a, $this->list_b ], $derived['non_modal'] );
		$this->assertSame( [], $derived['modal'] );
	}

	/**
	 * A list in the signup modal was left to reader opt-in, so auto-signup stays off.
	 */
	public function test_derive_auto_signup_is_off_when_every_list_is_in_the_signup_modal() {
		$this->set_signup_modal_lists( [ $this->list_a, $this->list_b ] );

		$derived = $this->invoke_private_static( 'derive_auto_signup', [ [ $this->list_a, $this->list_b ] ] );

		$this->assertFalse( $derived['value'] );
		$this->assertSame( [ $this->list_a, $this->list_b ], $derived['modal'] );
	}

	/**
	 * Auto-signup is one site-wide setting but the pre-Access-Control behavior was
	 * per-list, so a site splitting its lists across the modal cannot be expressed.
	 * The derivation returns no value rather than picking a side: guessing on
	 * subscribes readers who opted out, guessing off drops readers who expected the
	 * list.
	 */
	public function test_derive_auto_signup_is_undecided_when_lists_disagree() {
		$this->set_signup_modal_lists( [ $this->list_a ] );

		$derived = $this->invoke_private_static( 'derive_auto_signup', [ [ $this->list_a, $this->list_b ] ] );

		$this->assertNull( $derived['value'] );
		$this->assertSame( [ $this->list_a ], $derived['modal'] );
		$this->assertSame( [ $this->list_b ], $derived['non_modal'] );
	}

	/**
	 * With custom lists off there is no post-checkout modal at all —
	 * render_newsletters_signup_modal() returns early on
	 * is_newsletters_signup_available(), which is `(bool) get_setting(
	 * 'use_custom_lists' )` — so nothing was left to reader opt-in and the saved list
	 * selection is not a carve-out. (The selection still serves the registration
	 * form, which is a different surface.)
	 */
	public function test_derive_auto_signup_ignores_the_saved_lists_when_custom_lists_are_off() {
		update_option( 'newspack_reader_activation_use_custom_lists', 0 );
		update_option( 'newspack_reader_activation_newsletter_lists', [ [ 'id' => $this->public_id_for( $this->list_a ) ] ] );

		$derived = $this->invoke_private_static( 'derive_auto_signup', [ [ $this->list_a ] ] );

		$this->assertTrue( $derived['value'] );
	}

	/**
	 * A list ID that is not a newsletter list resolves to no ESP list, so it cannot
	 * be matched against the modal set. It is reported separately and counted as a
	 * non-modal list, which is what the pre-Access-Control default was.
	 */
	public function test_derive_auto_signup_reports_lists_it_cannot_resolve() {
		$this->set_signup_modal_lists( [] );
		$not_a_list = self::factory()->post->create();

		$derived = $this->invoke_private_static( 'derive_auto_signup', [ [ $this->list_a, $not_a_list ] ] );

		$this->assertSame( [ $not_a_list ], $derived['unresolved'] );
		$this->assertSame( [ $this->list_a, $not_a_list ], $derived['non_modal'] );
	}

	/**
	 * With no lists there is nothing to derive from, so nothing is decided.
	 */
	public function test_derive_auto_signup_is_undecided_with_no_lists() {
		$derived = $this->invoke_private_static( 'derive_auto_signup', [ [] ] );

		$this->assertNull( $derived['value'] );
	}

	/**
	 * Create a premium newsletter gate restricting the given lists.
	 *
	 * @param int[]  $list_ids     Newsletter list post IDs.
	 * @param bool   $has_purchase Whether to activate paid access with a product rule.
	 * @param string $title        The gate title.
	 *
	 * @return int The gate post ID.
	 */
	private function create_premium_gate( array $list_ids, bool $has_purchase = false, string $title = 'Premium fixture' ): int {
		return \Newspack\Content_Gate::create_gate(
			[
				'title'               => $title,
				'status'              => 'publish',
				'content_rules'       => [
					[
						'slug'  => 'newsletters',
						'value' => array_map( 'strval', $list_ids ),
					],
				],
				'content_rules_match' => 'any',
				'registration'        => [ 'active' => true ],
				'custom_access'       => [
					'active'       => $has_purchase,
					'access_rules' => $has_purchase
						? [
							[
								[
									'slug'  => 'subscription',
									'value' => [ 123 ],
								],
							],
						]
						: [],
				],
			],
			\Newspack\Content_Gate::GATE_CPT,
			true
		);
	}

	/**
	 * A correctly migrated gate reports nothing.
	 */
	public function test_verify_migrated_gate_passes_an_enforceable_gate() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ] );

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] ) );
	}

	/**
	 * Without the is_newsletter flag the gate lands in the content gate bucket, which
	 * the evaluator never consults for a list post, so it restricts nothing while
	 * looking migrated.
	 */
	public function test_verify_migrated_gate_flags_a_gate_missing_the_newsletter_flag() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ] );
		delete_post_meta( $gate_id, 'is_newsletter' );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'is_newsletter', $issues[0] );
	}

	/**
	 * A rule pointing at posts that are not newsletter lists selects nothing, so the
	 * lists the plan restricted stay open after cutover.
	 */
	public function test_verify_migrated_gate_flags_list_ids_that_are_not_lists() {
		$not_a_list = self::factory()->post->create();
		$gate_id    = $this->create_premium_gate( [ $not_a_list ] );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its restricted lists', $issues[0] );
	}

	/**
	 * A partly resolvable rule set is a partial leak, not a clean gate: the lists
	 * behind the dead IDs stay open while the rest are restricted.
	 */
	public function test_verify_migrated_gate_flags_a_partially_resolvable_rule() {
		$not_a_list = self::factory()->post->create();
		$gate_id    = $this->create_premium_gate( [ $this->list_a, $not_a_list ] );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'stay unrestricted', $issues[0] );
	}

	/**
	 * A gate with neither mode active is skipped outright by the evaluator.
	 */
	public function test_verify_migrated_gate_flags_a_gate_with_no_active_mode() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ] );
		\Newspack\Content_Gate::update_registration_settings( $gate_id, [ 'active' => false ] );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'neither the registration nor the paid access mode', $issues[0] );
	}

	/**
	 * Registration mode alone stops nobody who has an account, so a paid plan whose
	 * paid access mode never activated hands the premium list to any registered
	 * reader.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_with_no_paid_access_mode() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ], false );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'paid access mode is not active', $issues[0] );
		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, false ] ) );
	}

	/**
	 * An active paid access mode with no access rules asks for no purchase.
	 */
	public function test_verify_migrated_gate_flags_a_purchase_gate_whose_paid_access_has_no_rules() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ], true );
		\Newspack\Content_Gate::update_custom_access_settings( $gate_id, [ 'access_rules' => [] ] );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * The dry-run pass predicts the purchase-mode gap from group data, so the
	 * planning run is not more optimistic than the write.
	 */
	public function test_compute_pre_write_issues_flags_a_purchase_group_with_no_products() {
		$issues = $this->invoke_private_static( 'compute_pre_write_issues', [ [ $this->list_a ], true, [] ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no access rules', $issues[0] );
	}

	/**
	 * The dry-run pass also predicts unresolvable list IDs.
	 */
	public function test_compute_pre_write_issues_flags_list_ids_that_are_not_lists() {
		$not_a_list = self::factory()->post->create();

		$issues = $this->invoke_private_static( 'compute_pre_write_issues', [ [ $not_a_list ], false, [] ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'none of its restricted lists', $issues[0] );
	}

	/**
	 * A group with resolvable lists and products predicts nothing.
	 */
	public function test_compute_pre_write_issues_passes_a_sound_group() {
		$this->assertSame( [], $this->invoke_private_static( 'compute_pre_write_issues', [ [ $this->list_a ], true, [ 123 ] ] ) );
	}

	/**
	 * A dry run must never touch the site-wide option, even when the derived value
	 * disagrees with what is currently stored.
	 */
	public function test_report_auto_signup_dry_run_never_writes_option() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] ); // Neither list is in the modal, so auto-signup derives to on.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], true ] );

		$this->assertFalse( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
	}

	/**
	 * A live run writes the derived value over a stored one when the operator asks
	 * for it with --set-auto-signup. Without the flag the stored value is left alone
	 * ({@see test_report_auto_signup_live_leaves_a_stored_setting_alone()}), so this
	 * is the only path on which the write still has to work.
	 */
	public function test_report_auto_signup_live_writes_derived_value_when_it_differs() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] ); // Derives to on.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], false, false, 0, true ] );

		$this->assertTrue( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
	}

	/**
	 * A stored setting is a choice someone made, and a stored "on" reads exactly like
	 * the default. Overwriting it would silently undo a publisher who turned
	 * auto-signup off — which, once the access check is live, subscribes their readers
	 * to lists at the provider.
	 */
	public function test_report_auto_signup_live_leaves_a_stored_setting_alone() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] ); // Derives to on, differing from the stored value.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], false ] );

		$this->assertFalse( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
	}

	/**
	 * A site that never set the option has no choice to override, so the migration
	 * writes it without ceremony — which is the zero-touch case the command exists for.
	 */
	public function test_report_auto_signup_live_writes_when_the_option_was_never_set() {
		delete_option( 'newspack_premium_newsletters_auto_signup' );
		$this->set_signup_modal_lists( [ $this->list_a, $this->list_b ] ); // Derives to off.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], false ] );

		$this->assertSame( '0', (string) get_option( 'newspack_premium_newsletters_auto_signup' ) );
	}

	/**
	 * A live run that already matches the stored value takes the "leave it alone"
	 * branch rather than the write branch — pinned via the distinct message each
	 * branch emits, since WordPress's own update_option() no-ops an equal-value
	 * write regardless of which branch called it.
	 */
	public function test_report_auto_signup_live_leaves_matching_option_unchanged() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );
		$this->set_signup_modal_lists( [] ); // Derives to on, same as current.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], false ] );

		$this->assertTrue( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
		$this->assertContains( 'Auto-signup is already on; leaving it unchanged.', \WP_CLI::$output );
	}

	/**
	 * A live run against a split list set — some lists in the post-checkout signup
	 * modal, some not — cannot express the per-list distinction in one site-wide
	 * flag. It must write nothing and warn, naming the conflicting lists.
	 */
	public function test_report_auto_signup_split_lists_warns_and_writes_nothing_live() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );
		$this->set_signup_modal_lists( [ $this->list_a ] ); // list_a in the modal, list_b is not.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], false ] );

		$this->assertTrue( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
		$matching_warnings = array_filter(
			\WP_CLI::$warnings,
			fn( $warning ) => str_contains( $warning, 'disagree' )
		);
		$this->assertNotEmpty( $matching_warnings, 'Expected a warning about disagreeing lists.' );
		$warning = reset( $matching_warnings );
		$this->assertStringContainsString( (string) $this->list_a, $warning );
		$this->assertStringContainsString( (string) $this->list_b, $warning );
	}

	/**
	 * Two published gates can be named alike by hand, and indexing them by title is
	 * last-write-wins: the run would update one and leave the other restricting the
	 * same lists, invisible to the untouched-gate report because its title is one this
	 * run wrote.
	 */
	public function test_find_duplicate_gate_titles_fires_for_two_gates_sharing_a_title() {
		$gates = [
			[
				'id'    => 11,
				'title' => 'Premium',
			],
			[
				'id'    => 12,
				'title' => 'premium',
			],
		];

		$this->assertSame( [ 'Premium' ], $this->invoke_private_static( 'find_duplicate_gate_titles', [ $gates ] ) );
	}

	/**
	 * Distinct titles are the ordinary case; firing here would refuse every site with
	 * more than one premium newsletter gate.
	 */
	public function test_find_duplicate_gate_titles_is_empty_for_distinct_titles() {
		$gates = [
			[
				'id'    => 11,
				'title' => 'Premium',
			],
			[
				'id'    => 12,
				'title' => 'Insider',
			],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_duplicate_gate_titles', [ $gates ] ) );
	}

	/**
	 * A mixed group writes no paid access rules at all, so a purchase plan inside it
	 * has no one-time rule to give a duration to. Consulting it anyway would stop the
	 * run over a rule that was never going to be written. What the group actually
	 * loses is reported by the dropped-paywall warning instead.
	 */
	public function test_resolve_group_duration_asks_nothing_of_a_group_that_writes_no_rules() {
		$one_time = $this->create_product( 'simple' );
		$group    = [
			$this->make_payload_plan( 'purchase', [ $one_time ], [ $this->list_a ], 'Paid', null ),
			$this->make_payload_plan( 'signup', [], [ $this->list_a ], 'Free' ),
		];

		$result = $this->invoke_private_static( 'resolve_group_duration', [ $group, null ] );

		$this->assertSame( [], $result['plans'] );
		$this->assertNull( $result['duration'] );
	}

	/**
	 * All three product warnings describe a paid access rule, and a mixed group writes
	 * none. Reporting that its gate "keeps variation ID(s)" describes a rule that was
	 * never written; what the group actually lost is reported separately.
	 */
	public function test_report_product_id_issues_is_silent_for_a_gate_with_no_product_rule() {
		// A product ID that does get dropped, so there is a warning to suppress: with a
		// clean product list this would pass with the guard removed.
		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[
				[
					$this->make_payload_plan( 'purchase', [ 0 ], [ $this->list_a ], 'Paid' ),
					$this->make_payload_plan( 'signup', [], [ $this->list_a ], 'Free' ),
				],
			]
		);
		$this->assertSame( [ 0 ], $payload['dropped_product_ids']['invalid'] );
		\WP_CLI::$warnings = [];

		$this->invoke_private_static( 'report_product_id_issues', [ $payload ] );

		$this->assertSame( [], \WP_CLI::$warnings );
	}

	/**
	 * The counterpart: the same dropped ID on a group that does write a rule still
	 * warns, so the guard above suppresses the false case rather than the warning.
	 */
	public function test_report_product_id_issues_still_warns_for_a_purchase_group() {
		$payload = $this->invoke_private_static(
			'build_gate_payload',
			[ [ $this->make_payload_plan( 'purchase', [ 0, $this->product ], [ $this->list_a ], 'Paid' ) ] ]
		);
		\WP_CLI::$warnings = [];

		$this->invoke_private_static( 'report_product_id_issues', [ $payload ] );

		$this->assertNotEmpty( \WP_CLI::$warnings );
	}

	/**
	 * A --plan run writes at most one title, so every other gate on the site comes back
	 * as untouched. It cannot compute staleness, and listing gates nobody should retire
	 * trains an operator to skim the warning that matters on a full run.
	 */
	public function test_report_stale_gates_is_skipped_under_plan_scope() {
		// A published gate this run did not write, so the check has something to find:
		// without one the assertions below would hold with the guard removed.
		$this->create_premium_gate( [ $this->list_a ], false, 'Untouched fixture' );
		\WP_CLI::$warnings = [];

		$reported = $this->invoke_private_static( 'report_stale_gates', [ [], false, true ] );

		$this->assertFalse( $reported );
		$this->assertSame( [], \WP_CLI::$warnings );
	}

	/**
	 * The counterpart: on a full run the same gate is reported, and the return value
	 * is what puts the auto-signup write on the report-only path.
	 */
	public function test_report_stale_gates_reports_an_untouched_gate_on_a_full_run() {
		$this->create_premium_gate( [ $this->list_a ], false, 'Untouched fixture' );
		\WP_CLI::$warnings = [];

		$reported = $this->invoke_private_static( 'report_stale_gates', [ [], false, false ] );

		$this->assertTrue( $reported );
		$this->assertNotEmpty( \WP_CLI::$warnings );
	}

	/**
	 * The option governs every published gate's lists, while the derivation sees only
	 * the lists this run migrated. A gate this run did not write contributes to the
	 * first and not the second, which is the partial view that can turn a genuine
	 * disagreement into a determinate write.
	 */
	public function test_report_auto_signup_leaves_the_option_alone_when_gates_went_untouched() {
		delete_option( 'newspack_premium_newsletters_auto_signup' );
		$this->set_signup_modal_lists( [ $this->list_a ] ); // Derives to off.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a ], false, false, 0, false, true ] );

		$this->assertFalse( get_option( 'newspack_premium_newsletters_auto_signup' ) );
	}

	/**
	 * A list whose public (ESP) ID cannot be resolved is called out in its own
	 * warning, separate from the derived-value reporting.
	 */
	public function test_report_auto_signup_warns_for_unresolvable_lists() {
		$not_a_list = self::factory()->post->create();
		$this->set_signup_modal_lists( [] );

		$this->invoke_private_static( 'report_auto_signup', [ [ $not_a_list ], true ] );

		$matching_warnings = array_filter(
			\WP_CLI::$warnings,
			fn( $warning ) => str_contains( $warning, 'Could not resolve an ESP list' )
		);
		$this->assertNotEmpty( $matching_warnings, 'Expected an unresolved-list warning.' );
		$this->assertStringContainsString( (string) $not_a_list, reset( $matching_warnings ) );
	}

	/**
	 * Create a wc_membership_plan post directly, bypassing WooCommerce Memberships
	 * (which is absent from this harness) — get_plans() is a plain get_posts() query
	 * keyed on post_type and post_status, so no registration is needed for it to see
	 * the post.
	 *
	 * @param string $status Post status.
	 *
	 * @return int The plan post ID.
	 */
	private function create_plan_post( string $status = 'publish' ): int {
		return self::factory()->post->create(
			[
				'post_type'   => 'wc_membership_plan',
				'post_status' => $status,
			]
		);
	}

	/**
	 * Published wc_membership_plan posts come back as IDs, in ascending ID order.
	 */
	public function test_get_plans_returns_published_plan_ids() {
		$plan_a = $this->create_plan_post();
		$plan_b = $this->create_plan_post();

		$this->assertSame( [ $plan_a, $plan_b ], $this->invoke_private_static( 'get_plans', [ 0 ] ) );
	}

	/**
	 * A draft plan and a published post of a different post type must not appear —
	 * only published wc_membership_plan posts qualify.
	 */
	public function test_get_plans_excludes_drafts_and_other_post_types() {
		$published_plan = $this->create_plan_post();
		$this->create_plan_post( 'draft' );
		self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
			] 
		);

		$this->assertSame( [ $published_plan ], $this->invoke_private_static( 'get_plans', [ 0 ] ) );
	}

	/**
	 * With an ID argument, get_plans() returns only that plan.
	 */
	public function test_get_plans_with_id_returns_only_that_plan() {
		$plan_a = $this->create_plan_post();
		$plan_b = $this->create_plan_post();

		$this->assertSame( [ $plan_b ], $this->invoke_private_static( 'get_plans', [ $plan_b ] ) );
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
	 * A non-numeric --plan value aborts before any WooCommerce Memberships check is
	 * reached, so it is reachable without that plugin present in this harness.
	 */
	public function test_migrate_premium_newsletters_aborts_on_non_numeric_plan() {
		$migration = new Premium_Newsletters_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --plan value' );

		$migration->migrate_premium_newsletters( [], [ 'plan' => 'not-a-number' ] );
	}

	/**
	 * A --plan value of zero aborts rather than being treated as "no filter".
	 */
	public function test_migrate_premium_newsletters_aborts_on_zero_plan() {
		$migration = new Premium_Newsletters_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --plan value' );

		$migration->migrate_premium_newsletters( [], [ 'plan' => '0' ] );
	}

	/**
	 * A negative --plan value aborts as well.
	 */
	public function test_migrate_premium_newsletters_aborts_on_negative_plan() {
		$migration = new Premium_Newsletters_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --plan value' );

		$migration->migrate_premium_newsletters( [], [ 'plan' => '-5' ] );
	}

	/**
	 * A --plan run is a testing path over one plan's lists, but the option it would
	 * write is site-wide. It must report the derivation and write nothing, even under
	 * --live: the site's other lists may sit on the other side of the modal split,
	 * and flipping the option from that partial view auto-subscribes readers to
	 * newsletters they declined at checkout.
	 */
	public function test_report_auto_signup_plan_scoped_live_writes_nothing() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] ); // Neither list is in the modal, so auto-signup derives to on.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], false, true ] );

		$this->assertFalse( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
	}

	/**
	 * An operator who passed --live has every reason to expect a write, so the reason
	 * nothing happened must name --plan rather than read as an ordinary dry run.
	 */
	public function test_report_auto_signup_plan_scoped_says_why_it_wrote_nothing() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] );

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a ], false, true ] );

		$matching_lines = array_filter(
			\WP_CLI::$output,
			fn( $line ) => str_contains( $line, 'a --plan run never writes it' )
		);
		$this->assertNotEmpty( $matching_lines, 'Expected the --plan run to explain why it wrote nothing.' );
	}

	/**
	 * A full run still writes: the --plan guard must not have disabled the setting's
	 * migration altogether.
	 */
	public function test_report_auto_signup_full_live_run_still_writes() {
		// Deleted rather than set to a differing value: a stored value is left alone on
		// its own account, which would pass this test for the wrong reason. Deriving to
		// OFF then gives the run something to do — an absent option already reads as on,
		// so deriving to on would take the "already on" branch and write nothing.
		delete_option( 'newspack_premium_newsletters_auto_signup' );
		$this->set_signup_modal_lists( [ $this->list_a ] );

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a ], false, false ] );

		$this->assertSame( '0', (string) get_option( 'newspack_premium_newsletters_auto_signup' ) );
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
			'list_ids'      => [],
			'product_ids'   => [],
		];
	}

	/**
	 * When regrouping merges plans a previous run migrated separately — the likely
	 * shape after a --plan run — the gates those plans were written to are named so
	 * the operator can retire them before a stale, stricter gate wins the evaluation.
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
	 * Two same-named plans restricting different lists land in different groups and
	 * resolve to one gate title. The second group would take the update branch, and
	 * update_gate_content_rules() replaces rather than merges — so the first group's
	 * lists would end up behind no gate at all while both rows reported as processed.
	 * The collision is computable before any write, so it is found here.
	 */
	public function test_find_colliding_gate_titles_fires_for_two_groups_sharing_a_title() {
		$plan_groups = [
			'[1]' => [ $this->make_payload_plan( 'purchase', [], [ $this->list_a ], 'Premium' ) ],
			'[2]' => [ $this->make_payload_plan( 'purchase', [], [ $this->list_b ], 'premium' ) ],
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
			'[1]' => [ $this->make_payload_plan( 'purchase', [], [ $this->list_a ], 'Premium' ) ],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_colliding_gate_titles', [ $plan_groups ] ) );
	}

	/**
	 * Distinct titles name distinct gates, which is the ordinary multi-gate run — it
	 * must not be stopped.
	 */
	public function test_find_colliding_gate_titles_is_empty_for_distinct_titles() {
		$plan_groups = [
			'[1]' => [ $this->make_payload_plan( 'purchase', [], [ $this->list_a ], 'Premium' ) ],
			'[2]' => [ $this->make_payload_plan( 'purchase', [], [ $this->list_b ], 'Insider' ) ],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_colliding_gate_titles', [ $plan_groups ] ) );
	}

	/**
	 * Two plans overlapping on one list without matching on the rest become two
	 * gates over that list. WooCommerce Memberships grants it to a holder of either
	 * plan while gates resolve restrictive-wins, so the stricter gate would decide
	 * and the other plan's readers would lose the list. Computable from the grouping,
	 * so it is caught before any write.
	 */
	public function test_find_lists_shared_across_groups_fires_for_overlapping_groups() {
		$plan_groups = [
			'[a]'   => [ $this->make_payload_plan( 'purchase', [], [ $this->list_a ], 'Premium' ) ],
			'[a,b]' => [ $this->make_payload_plan( 'signup', [], [ $this->list_a, $this->list_b ], 'Insider' ) ],
		];

		$this->assertSame(
			[ $this->list_a => [ 'Premium', 'Insider' ] ],
			$this->invoke_private_static( 'find_lists_shared_across_groups', [ $plan_groups ] )
		);
	}

	/**
	 * Groups restricting different lists are the ordinary multi-gate run. Refusing
	 * one would block every site with more than one premium newsletter plan.
	 */
	public function test_find_lists_shared_across_groups_is_empty_for_disjoint_groups() {
		$plan_groups = [
			'[a]' => [ $this->make_payload_plan( 'purchase', [], [ $this->list_a ], 'Premium' ) ],
			'[b]' => [ $this->make_payload_plan( 'signup', [], [ $this->list_b ], 'Insider' ) ],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_lists_shared_across_groups', [ $plan_groups ] ) );
	}

	/**
	 * Plans inside one group restrict the same lists and share one gate by
	 * construction, so their overlap is the grouping working rather than a conflict.
	 * Counting per plan instead of per group would refuse every multi-plan group.
	 */
	public function test_find_lists_shared_across_groups_ignores_plans_within_one_group() {
		$plan_groups = [
			'[a]' => [
				$this->make_payload_plan( 'purchase', [], [ $this->list_a ], 'Premium' ),
				$this->make_payload_plan( 'signup', [], [ $this->list_a ], 'Insider' ),
			],
		];

		$this->assertSame( [], $this->invoke_private_static( 'find_lists_shared_across_groups', [ $plan_groups ] ) );
	}

	/**
	 * A published premium newsletter gate no group wrote is a gate no current plan
	 * accounts for. It keeps restricting its lists, and the first restricting gate
	 * wins — so it has to be named. Gates in the content bucket are somebody else's
	 * business and must not be dragged in.
	 */
	public function test_report_stale_gates_names_an_untouched_newsletter_gate() {
		$stale_id = $this->create_premium_gate( [ $this->list_a ], false, 'Stale gate' );
		\Newspack\Content_Gate::create_gate(
			[
				'title'  => 'Plain content gate',
				'status' => 'publish',
			]
		);

		$this->invoke_private_static( 'report_stale_gates', [ [], false, false ] );

		$warnings = implode( "\n", \WP_CLI::$warnings );
		$this->assertStringContainsString( sprintf( '"Stale gate" (gate %d)', $stale_id ), $warnings );
		$this->assertStringNotContainsString( 'Plain content gate', $warnings );
	}

	/**
	 * A gate this run wrote is accounted for, so reporting it would be noise that
	 * buries the gates that matter.
	 */
	public function test_report_stale_gates_ignores_a_gate_this_run_wrote() {
		$this->create_premium_gate( [ $this->list_a ], false, 'Written gate' );

		$this->invoke_private_static( 'report_stale_gates', [ [ 'written gate' => true ], false, false ] );

		$this->assertSame( [], \WP_CLI::$warnings );
	}

	/**
	 * The diff is by title, and titles are matched case-insensitively everywhere else
	 * in this command; a casing difference must not turn a written gate into a stale
	 * one.
	 */
	public function test_find_stale_newsletter_gates_matches_titles_case_insensitively() {
		$gates = [
			[
				'id'    => 11,
				'title' => 'Written Gate',
			],
			[
				'id'    => 22,
				'title' => 'Untouched Gate',
			],
		];

		$this->assertSame(
			[
				[
					'id'    => 22,
					'title' => 'Untouched Gate',
				],
			],
			$this->invoke_private_static( 'find_stale_newsletter_gates', [ $gates, [ 'written gate' => true ] ] )
		);
	}

	/**
	 * WP-CLI strips a bare `--plan` before the command runs, so the command sees no
	 * plan at all. The raw argv is the only place the mistake is still visible.
	 */
	public function test_get_valueless_value_flags_reports_a_bare_plan() {
		$this->assertSame(
			[ '--plan' ],
			Premium_Newsletters_Migration::get_valueless_value_flags( [ 'wp', 'newspack', 'migrate-premium-newsletters', '--plan', '--live' ] )
		);
	}

	/**
	 * A --plan that carries its value is the ordinary invocation and must pass.
	 */
	public function test_get_valueless_value_flags_ignores_a_plan_with_a_value() {
		$this->assertSame(
			[],
			Premium_Newsletters_Migration::get_valueless_value_flags( [ 'wp', 'newspack', 'migrate-premium-newsletters', '--plan=12', '--live' ] )
		);
	}

	/**
	 * The guard has to be wired into the command, not merely available: a bare --plan
	 * with --live would otherwise migrate every plan on the site and write the
	 * site-wide auto-signup setting.
	 */
	public function test_migrate_premium_newsletters_aborts_on_a_bare_plan_flag() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'migrate-premium-newsletters', '--plan', '--live' ];
		$migration       = new Premium_Newsletters_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'require a value but arrived without one' );

		$migration->migrate_premium_newsletters( [], [ 'live' => true ] );
	}

	/**
	 * PHP's is_numeric() accepts '12.9', which casts to plan 12 — a run narrowed to a plan
	 * the operator never named.
	 */
	public function test_migrate_premium_newsletters_aborts_on_a_fractional_plan() {
		$migration = new Premium_Newsletters_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --plan value' );

		$migration->migrate_premium_newsletters( [], [ 'plan' => '12.9' ] );
	}

	/**
	 * PHP's is_numeric() accepts '1e2', which casts to plan 100.
	 */
	public function test_migrate_premium_newsletters_aborts_on_an_exponent_plan() {
		$migration = new Premium_Newsletters_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'Invalid --plan value' );

		$migration->migrate_premium_newsletters( [], [ 'plan' => '1e2' ] );
	}

	/**
	 * A digits-only --plan passes both guards and the run proceeds — reaching, in this
	 * harness, the WooCommerce Memberships pre-flight. Without this the two guards
	 * above could pass by rejecting everything.
	 */
	public function test_migrate_premium_newsletters_accepts_a_digits_only_plan() {
		$_SERVER['argv'] = [ 'wp', 'newspack', 'migrate-premium-newsletters', '--plan=12' ];
		$migration       = new Premium_Newsletters_Migration();

		$this->expectException( \WP_CLI_Mock_Exception::class );
		$this->expectExceptionMessage( 'WooCommerce Memberships is not active' );

		$migration->migrate_premium_newsletters( [], [ 'plan' => '12' ] );
	}

	/**
	 * WP_CLI::confirm() reads STDIN, and at EOF it exits with status 0 and no message
	 * — so an `ssh host "wp newspack migrate-…"` run would stop at the prompt having
	 * already written every gate before it, and report success. With no terminal and
	 * no --yes, the prompt must never be asked.
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
	 * Enforcement asks Content_Gate::is_gating_active(), which is the feature constant
	 * AND Reader_Activation::is_enabled(). A site that defines the constant with
	 * Audience Management off enforces nothing — Content_Restriction_Control::
	 * is_post_restricted() and Premium_Newsletters::check_access() both bail — so the
	 * preflight must warn, and say which half is missing.
	 */
	public function test_describe_inactive_gating_warns_when_reader_activation_is_off() {
		$warning = $this->invoke_private_static( 'describe_inactive_gating', [ true, false ] );

		$this->assertNotNull( $warning );
		$this->assertStringContainsString( 'Audience Management', $warning );
		$this->assertStringNotContainsString( 'NEWSPACK_CONTENT_GATES', $warning );
	}

	/**
	 * The other half, unchanged from before: no feature constant, no enforcement.
	 */
	public function test_describe_inactive_gating_warns_when_the_feature_constant_is_off() {
		$warning = $this->invoke_private_static( 'describe_inactive_gating', [ false, true ] );

		$this->assertNotNull( $warning );
		$this->assertStringContainsString( 'NEWSPACK_CONTENT_GATES', $warning );
		$this->assertStringNotContainsString( 'Audience Management', $warning );
	}

	/**
	 * With both off, both are named: the operator who fixes only the one they were
	 * told about would re-run and still get dormant gates.
	 */
	public function test_describe_inactive_gating_names_both_missing_halves() {
		$warning = $this->invoke_private_static( 'describe_inactive_gating', [ false, false ] );

		$this->assertStringContainsString( 'NEWSPACK_CONTENT_GATES', $warning );
		$this->assertStringContainsString( 'Audience Management', $warning );
	}

	/**
	 * Both conditions met is the only silent case.
	 */
	public function test_describe_inactive_gating_is_silent_when_gating_is_active() {
		$this->assertNull( $this->invoke_private_static( 'describe_inactive_gating', [ true, true ] ) );
	}

	/**
	 * A summary row for a group whose gate failed to write.
	 *
	 * @param string $action The row's action text.
	 *
	 * @return array A summary row.
	 */
	private function make_summary_row( string $action ): array {
		return [
			'plan_name'   => 'Fixture',
			'action'      => $action,
			'gate_id'     => 1,
			'lists'       => 1,
			'access_type' => 'signup',
		];
	}

	/**
	 * A group whose gate failed to write migrated no lists, so it must be kept out of
	 * the site-wide auto-signup derivation.
	 */
	public function test_count_incomplete_groups_counts_a_failed_write() {
		$summary = [
			$this->make_summary_row( 'created' ),
			$this->make_summary_row( 'ERROR: could not insert post' ),
		];

		$this->assertSame( 1, $this->invoke_private_static( 'count_incomplete_groups', [ $summary ] ) );
	}

	/**
	 * A WARN row counts too: $migrated_lists is appended before verify_migrated_gate()
	 * runs, so a gate the evaluator cannot enforce feeds the derivation unless this
	 * catches it.
	 */
	public function test_count_incomplete_groups_counts_a_warn_row() {
		$summary = [
			$this->make_summary_row( 'updated' ),
			$this->make_summary_row( 'WARN: it has no content rules' ),
		];

		$this->assertSame( 1, $this->invoke_private_static( 'count_incomplete_groups', [ $summary ] ) );
	}

	/**
	 * A clean run counts nothing, so the ordinary path still writes the setting.
	 */
	public function test_count_incomplete_groups_ignores_clean_rows() {
		$summary = [
			$this->make_summary_row( 'created' ),
			$this->make_summary_row( 'updated (dry-run)' ),
		];

		$this->assertSame( 0, $this->invoke_private_static( 'count_incomplete_groups', [ $summary ] ) );
	}

	/**
	 * The derivation is over the lists this run migrated, so a group that failed
	 * leaves its lists out of it — and dropping lists can turn a genuine modal /
	 * non-modal disagreement into a determinate value. The option must be left alone
	 * even under --live.
	 */
	public function test_report_auto_signup_writes_nothing_when_a_group_is_incomplete() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] ); // Derives to on, which differs from the stored value.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a ], false, false, 1 ] );

		$this->assertFalse( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
	}

	/**
	 * And says so: an operator who passed --live and saw a derivation printed needs to
	 * be told why it was not written, and that a re-run settles it.
	 */
	public function test_report_auto_signup_says_why_an_incomplete_run_wrote_nothing() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] );

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a ], false, false, 2 ] );

		$matching_lines = array_filter(
			\WP_CLI::$output,
			fn( $line ) => str_contains( $line, 'failed to write or will not enforce' )
		);
		$this->assertNotEmpty( $matching_lines, 'Expected the incomplete run to explain why it wrote nothing.' );
		$this->assertStringContainsString( '2 gate(s)', reset( $matching_lines ) );
	}

	/**
	 * A dry run predicts the same suppression rather than promising a write that the
	 * live run would not make.
	 */
	public function test_report_auto_signup_dry_run_predicts_the_suppression() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] );

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a ], true, false, 1 ] );

		$this->assertEmpty(
			array_filter( \WP_CLI::$output, fn( $line ) => str_contains( $line, 'would be set to' ) ),
			'A dry run over an incomplete set must not promise a write.'
		);
	}

	/**
	 * A run where every group failed derives from no lists at all. That used to print
	 * nothing — indistinguishable from a run with nothing to change — so it must say
	 * something.
	 */
	public function test_report_auto_signup_reports_a_run_that_migrated_no_lists() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$this->invoke_private_static( 'report_auto_signup', [ [], false, false, 3 ] );

		$matching_warnings = array_filter(
			\WP_CLI::$warnings,
			fn( $warning ) => str_contains( $warning, 'Auto-signup was not derived' )
		);
		$this->assertNotEmpty( $matching_warnings, 'Expected an auto-signup line for a run that migrated nothing.' );
		$this->assertStringContainsString( 'all 3 gate(s)', reset( $matching_warnings ) );
	}

	/**
	 * The same line covers an empty run with no failures, without claiming a failure
	 * that did not happen.
	 */
	public function test_report_auto_signup_reports_an_empty_run_without_blaming_failures() {
		update_option( 'newspack_premium_newsletters_auto_signup', 1 );

		$this->invoke_private_static( 'report_auto_signup', [ [], false, false, 0 ] );

		$matching_warnings = array_filter(
			\WP_CLI::$warnings,
			fn( $warning ) => str_contains( $warning, 'Auto-signup was not derived' )
		);
		$this->assertNotEmpty( $matching_warnings );
		$this->assertStringNotContainsString( 'failed to write', reset( $matching_warnings ) );
	}

	/**
	 * Point a gate mode at a given layout post ID.
	 *
	 * @param int    $gate_id   The gate post ID.
	 * @param string $mode      Either 'registration' or 'custom_access'.
	 * @param int    $layout_id The layout post ID to store.
	 */
	private function set_gate_layout_id( int $gate_id, string $mode, int $layout_id ) {
		if ( 'custom_access' === $mode ) {
			\Newspack\Content_Gate::update_custom_access_settings( $gate_id, [ 'gate_layout_id' => $layout_id ] );
			return;
		}
		\Newspack\Content_Gate::update_registration_settings( $gate_id, [ 'gate_layout_id' => $layout_id ] );
	}

	/**
	 * The evaluator ends each gate's turn on `if ( $is_restricted && $gate_layout_id )`,
	 * and the settings getters always return a gate_layout_id defaulting to 0 — so the
	 * `??` fallbacks beside it never fire and a gate with no layout restricts nothing.
	 * create_gate() can produce exactly that: it discards a WP_Error from
	 * create_gate_layout() and still returns the gate ID.
	 */
	public function test_verify_migrated_gate_flags_an_active_mode_with_no_layout() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ] );
		$this->set_gate_layout_id( $gate_id, 'registration', 0 );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'points at no gate layout', $issues[0] );
		$this->assertStringContainsString( 'registration', $issues[0] );
	}

	/**
	 * The paid access mode is checked on the same terms when it is the active one.
	 */
	public function test_verify_migrated_gate_flags_a_paid_access_mode_with_no_layout() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ], true );
		$this->set_gate_layout_id( $gate_id, 'custom_access', 0 );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id, true ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'paid access', $issues[0] );
	}

	/**
	 * An existing gate whose layout post was deleted keeps a truthy stale ID, so it
	 * still restricts — but the layout is gone from under it and readers get the
	 * hard-coded default gate instead of the publisher's.
	 */
	public function test_verify_migrated_gate_flags_a_layout_post_that_no_longer_exists() {
		$gate_id   = $this->create_premium_gate( [ $this->list_a ] );
		$layout_id = (int) \Newspack\Content_Gate::get_registration_settings( $gate_id )['gate_layout_id'];
		$this->assertGreaterThan( 0, $layout_id, 'create_gate() should have seeded a registration layout.' );
		wp_delete_post( $layout_id, true );

		$issues = $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no longer exists', $issues[0] );
		$this->assertStringContainsString( (string) $layout_id, $issues[0] );
	}

	/**
	 * An inactive mode's layout is not the gate's problem: the evaluator never reads
	 * it, so flagging it would be noise on every signup-only gate.
	 */
	public function test_verify_migrated_gate_ignores_the_layout_of_an_inactive_mode() {
		$gate_id = $this->create_premium_gate( [ $this->list_a ] );
		$this->set_gate_layout_id( $gate_id, 'custom_access', 0 );

		$this->assertSame( [], $this->invoke_private_static( 'verify_migrated_gate', [ $gate_id ] ) );
	}

	/**
	 * A group that would update an existing gate can have its layouts checked before
	 * the write: they are on the site now, and the update path leaves them alone.
	 */
	public function test_compute_pre_write_issues_flags_an_existing_gates_missing_layout() {
		$gate_id   = $this->create_premium_gate( [ $this->list_a ] );
		$layout_id = (int) \Newspack\Content_Gate::get_registration_settings( $gate_id )['gate_layout_id'];
		wp_delete_post( $layout_id, true );

		$issues = $this->invoke_private_static( 'compute_pre_write_issues', [ [ $this->list_a ], false, [], $gate_id ] );

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'no longer exists', $issues[0] );
	}

	/**
	 * A group that would create its gate has no layouts to read yet — create_gate()
	 * makes them as part of the write — so the dry run predicts nothing about them
	 * rather than inventing a verdict.
	 */
	public function test_compute_pre_write_issues_predicts_no_layout_for_a_gate_that_does_not_exist() {
		$this->assertSame( [], $this->invoke_private_static( 'compute_pre_write_issues', [ [ $this->list_a ], false, [], null ] ) );
	}

	/**
	 * A manual-only plan is skipped, but the lists it restricted are what the operator
	 * has to decide about. The row used to report "—" whether the plan restricted five
	 * lists or none.
	 */
	public function test_report_manual_only_plan_row_carries_the_real_list_count() {
		$row = $this->invoke_private_static( 'report_manual_only_plan', [ 'Staff comps', [ $this->list_a, $this->list_b ] ] );

		$this->assertSame( 2, $row['lists'] );
		$this->assertSame( 'skipped (manual-only)', $row['action'] );
	}

	/**
	 * And the lists are named, because the table cannot say whether they will go open
	 * to everyone or have their members unsubscribed by another plan's gate.
	 */
	public function test_report_manual_only_plan_warns_naming_its_lists() {
		$this->invoke_private_static( 'report_manual_only_plan', [ 'Staff comps', [ $this->list_a ] ] );

		$matching_warnings = array_filter(
			\WP_CLI::$warnings,
			fn( $warning ) => str_contains( $warning, 'manual-only' )
		);
		$this->assertNotEmpty( $matching_warnings, 'Expected a warning naming the skipped plan\'s lists.' );
		$this->assertStringContainsString( (string) $this->list_a, reset( $matching_warnings ) );
	}

	/**
	 * A manual-only plan that restricted no list has nothing to warn about, and a
	 * warning per such plan would bury the ones that matter.
	 */
	public function test_report_manual_only_plan_is_quiet_without_lists() {
		$row = $this->invoke_private_static( 'report_manual_only_plan', [ 'Staff comps', [] ] );

		$this->assertSame( 0, $row['lists'] );
		$this->assertEmpty( \WP_CLI::$warnings );
	}
}
