<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the contact-name gating in Contact_Sync::get_contact_data() (NPPM-3018).
 *
 * Readers who register without a name get a display name auto-generated from
 * the email local part (Reader_Activation::canonize_user_data()). On sites
 * without WooCommerce, get_contact_data() returns early with the display name
 * as the contact name, so that slug used to sync to the ESP as the reader's
 * name (merge tags rendering "Hi stephenberkeleysidetest").
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/class-woo-less-contact-sync.php';

/**
 * Contact name in Contact_Sync::get_contact_data().
 *
 * @group Contact_Sync_Name
 */
class Test_Contact_Sync_Name extends WP_UnitTestCase {

	private function create_reader( $email, $display_name ) {
		return $this->factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_email'   => $email,
				'display_name' => $display_name,
			]
		);
	}

	public function test_generated_display_name_is_omitted_without_woocommerce() {
		$email   = 'stephen+test@example.com';
		$user_id = $this->create_reader( $email, Reader_Activation::generate_user_nicename( $email ) );

		$contact = Woo_Less_Contact_Sync::get_contact_data( $user_id );

		$this->assertSame( $email, $contact['email'] );
		// Absent rather than '': some ESP providers write empty first/last name
		// fields whenever the name key is present.
		$this->assertArrayNotHasKey( 'name', $contact, 'An auto-generated display name must not be synced as the contact name.' );
	}

	public function test_legacy_generated_display_name_is_omitted_without_woocommerce() {
		$email = 'stephen+test@example.com';
		// Legacy generated construction: the raw email local part.
		$user_id = $this->create_reader( $email, Reader_Activation::strip_email_domain( $email ) );

		$contact = Woo_Less_Contact_Sync::get_contact_data( $user_id );

		$this->assertArrayNotHasKey( 'name', $contact, 'A legacy generated display name must not be synced as the contact name.' );
	}

	public function test_real_display_name_is_synced_without_woocommerce() {
		$user_id = $this->create_reader( 'reader@example.com', 'Sample Reader' );

		$contact = Woo_Less_Contact_Sync::get_contact_data( $user_id );

		$this->assertSame( 'Sample Reader', $contact['name'], 'A reader-provided display name must be synced as the contact name.' );
	}

	public function test_intentionally_saved_generic_display_name_is_synced() {
		$email        = 'lindy@example.com';
		$display_name = Reader_Activation::generate_user_nicename( $email );
		$user_id      = $this->create_reader( $email, $display_name );
		// The reader deliberately saved a display name we would consider generic.
		update_user_meta( $user_id, Reader_Activation::READER_SAVED_GENERIC_DISPLAY_NAME, true );

		$contact = Woo_Less_Contact_Sync::get_contact_data( $user_id );

		$this->assertSame( $display_name, $contact['name'], 'A deliberately saved display name must sync even when it matches the generated construction.' );
	}

	public function test_billing_name_is_used_when_woocommerce_is_available() {
		$original_version  = Metadata::$version;
		Metadata::$version = 'legacy';

		$email   = 'buyer@example.com';
		$user_id = $this->create_reader( $email, Reader_Activation::generate_user_nicename( $email ) );
		// The WC_Customer mock reads billing names from first/last name user meta.
		update_user_meta( $user_id, 'first_name', 'Sample' );
		update_user_meta( $user_id, 'last_name', 'Buyer' );

		try {
			$contact = Contact_Sync::get_contact_data( $user_id );
		} finally {
			Metadata::$version = $original_version;
		}

		$this->assertSame( 'Sample Buyer', $contact['name'], 'With WooCommerce available the billing name is the contact name.' );
	}
}
