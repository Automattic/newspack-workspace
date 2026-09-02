<?php
/**
 * Tests that a newsletter subscription change reaches the ESP with the
 * "Newsletter Selection" field after the shutdown flush.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events;
use Newspack\Data_Events\Connectors\Contact_Sync_Connector;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/../../mocks/newsletters-mocks.php';

/**
 * The deferred (data event) sync path for newsletter subscription changes.
 *
 * @group Contact_Sync_Queued_Metadata
 */
class Test_Contact_Sync_Queued_Metadata extends WP_UnitTestCase {
	/**
	 * Metadata version before the test class ran.
	 *
	 * @var string
	 */
	private static $original_version;

	/**
	 * Reader under test.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Reader email.
	 *
	 * @var string
	 */
	private $email = 'reader@example.com';

	/**
	 * Class-level setup.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		if ( ! defined( 'NEWSPACK_ALLOW_READER_SYNC' ) ) {
			define( 'NEWSPACK_ALLOW_READER_SYNC', true );
		}
		if ( ! defined( 'NEWSPACK_FORCE_ALLOW_ESP_SYNC' ) ) {
			define( 'NEWSPACK_FORCE_ALLOW_ESP_SYNC', true );
		}
		self::$original_version = Metadata::$version;
	}

	/**
	 * Per-test setup.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Newsletters_Contacts::reset_calls();
		Newspack_Newsletters_Subscription::reset_calls();
		Metadata::$version = 'legacy';
		$this->reset_queue();

		$this->user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => $this->email,
			]
		);

		$esp = Integrations::get_integration( 'esp' );
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );
		Integrations::enable( 'esp' );
	}

	/**
	 * Per-test teardown.
	 */
	public function tear_down() {
		Metadata::$version = self::$original_version;
		$this->reset_queue();
		Newspack_Newsletters_Subscription::reset_calls();
		parent::tear_down();
	}

	/**
	 * Clear the request-scoped sync queue so tests do not leak into each other.
	 */
	private function reset_queue() {
		$reflection = new ReflectionClass( Contact_Sync::class );
		foreach ( [ 'queued_syncs', 'deleted_emails' ] as $name ) {
			$property = $reflection->getProperty( $name );
			$property->setAccessible( true );
			$property->setValue( null, [] );
		}
	}

	/**
	 * Inside a data event the sync is queued and flushed at shutdown. The
	 * reader-data handler for the same event stores the lists, and the flush
	 * rebuilds the contact with the "Newsletter Selection" field from them.
	 */
	public function test_newsletter_selection_reaches_esp_after_shutdown_flush() {
		Contact_Sync_Connector::register_handlers();
		$this->assertContains(
			[ Contact_Sync_Connector::class, 'newsletter_updated' ],
			Data_Events::get_action_handlers( 'newsletter_updated' ),
			'Precondition: the connector handles newsletter_updated.'
		);

		Data_Events::handle(
			'newsletter_updated',
			time(),
			[
				'user_id'       => $this->user_id,
				'email'         => $this->email,
				'lists_added'   => [ '123' ],
				'lists_removed' => [],
			],
			'test-client'
		);
		$this->assertEmpty(
			Newspack_Newsletters_Contacts::$upsert_calls,
			'Inside a data event the sync is deferred to shutdown.'
		);

		Contact_Sync::run_queued_syncs();

		$this->assertCount( 1, Newspack_Newsletters_Contacts::$upsert_calls, 'The queued sync flushes once at shutdown.' );
		$metadata = Newspack_Newsletters_Contacts::$upsert_calls[0]['contact']['metadata'] ?? [];
		$key      = Metadata::get_key( 'newsletter_selection' );
		$this->assertArrayHasKey(
			$key,
			$metadata,
			sprintf( 'Pushed metadata keys: %s', implode( ', ', array_keys( $metadata ) ) )
		);
		$this->assertSame( 'test', $metadata[ $key ], 'The field carries the subscribed list names.' );
	}
}
