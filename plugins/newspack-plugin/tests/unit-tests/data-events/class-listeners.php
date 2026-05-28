<?php
/**
 * Tests for the Data Events listeners (listeners.php).
 *
 * @package Newspack\Tests\Data_Events
 * @group data-events-listeners
 */

namespace Newspack\Tests\Data_Events;

use Newspack\Data_Events;
use Newspack\Reader_Data;

/**
 * Tests for the reader_data_updated Data Events listener.
 *
 * The listener in listeners.php hooks into the `newspack_reader_data_updated`
 * WordPress action and calls Data_Events::dispatch('reader_data_updated', ...).
 * It must NOT dispatch for system-managed read-only keys (active_memberships,
 * active_subscriptions, is_former_donor, is_donor, newsletter_subscribed_lists)
 * because those are updated as side-effects of other Data Event handlers, and
 * dispatching for them would create an infinite loop:
 *
 *   reader_data_updated → access check → newsletter API →
 *   update_newsletter_subscribed_lists → update_item() →
 *   newspack_reader_data_updated (WP action) → dispatch reader_data_updated → …
 *
 * @group data-events-listeners
 */
class Newspack_Test_Data_Events_Listeners extends \WP_UnitTestCase {

	/**
	 * Action names captured during a test via the dispatch hook.
	 *
	 * @var string[]
	 */
	private $dispatched_actions = [];

	/**
	 * Callback for capturing dispatched action names.
	 *
	 * @var callable
	 */
	private $capture_callback;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->dispatched_actions = [];

		// Capture every Data Event dispatch so individual tests can inspect them.
		$this->capture_callback = function( $action_name ) {
			$this->dispatched_actions[] = $action_name;
		};
		add_action( 'newspack_data_event_dispatch', $this->capture_callback, 10, 1 );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		remove_action( 'newspack_data_event_dispatch', $this->capture_callback );
		parent::tear_down();
	}

	/**
	 * Test that updating a read-only key does NOT trigger the reader_data_updated
	 * Data Event dispatch — preventing circular dispatch loops.
	 *
	 * @dataProvider read_only_keys_provider
	 *
	 * @param string $key Read-only key.
	 */
	public function test_readonly_key_does_not_trigger_dispatch( $key ) {
		$user_id = $this->factory->user->create();

		do_action( 'newspack_reader_data_updated', $user_id, $key, 'any_value' );

		$this->assertNotContains(
			'reader_data_updated',
			$this->dispatched_actions,
			"reader_data_updated must NOT be dispatched for read-only key '{$key}'."
		);
	}

	/**
	 * Data provider: all read-only keys that should be filtered from dispatch.
	 *
	 * @return array[]
	 */
	public function read_only_keys_provider() {
		return array_map(
			fn( $key ) => [ $key ],
			Reader_Data::get_read_only_keys()
		);
	}

	// =========================================================================
	// Re-dispatch guard during active Data Event handler
	// =========================================================================

	/**
	 * Test that firing newspack_reader_data_updated from within an active Data
	 * Event handler execution does NOT queue a second reader_data_updated dispatch,
	 * preventing recursive dispatch chains.
	 */
	public function test_no_dispatch_when_inside_active_data_event() {
		$user_id = $this->factory->user->create();

		// Simulate being inside an active Data Event by setting the current event.
		// We use the internal mechanism: dispatch a dummy event and "handle" it so
		// Data_Events::current_event() returns a non-null value.
		//
		// The simplest portable way is to use the reflection to set the private
		// $current_event property directly.
		$reflection = new \ReflectionClass( Data_Events::class );
		$prop        = $reflection->getProperty( 'current_event' );
		$prop->setAccessible( true );
		$prev = $prop->getValue();
		$prop->setValue( null, 'some_other_event' ); // Simulate active handler.

		try {
			do_action( 'newspack_reader_data_updated', $user_id, 'article_views', '10' );
		} finally {
			$prop->setValue( null, $prev ); // Restore.
		}

		$this->assertNotContains(
			'reader_data_updated',
			$this->dispatched_actions,
			'reader_data_updated must NOT be dispatched when already inside a Data Event handler.'
		);
	}
}
