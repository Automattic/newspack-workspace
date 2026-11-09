<?php
/**
 * Tests for how donation settings saves write each donation product's minimum price.
 *
 * @package Newspack\Tests
 */

use Newspack\Donations;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * A donation product follows the global "Minimum donation" setting until
 * someone sets that product's minimum directly. After that, saving donation or
 * payment settings must leave the product's own minimum alone, because that
 * minimum is what checkout enforces.
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
	 * Set a product's minimum price directly, as an admin does on the product screen.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $minimum    Minimum price.
	 */
	private function set_product_minimum( $product_id, $minimum ) {
		wc_get_product( $product_id )->update_meta_data( '_min_price', $minimum );
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
		$this->assertNotSame( '88', $this->product_minimum( self::CHILD_YEAR ), 'The repair writes the default minimum.' );

		$this->save_settings( '88' );

		$this->assertSame( '88', $this->product_minimum( self::CHILD_YEAR ) );
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
	 * save, and it follows the global minimum from then on.
	 */
	public function test_clearing_a_product_minimum_restores_the_global_minimum_on_save() {
		$this->save_settings( '88' );
		$this->set_product_minimum( self::CHILD_YEAR, '1200' );
		$this->save_settings( '90' );

		$product = wc_get_product( self::CHILD_YEAR );
		$product->update_meta_data( '_min_price', '' );
		$product->save();
		$this->assertSame( '90', $this->product_minimum( self::CHILD_YEAR ) );

		$this->save_settings( '95' );
		$this->assertSame( '95', $this->product_minimum( self::CHILD_YEAR ) );
	}
}
