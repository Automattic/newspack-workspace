<?php
/**
 * Tests for Contact_Sync_Connector handler registration.
 *
 * @package Newspack\Tests\Data_Events
 */

namespace Newspack\Tests\Data_Events;

use Newspack\Data_Events;
use Newspack\Data_Events\Connectors\Contact_Sync_Connector;
use Newspack\Reader_Activation\Integrations;
use Sample_Integration;

/**
 * Tests for Contact_Sync_Connector::register_handlers().
 *
 * Verifies that register_handlers() always registers the unified
 * `reader_delete_sync` handler (which routes through
 * Contact_Sync::handle_account_deletion()) and never the legacy
 * `reader_deleted` handler, which predates per-integration account-deletion
 * settings and is no longer wired up automatically.
 */
class Newspack_Test_Contact_Sync_Connector extends \WP_UnitTestCase {

	/**
	 * Snapshot of Data_Events::$actions taken in set_up so tear_down can restore
	 * the full action+handler map. Without restore, reset_data_events_handlers()
	 * would wipe handlers registered by other test classes and make the suite
	 * order-dependent.
	 *
	 * @var array<string,callable[]>|null
	 */
	private $actions_snapshot = null;

	/**
	 * Set up: register a syncable Sample_Integration so register_handlers() does
	 * not bail out via Contact_Sync::has_one_syncable_integration().
	 */
	public function set_up() {
		parent::set_up();
		// Allow sync on the test (non-production) site so Sync::can_sync() does
		// not bail out. The filter is scoped (removed in tear_down) and does not
		// pollute later tests, unlike defining NEWSPACK_ALLOW_READER_SYNC globally.
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		$this->actions_snapshot = $this->snapshot_data_events_actions();
		$this->reset_integrations();
		Integrations::register( new Sample_Integration( 'contact-sync-connector-test', 'Contact Sync Connector Test' ) );
		// Mark the integration as enabled so it counts as an active syncable integration.
		update_option( Integrations::OPTION_NAME, [ 'contact-sync-connector-test' ] );
	}

	/**
	 * Tear down: restore baseline state for shared static registries.
	 */
	public function tear_down() {
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		delete_option( Integrations::OPTION_NAME );
		$this->reset_integrations();
		Integrations::register_integrations();
		if ( null !== $this->actions_snapshot ) {
			$this->restore_data_events_actions( $this->actions_snapshot );
			$this->actions_snapshot = null;
		}
		parent::tear_down();
	}

	/**
	 * Capture the full Data_Events::$actions map (action_name => handlers[]).
	 *
	 * @return array
	 */
	private function snapshot_data_events_actions() {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Restore a previously-snapshot'd Data_Events::$actions map.
	 *
	 * @param array $snapshot The actions map to restore.
	 */
	private function restore_data_events_actions( $snapshot ) {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		$property->setValue( null, $snapshot );
	}

	/**
	 * Clear the integrations registry via reflection.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Clear handler callables for all registered data event actions while
	 * preserving the action keys themselves (which are required by
	 * Data_Events::register_handler).
	 */
	private function reset_data_events_handlers() {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		$actions = $property->getValue();
		foreach ( $actions as $action_name => $handlers ) {
			$actions[ $action_name ] = [];
		}
		$property->setValue( null, $actions );
	}

	/**
	 * Return the names of data event actions that currently have at least one
	 * registered handler.
	 *
	 * @return string[]
	 */
	private function get_registered_handler_action_names() {
		$reflection = new \ReflectionClass( Data_Events::class );
		$property   = $reflection->getProperty( 'actions' );
		$property->setAccessible( true );
		$action_names = [];
		foreach ( $property->getValue() as $action_name => $handlers ) {
			if ( ! empty( $handlers ) ) {
				$action_names[] = $action_name;
			}
		}
		return $action_names;
	}

	/**
	 * Register_handlers() should always register the unified
	 * `reader_delete_sync` handler, regardless of the (now-retired) metadata
	 * schema version.
	 */
	public function test_register_handlers_registers_reader_delete_sync() {
		$this->reset_data_events_handlers();

		Contact_Sync_Connector::register_handlers();

		$this->assertContains( 'reader_delete_sync', $this->get_registered_handler_action_names() );
	}

	/**
	 * Register_handlers() should never register the legacy `reader_deleted`
	 * handler: it predated per-integration account-deletion settings and has
	 * been removed — deletion always routes through reader_delete_sync.
	 */
	public function test_register_handlers_never_registers_reader_deleted() {
		$this->reset_data_events_handlers();

		Contact_Sync_Connector::register_handlers();

		$this->assertNotContains( 'reader_deleted', $this->get_registered_handler_action_names() );
		$this->assertFalse( method_exists( Contact_Sync_Connector::class, 'reader_deleted' ) );
	}

	/**
	 * End-to-end: reader_delete_sync should route through the Contact_Sync
	 * dispatcher and call delete_contact() on a registered integration
	 * configured to handle deletion via the 'delete' mode.
	 */
	public function test_reader_delete_sync_routes_to_handle_account_deletion() {
		// Pre-condition: one spy integration in 'delete' mode.
		$reflection = new \ReflectionClass( \Newspack\Reader_Activation\Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );

		$spy = new \Deletion_Spy_Integration( 'spy-e2e', 'Spy E2E' );
		\Newspack\Reader_Activation\Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		\Newspack\Reader_Activation\Integrations::enable( 'spy-e2e' );

		\Newspack\Data_Events\Connectors\Contact_Sync_Connector::reader_delete_sync(
			time(),
			[
				'email'   => 'deleted@example.com',
				'user_id' => 0,
			],
			'client-id'
		);

		$this->assertCount( 1, $spy->delete_calls );
		$this->assertSame( 'deleted@example.com', $spy->delete_calls[0]['email'] );
	}
}
