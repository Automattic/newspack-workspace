<?php
/**
 * Tests for Member_Body_Class.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use Newspack\Content_Gate;

/**
 * Member_Body_Class test case.
 *
 * @group Member_Body_Class
 */
class Newspack_Test_Member_Body_Class extends WP_UnitTestCase {

	/**
	 * The class WooCommerce Memberships added, which third-party CSS and ad rules key on.
	 * Asserted as a literal: renaming it would break those consumers.
	 */
	const MEMBER_CLASS = 'member-logged-in';

	/**
	 * Slug of the access rule the test gates use.
	 */
	const RULE = 'member_body_class_test_rule';

	/**
	 * Slug of an access rule that admits logged-out visitors, as institutional access can.
	 */
	const ANONYMOUS_RULE = 'member_body_class_anonymous_rule';

	/**
	 * User IDs the test rule admits.
	 *
	 * @var int[]
	 */
	private static $admitted = [];

	/**
	 * How many times the test rule has been evaluated.
	 *
	 * @var int
	 */
	private static $evaluations = 0;

	/**
	 * The `payment_recovery_grace` context value each evaluation saw.
	 *
	 * @var array
	 */
	private static $grace_seen = [];

	/**
	 * A reader with no special capabilities.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		// Access_Rules keeps registered rules in a static, so register once per process.
		if ( ! isset( Access_Rules::get_registered_rules()[ self::RULE ] ) ) {
			Access_Rules::register_rule(
				[
					'id'       => self::RULE,
					'name'     => 'Member body class test rule',
					'callback' => function( $user_id ) {
						++self::$evaluations;
						self::$grace_seen[] = Access_Rules::get_evaluation_context( 'payment_recovery_grace' );
						return in_array( (int) $user_id, self::$admitted, true );
					},
				]
			);
		}
		if ( ! isset( Access_Rules::get_registered_rules()[ self::ANONYMOUS_RULE ] ) ) {
			Access_Rules::register_rule(
				[
					'id'                 => self::ANONYMOUS_RULE,
					'name'               => 'Member body class anonymous test rule',
					'supports_anonymous' => true,
					'callback'           => function() {
						++self::$evaluations;
						return true;
					},
				]
			);
		}
		self::$admitted    = [];
		self::$evaluations = 0;
		self::$grace_seen  = [];
		wp_cache_flush();
		$this->reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_reader_activation_enabled' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a gate whose paid access uses the test rule.
	 *
	 * @param array $custom_access Overrides for the gate's custom_access settings.
	 * @param array $args          Optional. 'status' (default 'publish') and 'is_newsletter'.
	 * @return int Gate ID.
	 */
	private function make_gate( $custom_access = [], $args = [] ) {
		$gate_id = self::factory()->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => $args['status'] ?? 'publish',
			]
		);
		if ( ! empty( $args['is_newsletter'] ) ) {
			update_post_meta( $gate_id, 'is_newsletter', true );
		}
		update_post_meta(
			$gate_id,
			'custom_access',
			array_merge(
				[
					'active'       => true,
					'access_rules' => [ [ [ 'slug' => self::RULE ] ] ],
				],
				$custom_access
			)
		);
		return $gate_id;
	}

	/**
	 * Body classes for the current request.
	 *
	 * @return string[]
	 */
	private function body_classes() {
		return apply_filters( 'body_class', [] );
	}

	/**
	 * A reader who passes a published gate's paid-access rules gets the class.
	 */
	public function test_adds_class_for_reader_who_passes_a_gate() {
		$this->make_gate();
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$this->assertContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Passing any one gate is enough, even when an earlier gate refuses the reader.
	 */
	public function test_adds_class_when_any_gate_admits() {
		$this->make_gate(
			[
				'access_rules' => [
					[
						[
							'slug'  => 'email_domain',
							'value' => 'example.test',
						],
					],
				],
			]
		);
		$this->make_gate();
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$this->assertContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * A reader who passes no gate gets no class.
	 */
	public function test_no_class_for_reader_without_paid_access() {
		$this->make_gate();
		wp_set_current_user( $this->reader_id );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Logged-out visitors get no class, even from a gate that admits them, and no rule runs.
	 */
	public function test_logged_out_visitor_gets_no_class_and_no_evaluation() {
		$this->make_gate(
			[
				'access_rules' => [
					[
						[
							'slug'  => self::ANONYMOUS_RULE,
							'value' => [ 1 ],
						],
					],
				],
			]
		);
		wp_set_current_user( 0 );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
		$this->assertSame( 0, self::$evaluations );
	}

	/**
	 * An empty rule set admits everyone, so it must not mark every reader a member.
	 */
	public function test_ignores_gate_with_no_access_rules() {
		$this->make_gate( [ 'access_rules' => [] ] );
		wp_set_current_user( $this->reader_id );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Rules on a gate whose paid access is switched off grant nothing.
	 */
	public function test_ignores_gate_with_custom_access_off() {
		$this->make_gate( [ 'active' => false ] );
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Draft gates restrict nothing, so they grant nothing either.
	 */
	public function test_ignores_draft_gate() {
		$this->make_gate( [], [ 'status' => 'draft' ] );
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Premium newsletter gates control newsletter access, not site membership.
	 */
	public function test_ignores_newsletter_gate() {
		$this->make_gate( [], [ 'is_newsletter' => true ] );
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Editors and admins get the class without passing any rule.
	 */
	public function test_editor_gets_class() {
		$this->make_gate();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Inert gating restricts nothing, so it adds nothing.
	 */
	public function test_no_class_while_gating_is_inactive() {
		$this->make_gate();
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
		$this->assertSame( 0, self::$evaluations );
	}

	/**
	 * While Memberships is active it owns the class, so this adds nothing.
	 *
	 * Runs isolated because WC_Memberships, once declared, would make
	 * Memberships::is_active() true for every later test in this process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_class_while_memberships_is_active() {
		require dirname( __DIR__, 2 ) . '/mocks/wc-memberships-active-mock.php';
		$this->make_gate();
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
		$this->assertSame( 0, self::$evaluations );
	}

	/**
	 * The class is added once, even if another source already added it.
	 */
	public function test_does_not_duplicate_class() {
		$this->make_gate();
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$classes = apply_filters( 'body_class', [ self::MEMBER_CLASS ] );

		$this->assertSame( 1, count( array_keys( $classes, self::MEMBER_CLASS, true ) ) );
	}

	/**
	 * The gate's payment-recovery setting reaches the rules, as it does for content.
	 */
	public function test_passes_gate_payment_recovery_setting_to_rules() {
		$this->make_gate( [ 'payment_recovery_grace' => false ] );
		wp_set_current_user( $this->reader_id );

		$this->body_classes();

		$this->assertSame( [ false ], self::$grace_seen );
	}

	/**
	 * A reader's result is evaluated once, then served from the cache.
	 */
	public function test_result_is_cached_per_reader() {
		$this->make_gate();
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );

		$this->body_classes();
		$this->body_classes();

		$this->assertSame( 1, self::$evaluations );
	}

	/**
	 * A subscription status change re-checks that reader, so a lapsed reader loses the class.
	 */
	public function test_subscription_status_change_clears_cached_result() {
		$this->make_gate();
		self::$admitted = [ $this->reader_id ];
		wp_set_current_user( $this->reader_id );
		$this->assertContains( self::MEMBER_CLASS, $this->body_classes() );

		self::$admitted = [];
		$this->fire_with_only_member_body_class_listening( 'woocommerce_subscription_status_updated', $this->customer_object( $this->reader_id ), 'on-hold', 'active' );

		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * An order status change re-checks that reader, so a one-time purchase counts at once.
	 */
	public function test_order_status_change_clears_cached_result() {
		$this->make_gate();
		wp_set_current_user( $this->reader_id );
		$this->assertNotContains( self::MEMBER_CLASS, $this->body_classes() );

		self::$admitted = [ $this->reader_id ];
		$this->fire_with_only_member_body_class_listening( 'woocommerce_order_status_changed', 123, 'pending', 'completed', $this->customer_object( $this->reader_id ) );

		$this->assertContains( self::MEMBER_CLASS, $this->body_classes() );
	}

	/**
	 * Fire a WooCommerce hook with every other listener removed.
	 *
	 * Other listeners on these hooks (Data Events, Subscriptions meta) need a real order or
	 * subscription. The test case restores all hooks after each test.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Hook arguments.
	 */
	private function fire_with_only_member_body_class_listening( $hook, ...$args ) {
		global $wp_filter;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_array( $callback['function'] ) || 'Newspack\\Member_Body_Class' !== $callback['function'][0] ) {
					remove_action( $hook, $callback['function'], $priority );
				}
			}
		}
		do_action( $hook, ...$args );
	}

	/**
	 * A stand-in for a subscription or order: the only method the cache clearing reads.
	 *
	 * @param int $customer_id Customer ID.
	 * @return object
	 */
	private function customer_object( $customer_id ) {
		return new class( $customer_id ) {
			/**
			 * Customer ID.
			 *
			 * @var int
			 */
			private $customer_id;

			/**
			 * Constructor.
			 *
			 * @param int $customer_id Customer ID.
			 */
			public function __construct( $customer_id ) {
				$this->customer_id = $customer_id;
			}

			/**
			 * Customer ID.
			 *
			 * @return int
			 */
			public function get_customer_id() {
				return $this->customer_id;
			}
		};
	}
}
