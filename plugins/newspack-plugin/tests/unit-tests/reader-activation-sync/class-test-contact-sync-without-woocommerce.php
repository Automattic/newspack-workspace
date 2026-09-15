<?php
/**
 * Contact sync on a site without WooCommerce.
 *
 * Every test here runs in a separate process so that `WC_Customer` is really
 * undefined. The suite's `tests/mocks/wc-mocks.php` defines that class at file
 * inclusion time, which makes it exist for the whole main test process; this
 * file deliberately does not load those mocks, and an isolated child process
 * only loads the bootstrap and this file.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events\Connectors\Contact_Sync_Connector;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Sync\Metadata;

/**
 * Contact sync without WooCommerce.
 *
 * @group Contact_Sync_Without_WooCommerce
 */
class Test_Contact_Sync_Without_WooCommerce extends WP_UnitTestCase {

	/**
	 * Number of times a contact reached the sync filter, i.e. a sync was attempted.
	 *
	 * @var int
	 */
	private $sync_attempts = 0;

	/**
	 * Allow syncing on the (non-production) test site and count sync attempts.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		add_filter( 'newspack_esp_sync_contact', [ $this, 'count_sync_attempt' ] );
	}

	/**
	 * Remove the filters added in set_up.
	 */
	public function tear_down() {
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		remove_filter( 'newspack_esp_sync_contact', [ $this, 'count_sync_attempt' ] );
		parent::tear_down();
	}

	/**
	 * Filter callback: count a sync attempt and pass the contact through.
	 *
	 * @param array $contact Contact data.
	 * @return array
	 */
	public function count_sync_attempt( $contact ) {
		$this->sync_attempts++;
		return $contact;
	}

	/**
	 * The tests are meaningless if the WooCommerce mocks leaked into this process.
	 */
	private function assert_no_woocommerce() {
		$this->assertTrue( $this->isInIsolation(), 'This test must run with @runInSeparateProcess.' );
		$this->assertFalse( class_exists( 'WC_Customer' ), 'WC_Customer must be undefined here; do not load wc-mocks.php from this file.' );
	}

	/**
	 * Without WooCommerce, the contact data still carries the metadata that the
	 * providers compute from the WordPress user alone.
	 *
	 * Pinned to the v1 schema: the legacy metadata classes read everything from
	 * a WooCommerce customer, so they have nothing to say about a user without
	 * one either way.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_contact_data_includes_provider_metadata_without_woocommerce() {
		$this->assert_no_woocommerce();
		Metadata::$version = '1.0';

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'no-woo@example.test',
				'role'       => 'subscriber',
			]
		);

		$contact = Contact_Sync::get_contact_data( $user_id );

		$this->assertNotWPError( $contact );
		$this->assertSame( 'no-woo@example.test', $contact['email'] );
		$this->assertArrayHasKey( 'Registration_Date', $contact['metadata'] );
		$this->assertNotSame( '', $contact['metadata']['Registration_Date'] );
	}

	/**
	 * A login on a site without WooCommerce has no orders or subscriptions to
	 * resync, so the handler returns without touching WooCommerce classes and
	 * without attempting a sync.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reader_logged_in_handler_is_a_no_op_without_woocommerce() {
		$this->assert_no_woocommerce();

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'login@example.test',
				'role'       => 'subscriber',
			]
		);

		Contact_Sync_Connector::reader_logged_in(
			time(),
			[
				'user_id' => $user_id,
				'email'   => 'login@example.test',
			],
			'client-id'
		);

		$this->assertSame( 0, $this->sync_attempts );
	}
}
