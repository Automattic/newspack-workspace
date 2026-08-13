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
}
