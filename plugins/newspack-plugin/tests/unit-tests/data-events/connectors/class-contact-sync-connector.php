<?php
/**
 * Tests for the Contact_Sync_Connector data events handler.
 *
 * @package Newspack\Tests\Data_Events\Connectors
 * @group data-events-connectors
 */

namespace Newspack\Tests\Data_Events\Connectors;

use Newspack\Data_Events\Connectors\Contact_Sync_Connector;

/**
 * Test the Contact_Sync_Connector class.
 *
 * @group data-events-connectors
 */
class Newspack_Test_Contact_Sync_Connector extends \WP_UnitTestCase {

	/**
	 * Load newsletter mocks once per class so the static helpers used by
	 * Contact_Sync_Connector::newsletter_updated() are available.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/mocks/newsletters-mocks.php';
	}

	/**
	 * Reset mock state between tests.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack_Newsletters_Subscription::reset_calls();
	}

	/**
	 * Regression test: when a `newsletter_subscribed` event arrives with a
	 * `$contact` array that has no `metadata` key (which is allowed by the
	 * contact contract — see Newspack_Newsletters_Contacts::subscribe()),
	 * the handler must not throw `array_merge(): Argument #1 must be of type
	 * array, null given` under PHP 8+. This guards the metadata merge in
	 * Contact_Sync_Connector::newsletter_updated().
	 */
	public function test_newsletter_updated_tolerates_missing_metadata() {
		$data = [
			'user_id'  => 1,
			'email'    => 'reader@example.com',
			'provider' => 'mailchimp',
			// Contact intentionally omits the 'metadata' key — this is the
			// shape dispatched by Newspack_Newsletters_Contacts::subscribe()
			// when no extra metadata is provided by the caller.
			'contact'  => [
				'email' => 'reader@example.com',
				'name'  => 'Test Reader',
			],
			'lists'    => [ '123' ],
		];

		Contact_Sync_Connector::newsletter_updated( time(), $data );

		$this->assertTrue( true, 'Handler returned without throwing a TypeError.' );
	}
}
