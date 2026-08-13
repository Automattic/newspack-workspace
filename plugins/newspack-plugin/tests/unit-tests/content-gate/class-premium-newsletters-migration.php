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
	 * Register the list post type and create two lists.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type( Subscription_Lists::CPT, [ 'public' => false ] );
		$this->list_a = self::factory()->post->create( [ 'post_type' => Subscription_Lists::CPT ] );
		$this->list_b = self::factory()->post->create( [ 'post_type' => Subscription_Lists::CPT ] );
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
}
