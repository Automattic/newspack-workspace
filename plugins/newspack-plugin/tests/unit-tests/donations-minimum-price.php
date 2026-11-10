<?php
/**
 * Tests for how each donation product's minimum price follows the global minimum donation.
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;
use Newspack\WooCommerce_Products;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * A donation product follows the global "Minimum donation" setting until
 * someone sets that product's minimum directly. After that, saving donation or
 * payment settings must leave the product's own minimum alone, because that
 * minimum is what checkout enforces. Clearing the product's minimum, or setting
 * it to the global one, makes it follow again.
 *
 * @group donations
 */
class Newspack_Test_Donations_Minimum_Price extends WP_UnitTestCase {

	const PARENT_ID   = 9300;
	const CHILD_ONCE  = 9301;
	const CHILD_MONTH = 9302;
	const CHILD_YEAR  = 9303;

	/**
	 * Register the grouped donation product and its three children, with no
	 * minimum price set yet.
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

		update_option( Donations::DONATION_PRODUCT_ID_OPTION, self::PARENT_ID );
		delete_option( Donations::DONATION_SETTINGS_OPTION );
		Donations::reset_legacy_donation_product_ids_cache();
	}

	/**
	 * Leave no memo or mock products behind for the next test.
	 */
	public function tearDown(): void {
		Donations::reset_legacy_donation_product_ids_cache();
		$GLOBALS['products_database'] = [];
		delete_option( Donations::DONATION_SETTINGS_OPTION );
		delete_option( Donations::NEWSPACK_READER_REVENUE_PLATFORM );
		parent::tearDown();
	}

	/**
	 * Save donation settings the way Donations::set_donation_settings() does:
	 * products are written while the option still holds the previous settings,
	 * then the option is updated.
	 *
	 * @param string $minimum Global minimum donation.
	 */
	private function save_settings( $minimum ) {
		Donations::update_donation_product( [ 'minimumDonation' => $minimum ] );
		update_option( Donations::DONATION_SETTINGS_OPTION, [ 'minimumDonation' => $minimum ] );
	}

	/**
	 * Change a product's minimum price without saving the product, so the save
	 * hook doesn't run. The mock store returns this same object, so the change
	 * sticks, and the next settings save is the first code to act on it.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $minimum    Minimum price.
	 */
	private function set_product_minimum( $product_id, $minimum ) {
		wc_get_product( $product_id )->update_meta_data( '_min_price', $minimum );
	}

	/**
	 * Set a product's minimum price and save the product, as the product screen does.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $minimum    Minimum price.
	 */
	private function save_product_minimum( $product_id, $minimum ) {
		$product = wc_get_product( $product_id );
		$product->update_meta_data( '_min_price', $minimum );
		$product->save();
	}

	/**
	 * The minimum price stored on a product.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function product_minimum( $product_id ) {
		return wc_get_product( $product_id )->get_meta( '_min_price' );
	}

	/**
	 * A later save: one that leaves the global minimum unchanged (such as a
	 * payment settings save), and one that changes it.
	 */
	public function later_save_provider() {
		return [
			'global minimum unchanged' => [ '88' ],
			'global minimum changed'   => [ '90' ],
		];
	}

	/**
	 * Products follow the global minimum, except one whose minimum was set directly.
	 *
	 * @dataProvider later_save_provider
	 *
	 * @param string $later_minimum Global minimum in the later save.
	 */
	public function test_saving_settings_keeps_a_product_minimum_set_directly( $later_minimum ) {
		$this->save_settings( '88' );
		$this->assertSame( '88', $this->product_minimum( self::CHILD_YEAR ), 'Products start out following the global minimum.' );

		$this->set_product_minimum( self::CHILD_YEAR, '1200' );
		$this->save_settings( $later_minimum );

		$this->assertSame( '1200', $this->product_minimum( self::CHILD_YEAR ) );
		$this->assertSame( $later_minimum, $this->product_minimum( self::CHILD_ONCE ) );
		$this->assertSame( $later_minimum, $this->product_minimum( self::CHILD_MONTH ) );
	}

	/**
	 * Sites that already have donation products carry no record of what Newspack
	 * last wrote. A product whose minimum matches the saved global minimum is
	 * treated as following it; one that differs was set directly and is kept.
	 */
	public function test_existing_products_follow_only_when_they_match_the_saved_global_minimum() {
		update_option( Donations::DONATION_SETTINGS_OPTION, [ 'minimumDonation' => '88' ] );
		$this->set_product_minimum( self::CHILD_ONCE, '88' );
		$this->set_product_minimum( self::CHILD_MONTH, '88' );
		$this->set_product_minimum( self::CHILD_YEAR, '1200' );

		$this->save_settings( '90' );

		$this->assertSame( '90', $this->product_minimum( self::CHILD_ONCE ) );
		$this->assertSame( '90', $this->product_minimum( self::CHILD_MONTH ) );
		$this->assertSame( '1200', $this->product_minimum( self::CHILD_YEAR ) );
	}

	/**
	 * Newspack can write products a minimum the saved settings don't hold, as the
	 * product repair in get_donation_product() does with the default settings.
	 * Those products still follow, because they match what Newspack last wrote.
	 */
	public function test_products_keep_following_after_newspack_writes_a_minimum_the_settings_lack() {
		$this->save_settings( '88' );
		Donations::update_donation_product();
		$this->assertSame( '5', $this->product_minimum( self::CHILD_YEAR ), 'The repair writes the default minimum.' );

		$this->save_settings( '88' );

		$this->assertSame( '88', $this->product_minimum( self::CHILD_YEAR ) );
	}

	/**
	 * A product set directly keeps its minimum even when it matches a value
	 * Newspack wrote to it before it was set directly.
	 */
	public function test_product_set_back_to_an_earlier_synced_minimum_keeps_it() {
		$this->save_settings( '88' );
		$this->set_product_minimum( self::CHILD_YEAR, '1200' );
		$this->save_settings( '90' );

		$this->set_product_minimum( self::CHILD_YEAR, '88' );
		$this->save_settings( '95' );

		$this->assertSame( '88', $this->product_minimum( self::CHILD_YEAR ) );
	}

	/**
	 * Saving a product set directly forgets what Newspack last wrote to it, so
	 * setting it back to that value before the next settings save still counts
	 * as set directly. Here the global minimum changes without a product write,
	 * as the setup wizard's save does.
	 */
	public function test_product_set_away_and_back_between_settings_saves_keeps_it() {
		$this->save_settings( '88' );
		$this->save_product_minimum( self::CHILD_YEAR, '1200' );
		update_option( Donations::DONATION_SETTINGS_OPTION, [ 'minimumDonation' => '90' ] );

		$this->save_product_minimum( self::CHILD_YEAR, '88' );
		$this->save_settings( '95' );

		$this->assertSame( '88', $this->product_minimum( self::CHILD_YEAR ) );
	}

	/**
	 * Saving a product with the minimum Newspack last wrote, typed differently,
	 * keeps it following: the product screen stores a typed "88.00" as is. Here
	 * the global minimum changes without a product write, so only that record
	 * keeps the product following.
	 */
	public function test_product_saved_with_its_synced_minimum_typed_differently_still_follows() {
		$this->save_settings( '88' );
		$this->save_product_minimum( self::CHILD_YEAR, '88.00' );
		update_option( Donations::DONATION_SETTINGS_OPTION, [ 'minimumDonation' => '90' ] );

		$this->save_settings( '95' );

		$this->assertSame( '95', $this->product_minimum( self::CHILD_YEAR ) );
	}

	/**
	 * Setting a product's minimum to the global minimum makes it follow again.
	 */
	public function test_product_set_to_the_global_minimum_follows_it_again() {
		$this->save_settings( '88' );
		$this->set_product_minimum( self::CHILD_YEAR, '1200' );
		$this->save_settings( '90' );

		$this->set_product_minimum( self::CHILD_YEAR, '90' );
		$this->save_settings( '95' );

		$this->assertSame( '95', $this->product_minimum( self::CHILD_YEAR ) );
	}

	/**
	 * Clearing a product's minimum puts the global minimum back as the product
	 * is saved, rather than leaving it with no minimum until the next settings
	 * save.
	 */
	public function test_clearing_a_product_minimum_restores_the_global_minimum_on_save() {
		$this->save_settings( '88' );
		$this->set_product_minimum( self::CHILD_YEAR, '1200' );
		$this->save_settings( '90' );

		$product = wc_get_product( self::CHILD_YEAR );
		$product->update_meta_data( '_min_price', '' );
		$product->save();

		$this->assertSame( '90', $this->product_minimum( self::CHILD_YEAR ) );
	}

	/**
	 * Products the restore on save must leave alone, each with the meta it is
	 * registered with and whether it is flagged as a donation. Flagged donation
	 * products never follow the global minimum, and the grouped parent has none.
	 */
	public function products_left_alone_provider() {
		return [
			'pay-what-you-want product' => [ 9400, [ '_nyp' => 'yes' ], false ],
			'flagged donation product'  => [ 9400, [ '_nyp' => 'yes' ], true ],
			'grouped donation parent'   => [ self::PARENT_ID, null, false ],
		];
	}

	/**
	 * Restoring the minimum on save applies only to the donation products that
	 * follow the global minimum, though the hook runs on every product save.
	 *
	 * @dataProvider products_left_alone_provider
	 *
	 * @param int        $product_id Product to save.
	 * @param array|null $meta       Meta to register the product with, or null if setUp() registered it.
	 * @param bool       $flagged    Whether the product is flagged as a donation.
	 */
	public function test_saving_another_product_without_a_minimum_leaves_it_without_one( $product_id, $meta, $flagged ) {
		$this->save_settings( '88' );
		if ( null !== $meta ) {
			wc_create_mock_product(
				[
					'id'   => $product_id,
					'type' => 'simple',
					'meta' => $meta,
				]
			);
		}
		if ( $flagged ) {
			update_post_meta( $product_id, WooCommerce_Products::DONATION_FLAG_META_KEY, 'yes' );
		}

		wc_get_product( $product_id )->save();

		$this->assertSame( '', $this->product_minimum( $product_id ) );
	}

	/**
	 * The global minimum saved before a blank one comes in, and the minimum the
	 * save should keep.
	 */
	public function blank_global_minimum_provider() {
		return [
			'a minimum is saved'         => [ '88', '88' ],
			// Sites could save a blank minimum before saves were guarded against it.
			'the saved minimum is blank' => [ '', 5.0 ],
		];
	}

	/**
	 * Clearing the global minimum keeps the one saved before, or the default if
	 * that is blank too, so it can't remove the floor that donation products and
	 * Donate blocks follow. RevEngine is the platform here because on WooCommerce
	 * the save stops at the required-plugins check, which fails in this suite,
	 * before the option is written.
	 *
	 * @dataProvider blank_global_minimum_provider
	 *
	 * @param string       $saved_minimum    Global minimum saved before.
	 * @param string|float $expected_minimum Global minimum the save should keep.
	 */
	public function test_saving_a_blank_global_minimum_keeps_a_floor( $saved_minimum, $expected_minimum ) {
		Donations::set_platform_slug( 'nrh' );
		update_option( Donations::DONATION_SETTINGS_OPTION, [ 'minimumDonation' => $saved_minimum ] );

		Donations::set_donation_settings( [ 'minimumDonation' => '' ] );

		$this->assertSame( $expected_minimum, get_option( Donations::DONATION_SETTINGS_OPTION )['minimumDonation'] );
	}
}
