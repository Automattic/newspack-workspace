<?php
/**
 * Tests Engagement contact metadata.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Data;
use Newspack\Reader_Activation\Sync\Contact_Metadata\Engagement;

/**
 * Test the Engagement metadata class.
 *
 * @group Engagement_Metadata
 */
class Test_Engagement_Metadata extends WP_UnitTestCase {

	/**
	 * User ID for tests.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		$keys = [ 'first_visit_date', 'last_active', 'articles_read', 'paywall_hits', 'favorite_categories' ];
		foreach ( $keys as $key ) {
			delete_user_meta( self::$user_id, Reader_Data::get_meta_key_name( $key ) );
		}
		delete_user_meta( self::$user_id, 'newspack_reader_data_keys' );
		parent::tear_down();
	}

	/**
	 * Helper to set a reader data store item for the test user.
	 *
	 * @param string $key   Reader data key.
	 * @param mixed  $value Value to store.
	 */
	private function set_reader_data( $key, $value ) {
		$meta_key = Reader_Data::get_meta_key_name( $key );
		update_user_meta( self::$user_id, $meta_key, $value );

		// Update the keys registry.
		$keys = get_user_meta( self::$user_id, 'newspack_reader_data_keys', true );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_user_meta( self::$user_id, 'newspack_reader_data_keys', $keys );
		}
	}

	/**
	 * Test first visit date conversion from JS timestamp.
	 */
	public function test_first_visit_date() {
		// JS timestamp: 1750000200000 ms = 2025-06-15 15:10:00 UTC.
		$this->set_reader_data( 'first_visit_date', 1750000200000 );
		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( '2025-06-15 15:10:00', $metadata['First_Visit_Date'] );
	}

	/**
	 * Test last active conversion from JS timestamp.
	 */
	public function test_last_active() {
		$this->set_reader_data( 'last_active', 1750000200000 );
		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( '2025-06-15 15:10:00', $metadata['Last_Active'] );
	}

	/**
	 * Test timestamps are empty when unset.
	 */
	public function test_timestamps_empty_when_unset() {
		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['First_Visit_Date'] );
		$this->assertSame( '', $metadata['Last_Active'] );
	}

	/**
	 * Test paywall hits count.
	 */
	public function test_paywall_hits() {
		$this->set_reader_data( 'paywall_hits', 3 );
		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( 3, $metadata['Paywall_Hits'] );
	}

	/**
	 * Test favorite categories converted to comma-separated names from PHP array.
	 */
	public function test_favorite_categories() {
		$cat1 = self::factory()->category->create( [ 'name' => 'Politics' ] );
		$cat2 = self::factory()->category->create( [ 'name' => 'Climate' ] );
		$this->set_reader_data( 'favorite_categories', [ $cat1, $cat2 ] );

		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Politics,Climate', $metadata['Favorite_Categories'] );
	}

	/**
	 * Test favorite categories from JSON string (as stored by Reader_Data::update_item).
	 */
	public function test_favorite_categories_from_json_string() {
		$cat1 = self::factory()->category->create( [ 'name' => 'Sports' ] );
		$cat2 = self::factory()->category->create( [ 'name' => 'Tech' ] );
		$this->set_reader_data( 'favorite_categories', wp_json_encode( [ $cat1, $cat2 ] ) );

		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( 'Sports,Tech', $metadata['Favorite_Categories'] );
	}

	/**
	 * Test favorite categories empty when unset.
	 */
	public function test_favorite_categories_empty() {
		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Favorite_Categories'] );
	}

	/**
	 * Test payment fields empty without WooCommerce orders.
	 */
	public function test_payment_fields_empty_without_woocommerce_orders() {
		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Payment_Page'] );
		$this->assertSame( '', $metadata['Payment_UTM_Source'] );
		$this->assertSame( '', $metadata['Payment_UTM_Medium'] );
		$this->assertSame( '', $metadata['Payment_UTM_Campaign'] );
	}

	/**
	 * Test total paid empty without WooCommerce.
	 */
	public function test_total_paid_empty_without_woocommerce() {
		$metadata = ( new Engagement( self::$user_id ) )->get_metadata();
		$this->assertSame( '', $metadata['Total_Paid'] );
	}

	/**
	 * Test returns empty without user.
	 */
	public function test_returns_empty_without_user() {
		$metadata = ( new Engagement( 0 ) )->get_metadata();
		$this->assertSame( [], $metadata );
	}
}
