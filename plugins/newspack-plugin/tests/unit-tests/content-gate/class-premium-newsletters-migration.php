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

require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-namespaced-mocks.php';
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
	 * Load the WP-CLI mocks once for the class. Deferred to set_up_before_class()
	 * rather than a file-scope require so this file does not load its mocks before
	 * an earlier-running test class gets a chance to run without them (see
	 * newsletters-namespaced-mocks.php's require above for the class of bug this
	 * avoids).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
	}

	/**
	 * Register the list post type, create two lists, and reset the WP_CLI mock's
	 * recorded output so assertions in one test cannot see another's messages.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type( Subscription_Lists::CPT, [ 'public' => false ] );
		$this->list_a = self::factory()->post->create( [ 'post_type' => Subscription_Lists::CPT ] );
		$this->list_b = self::factory()->post->create( [ 'post_type' => Subscription_Lists::CPT ] );
		\WP_CLI::reset();
	}

	/**
	 * Unregister the list post type so it does not leak into other test classes.
	 */
	public function tear_down() {
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
	 * With custom lists off, the modal shows every list rather than a chosen set, so
	 * the saved list selection is not the carve-out and must be ignored.
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
	 * @param int[] $list_ids     Newsletter list post IDs.
	 * @param bool  $has_purchase Whether to activate paid access with a product rule.
	 *
	 * @return int The gate post ID.
	 */
	private function create_premium_gate( array $list_ids, bool $has_purchase = false ): int {
		return \Newspack\Content_Gate::create_gate(
			[
				'title'               => 'Premium fixture',
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
	 * A live run writes the derived value when it differs from what is stored.
	 */
	public function test_report_auto_signup_live_writes_derived_value_when_it_differs() {
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] ); // Derives to on.

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a, $this->list_b ], false ] );

		$this->assertTrue( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
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
		update_option( 'newspack_premium_newsletters_auto_signup', 0 );
		$this->set_signup_modal_lists( [] );

		$this->invoke_private_static( 'report_auto_signup', [ [ $this->list_a ], false, false ] );

		$this->assertTrue( (bool) get_option( 'newspack_premium_newsletters_auto_signup' ) );
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

		$this->assertSame(
			[
				'plan a' => 11,
				'plan b' => 22,
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
	 * A null entry marks a title claimed by this run rather than a gate found on the
	 * site, so there is no prior gate to retire.
	 */
	public function test_find_superseded_gates_ignores_titles_claimed_by_this_run() {
		$group = [ $this->make_named_plan( 'Plan A' ), $this->make_named_plan( 'Plan B' ) ];

		$superseded = $this->invoke_private_static( 'find_superseded_gates', [ $group, 'plan a | plan b', [ 'plan a' => null ] ] );

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
}
