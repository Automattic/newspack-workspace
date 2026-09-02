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
	/**
	 * Removals reach the ESP too, including one that leaves a gap in the stored
	 * list, which used to strand the value in object shape and drop later changes.
	 */
	public function test_list_removals_reach_the_esp_through_the_shutdown_flush() {
		Newspack_Newsletters_Subscription::$lists = [
			[
				'active' => true,
				'name'   => 'Daily',
				'id'     => '123',
			],
			[
				'active' => true,
				'name'   => 'Weekly',
				'id'     => '456',
			],
		];
		Contact_Sync_Connector::register_handlers();
		$key = Metadata::get_key( 'newsletter_selection' );

		$this->dispatch_update( [ '123', '456' ], [] );
		$this->assertSame( 'Daily, Weekly', $this->flushed_field( $key ) );

		$this->dispatch_update( [], [ '123' ] );
		$this->assertSame( 'Weekly', $this->flushed_field( $key ), 'Removing the first stored list keeps the second.' );

		$this->dispatch_update( [], [ '456' ] );
		$this->assertSame( '', $this->flushed_field( $key ), 'Removing the last list empties the field.' );
	}

	/**
	 * A list change only affects Newsletter Selection, so when the field is not
	 * an enabled outgoing field the event does not upsert the contact at all.
	 */
	public function test_unselected_outgoing_field_does_not_trigger_a_sync() {
		Contact_Sync_Connector::register_handlers();
		Metadata::update_fields( array_values( array_diff( Metadata::get_default_fields(), [ 'Newsletter Selection' ] ) ) );
		$this->dispatch_update( [ '123' ], [] );
		Contact_Sync::run_queued_syncs();
		$this->assertEmpty( Newspack_Newsletters_Contacts::$upsert_calls, 'Nothing to sync when the field is not sent.' );
	}

	/**
	 * Dispatch a newsletter_updated event for the reader.
	 *
	 * @param string[] $added   Lists added.
	 * @param string[] $removed Lists removed.
	 */
	private function dispatch_update( array $added, array $removed ) {
		Data_Events::handle(
			'newsletter_updated',
			time(),
			[
				'user_id'       => $this->user_id,
				'email'         => $this->email,
				'lists_added'   => $added,
				'lists_removed' => $removed,
			],
			'test-client'
		);
	}

	/**
	 * Flush the queue and return the pushed value for the given metadata key.
	 *
	 * @param string $key Prefixed metadata key.
	 * @return string|null The pushed value, or null when the key was not pushed.
	 */
	private function flushed_field( $key ) {
		Newspack_Newsletters_Contacts::reset_calls();
		Contact_Sync::run_queued_syncs();
		$this->assertCount( 1, Newspack_Newsletters_Contacts::$upsert_calls, 'The queued sync flushes once.' );
		return Newspack_Newsletters_Contacts::$upsert_calls[0]['contact']['metadata'][ $key ] ?? null;
	}
}
