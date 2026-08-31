<?php
/**
 * Tests the shared subscriber-commerce stand-down check and rule sanitizer.
 *
 * @package Newspack\Tests\Subscriber_Commerce
 */

namespace Newspack\Tests\Subscriber_Commerce;

use Newspack\Product_Targeting;
use Newspack\Subscriber_Commerce;

/**
 * Both subscriber-commerce features read is_enforcement_active() before they
 * touch a price or a purchase, so what it answers — and what it refuses to let
 * a filter override — is the contract under test here.
 *
 * @group subscriber-commerce
 * @group Subscriber_Commerce
 */
class Test_Subscriber_Commerce extends \WP_UnitTestCase {

	/**
	 * Load the WooCommerce mocks, which is what makes wc_get_product() exist.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Drop any enforcement filter a test added.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_subscriber_commerce_enforcement_active' );
		remove_all_filters( 'newspack_reader_activation_enabled' );
		parent::tear_down();
	}

	/**
	 * Turn the feature flag on for a test that needs it.
	 *
	 * Deliberately not in set_up(): NEWSPACK_CONTENT_GATES is a constant, so
	 * defining it for every test would also define it inside the separate
	 * process the feature-disabled test runs in, and that test would then be
	 * asserting against a feature that is switched on.
	 */
	private function enable_gates() {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * The admin is reachable on a gated Woo site. This is what the page's
	 * registration turns on, and it deliberately does not consult Memberships:
	 * a publisher migrating off Memberships configures rules first and
	 * deactivates it afterwards.
	 */
	public function test_admin_is_available_with_gates_and_woocommerce() {
		$this->enable_gates();
		$this->assertTrue( Subscriber_Commerce::is_admin_available() );
	}

	/**
	 * With Memberships absent — the state of the test environment — enforcement
	 * follows admin availability.
	 */
	public function test_enforcement_is_active_without_memberships() {
		$this->enable_gates();
		$this->assertTrue( Subscriber_Commerce::is_enforcement_active() );
	}

	/**
	 * Audience Management is a prerequisite for enforcement, matching the way
	 * content gates go inert without it (NPPD-1846).
	 *
	 * Everything the reader is sent to when a purchase is refused — the
	 * subscription, registration, sign-in — belongs to Audience Management. With
	 * it off, blocking the purchase strands the reader at a notice pointing
	 * somewhere that cannot be reached.
	 *
	 * The admin stays reachable so the publisher can still author rules, which is
	 * the same split is_admin_available() already draws for Memberships.
	 */
	public function test_enforcement_stands_down_without_audience_management() {
		$this->enable_gates();
		add_filter( 'newspack_reader_activation_enabled', '__return_false' );

		$this->assertFalse( Subscriber_Commerce::is_enforcement_active() );
		$this->assertTrue( Subscriber_Commerce::is_admin_available() );
	}

	/**
	 * The filter can stand enforcement down. This is the escape hatch a site
	 * mid-migration uses, so it has to keep working.
	 */
	public function test_filter_can_stand_enforcement_down() {
		$this->enable_gates();
		add_filter( 'newspack_subscriber_commerce_enforcement_active', '__return_false' );
		$this->assertFalse( Subscriber_Commerce::is_enforcement_active() );
	}

	/**
	 * The filter cannot turn enforcement on when the feature is off.
	 *
	 * Features read this as their licence to call WooCommerce APIs, so a filter
	 * answering yes where the underlying conditions say no would turn a
	 * stand-down into a fatal rather than a no-op.
	 *
	 * Runs in a separate process so the NEWSPACK_CONTENT_GATES constant other
	 * suites define cannot leak in and make the feature look enabled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_cannot_enable_enforcement_when_feature_is_disabled() {
		add_filter( 'newspack_subscriber_commerce_enforcement_active', '__return_true' );
		$this->assertFalse( Subscriber_Commerce::is_admin_available() );
		$this->assertFalse( Subscriber_Commerce::is_enforcement_active() );
	}

	/**
	 * A rule saved without an ID gets one. A rule with no ID is one nothing can
	 * address for edit or delete.
	 */
	public function test_sanitize_base_rule_mints_a_missing_id() {
		$rule = Subscriber_Commerce::sanitize_base_rule( [ 'targeting' => 'all' ] );
		$this->assertNotEmpty( $rule['id'] );
	}

	/**
	 * An ID the caller supplied is kept, so saving a rule twice updates it
	 * rather than creating a second one.
	 */
	public function test_sanitize_base_rule_keeps_a_supplied_id() {
		$rule = Subscriber_Commerce::sanitize_base_rule(
			[
				'id'        => 'existing-rule',
				'targeting' => 'all',
			]
		);
		$this->assertSame( 'existing-rule', $rule['id'] );
	}

	/**
	 * An absent `active` flag reads as paused. Callers that mean "live on
	 * create" state it — the alternative would let a partial payload silently
	 * start enforcing a rule that gates a purchase or a price.
	 */
	public function test_sanitize_base_rule_reads_absent_active_as_paused() {
		$rule = Subscriber_Commerce::sanitize_base_rule( [ 'targeting' => 'all' ] );
		$this->assertFalse( $rule['active'] );

		$live = Subscriber_Commerce::sanitize_base_rule(
			[
				'targeting' => 'all',
				'active'    => true,
			]
		);
		$this->assertTrue( $live['active'] );
	}

	/**
	 * An unrecognized targeting mode falls back to the narrowest one rather than
	 * to "everything".
	 */
	public function test_sanitize_base_rule_falls_back_to_specific_products() {
		$rule = Subscriber_Commerce::sanitize_base_rule( [ 'targeting' => 'not-a-mode' ] );
		$this->assertSame( Product_Targeting::TARGETING_PRODUCTS, $rule['targeting'] );
	}

	/**
	 * A malformed creation date is replaced rather than stored, since the value
	 * is displayed back to the publisher.
	 */
	public function test_sanitize_base_rule_rejects_an_impossible_date() {
		$rule = Subscriber_Commerce::sanitize_base_rule(
			[
				'targeting'  => 'all',
				'created_at' => '2026-13-99',
			]
		);
		$this->assertSame( gmdate( 'Y-m-d' ), $rule['created_at'] );
	}
}
