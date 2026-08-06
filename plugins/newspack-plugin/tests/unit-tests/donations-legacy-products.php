<?php
/**
 * Tests for the legacy donation-product resolution in Donations.
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Covers is_donation_product()'s legacy parent/child branch and the request-level
 * memo behind it. The meta-flag fast path short-circuits before this branch, so
 * these products deliberately carry no donation flag.
 */
class Newspack_Test_Donations_Legacy_Products extends WP_UnitTestCase {

	const PARENT_ID = 9100;
	const CHILD_ONCE = 9101;
	const CHILD_MONTH = 9102;
	const CHILD_YEAR = 9103;
	const UNRELATED = 9200;

	/**
	 * Register the grouped parent, its children and an unrelated product, and
	 * point the donation-product option at the parent.
	 */
	public function setUp(): void {
		parent::setUp();
		$GLOBALS['products_database'] = [];

		wc_create_mock_product(
			[
				'id'       => self::PARENT_ID,
				'type'     => 'grouped',
				'children' => [ self::CHILD_MONTH, self::CHILD_YEAR, self::CHILD_ONCE ],
			]
		);
		wc_create_mock_product(
			[
				'id'   => self::CHILD_ONCE,
				'type' => 'simple',
			]
		);
		wc_create_mock_product(
			[
				'id'   => self::CHILD_MONTH,
				'type' => 'subscription',
				'meta' => [ '_subscription_period' => 'month' ],
			]
		);
		wc_create_mock_product(
			[
				'id'   => self::CHILD_YEAR,
				'type' => 'subscription',
				'meta' => [ '_subscription_period' => 'year' ],
			]
		);
		wc_create_mock_product(
			[
				'id'   => self::UNRELATED,
				'type' => 'simple',
			]
		);

		update_option( Donations::DONATION_PRODUCT_ID_OPTION, self::PARENT_ID );
		Donations::reset_legacy_donation_product_ids_cache();
	}

	/**
	 * Leave no memo or mock products behind for the next test.
	 */
	public function tearDown(): void {
		Donations::reset_legacy_donation_product_ids_cache();
		$GLOBALS['products_database'] = [];
		parent::tearDown();
	}

	/**
	 * The grouped parent itself is a donation product.
	 */
	public function test_parent_product_is_a_donation() {
		$this->assertTrue( Donations::is_donation_product( self::PARENT_ID ) );
	}

	/**
	 * Each configured child resolves through the parent's children.
	 */
	public function test_configured_children_are_donations() {
		$this->assertTrue( Donations::is_donation_product( self::CHILD_ONCE ), 'one-time child' );
		$this->assertTrue( Donations::is_donation_product( self::CHILD_MONTH ), 'monthly child' );
		$this->assertTrue( Donations::is_donation_product( self::CHILD_YEAR ), 'yearly child' );
	}

	/**
	 * A product outside the group is not a donation.
	 */
	public function test_unrelated_product_is_not_a_donation() {
		$this->assertFalse( Donations::is_donation_product( self::UNRELATED ) );
	}

	/**
	 * Nothing is a donation when no legacy parent is configured.
	 */
	public function test_no_parent_configured_resolves_false() {
		update_option( Donations::DONATION_PRODUCT_ID_OPTION, 0 );
		Donations::reset_legacy_donation_product_ids_cache();

		$this->assertFalse( Donations::is_donation_product( self::PARENT_ID ) );
		$this->assertFalse( Donations::is_donation_product( self::CHILD_ONCE ) );
	}

	/**
	 * A product whose ID is only in the option but isn't a grouped product is not
	 * treated as the legacy parent.
	 */
	public function test_non_grouped_option_target_resolves_false() {
		update_option( Donations::DONATION_PRODUCT_ID_OPTION, self::UNRELATED );
		Donations::reset_legacy_donation_product_ids_cache();

		$this->assertFalse( Donations::is_donation_product( self::UNRELATED ) );
	}

	/**
	 * Changing the parent option re-resolves rather than serving the memo, since
	 * the memo is keyed on that option.
	 */
	public function test_changing_the_parent_option_re_resolves() {
		$this->assertTrue( Donations::is_donation_product( self::CHILD_ONCE ) );

		wc_create_mock_product(
			[
				'id'       => 9300,
				'type'     => 'grouped',
				'children' => [ self::UNRELATED ],
			]
		);
		update_option( Donations::DONATION_PRODUCT_ID_OPTION, 9300 );

		$this->assertFalse( Donations::is_donation_product( self::CHILD_ONCE ), 'old child no longer resolves' );
		$this->assertTrue( Donations::is_donation_product( self::UNRELATED ), 'new child resolves' );
	}

	/**
	 * Children regenerated under an unchanged parent option are invisible to the
	 * memo's key, which is what reset_legacy_donation_product_ids_cache() is for.
	 */
	public function test_reset_picks_up_children_regenerated_under_the_same_parent() {
		$this->assertTrue( Donations::is_donation_product( self::CHILD_ONCE ) );

		// Same parent ID, different children — what update_donation_product() does
		// when it reuses the parent but recreates a missing frequency.
		wc_create_mock_product(
			[
				'id'       => self::PARENT_ID,
				'type'     => 'grouped',
				'children' => [ 9400 ],
			]
		);
		wc_create_mock_product(
			[
				'id'   => 9400,
				'type' => 'simple',
			]
		);

		$this->assertTrue( Donations::is_donation_product( self::CHILD_ONCE ), 'memo still serves the old set' );

		Donations::reset_legacy_donation_product_ids_cache();

		$this->assertFalse( Donations::is_donation_product( self::CHILD_ONCE ), 'old child gone after reset' );
		$this->assertTrue( Donations::is_donation_product( 9400 ), 'regenerated child resolves after reset' );
	}
}
