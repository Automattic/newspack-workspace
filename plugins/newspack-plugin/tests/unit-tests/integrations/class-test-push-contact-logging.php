<?php
/**
 * Tests for the log entry written around every integration push.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;

require_once __DIR__ . '/class-deletion-spy-integration.php';

/**
 * Push contact logging test case.
 *
 * Every push, whatever the integration, must leave one `newspack_log`
 * record carrying the payload the integration received and the result it
 * reported, so the manager log shows syncs for integrations that never go
 * through Newspack Newsletters.
 *
 * @group integrations
 * @group push-contact-log
 */
class Test_Push_Contact_Logging extends \WP_UnitTestCase {

	/**
	 * Captured `newspack_log` entries.
	 *
	 * @var array
	 */
	private $entries = [];

	/**
	 * The listener added to `newspack_log`, kept so tear_down removes only it.
	 *
	 * @var callable
	 */
	private $listener;

	/**
	 * Set up the test environment before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Allow sync on the test (non-production) site so Sync::can_sync() does
		// not bail out of the dispatcher test below. Scoped via filter and
		// removed in tear_down.
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		$this->reset_integrations();
		$this->entries  = [];
		$this->listener = function ( $code, $message, $params ) {
			$this->entries[] = compact( 'code', 'message', 'params' );
		};
		add_action( 'newspack_log', $this->listener, 10, 3 );
	}

	/**
	 * Tear down the test environment after each test.
	 */
	public function tear_down() {
		remove_action( 'newspack_log', $this->listener, 10 );
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		delete_option( Integrations::OPTION_NAME );
		$this->reset_integrations();
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset the static integrations registry so each test starts clean.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * The captured entries written by the push wrapper.
	 *
	 * @return array
	 */
	private function push_entries() {
		return array_values(
			array_filter(
				$this->entries,
				function ( $entry ) {
					return 'newspack_sync_push_contact' === $entry['code'];
				}
			)
		);
	}

	/**
	 * The wrapper is the framework's only push entry point, so an integration
	 * must not be able to replace it and skip the log.
	 */
	public function test_push_contact_is_final() {
		$method = new \ReflectionMethod( Integration::class, 'push_contact' );
		$this->assertTrue( $method->isFinal() );
	}

	/**
	 * A successful push hands the contact and options to push_contact_data()
	 * untouched and records one debug entry carrying that same payload.
	 */
	public function test_push_contact_records_a_success_entry_with_the_payload_it_handed_over() {
		$spy     = new \Deletion_Spy_Integration( 'log-spy', 'Log Spy' );
		$contact = [
			'email'    => 'reader@example.com',
			'name'     => 'Reader',
			'metadata' => [ 'NP_Account' => 7 ],
		];
		$options = [ 'skip_lists' => true ];

		$result = $spy->push_contact( $contact, 'TestContext', null, $options );

		$this->assertTrue( $result );
		$this->assertCount( 1, $spy->push_calls, 'The wrapper delegates to push_contact_data() exactly once.' );
		$this->assertSame( $contact, $spy->push_calls[0]['contact'] );
		$this->assertSame( $options, $spy->push_calls[0]['options'] );

		$entries = $this->push_entries();
		$this->assertCount( 1, $entries );
		$params = $entries[0]['params'];
		$this->assertSame( 'debug', $params['type'] );
		$this->assertSame( 'reader@example.com', $params['user_email'] );
		$this->assertSame( 'log-spy', $params['data']['integration_id'] );
		$this->assertSame( 'TestContext', $params['data']['context'] );
		$this->assertSame( $contact, $params['data']['contact'] );
		$this->assertSame( $options, $params['data']['options'] );
		$this->assertSame( [], $params['data']['errors'] );
		$this->assertSame( [], $params['data']['status'] );
	}

	/**
	 * A failed push records an error entry with the integration's messages and
	 * codes, and returns the integration's WP_Error unchanged so the retry
	 * machinery sees exactly what the integration reported.
	 */
	public function test_push_contact_records_a_failure_entry_and_returns_the_error_untouched() {
		$spy              = new \Deletion_Spy_Integration( 'log-spy', 'Log Spy' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );
		$contact          = [
			'email'    => 'reader@example.com',
			'metadata' => [],
		];

		$result = $spy->push_contact( $contact, 'TestContext' );

		$this->assertSame( $spy->push_result, $result );

		$entries = $this->push_entries();
		$this->assertCount( 1, $entries );
		$params = $entries[0]['params'];
		$this->assertSame( 'error', $params['type'] );
		$this->assertSame( [ 'Provider down' ], $params['data']['errors'] );
		$this->assertSame( [ 'provider_down' ], $params['data']['status'] );
	}

	/**
	 * The dispatcher pushes through the wrapper, so a sync records the
	 * prepared (filtered, prefixed) contact the integration actually received
	 * rather than the raw input.
	 */
	public function test_contact_sync_records_the_prepared_contact_for_each_integration() {
		$spy = new \Deletion_Spy_Integration( 'log-spy', 'Log Spy' );
		Integrations::register( $spy );
		update_option( Integrations::OPTION_NAME, [ 'log-spy' ] );

		Contact_Sync::sync(
			[
				'email'    => 'reader@example.com',
				'metadata' => [ 'account' => 7 ],
			],
			'TestContext'
		);

		$this->assertCount( 1, $spy->push_calls );
		$entries = $this->push_entries();
		$this->assertCount( 1, $entries );
		$data = $entries[0]['params']['data'];
		$this->assertSame( $spy->push_calls[0]['contact'], $data['contact'], 'The entry carries the contact the integration received.' );
		$this->assertArrayNotHasKey( 'account', $data['contact']['metadata'], 'Raw keys are prefixed or dropped before the entry is written.' );
	}
}
