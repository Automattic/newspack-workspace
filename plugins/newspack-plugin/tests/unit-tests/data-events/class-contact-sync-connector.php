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
use Newspack\Reader_Activation\Sync\Metadata;
use Sample_Integration;

/**
 * Tests for Contact_Sync_Connector::register_handlers().
 *
 * Verifies that the deletion handlers are gated by metadata schema version:
 * legacy-mode sites register only the legacy `reader_deleted` handler, while
 * v1+ sites register only the `reader_delete_sync` handler. Today both run on
 * every site, causing double-processing during user deletion.
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
		$this->set_metadata_version( 'legacy' );
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
	 * Set the metadata schema version via reflection.
	 *
	 * @param string $version The version to set.
	 */
	private function set_metadata_version( $version ) {
		$reflection = new \ReflectionClass( Metadata::class );
		$property   = $reflection->getProperty( 'version' );
		$property->setAccessible( true );
		$property->setValue( null, $version );
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
	 * In legacy metadata mode, register_handlers() should register the
	 * `reader_deleted` handler and skip `reader_delete_sync`.
	 */
	public function test_legacy_mode_registers_reader_deleted_only() {
		$this->set_metadata_version( 'legacy' );
		$this->reset_data_events_handlers();

		Contact_Sync_Connector::register_handlers();

		$actions = $this->get_registered_handler_action_names();
		$this->assertContains( 'reader_deleted', $actions );
		$this->assertNotContains( 'reader_delete_sync', $actions );
	}

	/**
	 * In v1 (non-legacy) metadata mode, register_handlers() should register the
	 * `reader_delete_sync` handler and skip `reader_deleted`.
	 */
	public function test_v1_mode_registers_reader_delete_sync_only() {
		$this->set_metadata_version( '2' );
		$this->reset_data_events_handlers();

		Contact_Sync_Connector::register_handlers();

		$actions = $this->get_registered_handler_action_names();
		$this->assertContains( 'reader_delete_sync', $actions );
		$this->assertNotContains( 'reader_deleted', $actions );
	}

	/**
	 * With sync_esp_delete=true, the legacy reader_deleted handler should
	 * call Newspack_Newsletters_Contacts::delete() on the email.
	 */
	public function test_legacy_reader_deleted_with_sync_true_calls_delete() {
		\Newspack_Newsletters_Contacts::reset_calls();
		\Newspack\Reader_Activation::update_setting( 'sync_esp_delete', true );

		Contact_Sync_Connector::reader_deleted(
			time(),
			[
				'email'   => 'legacy@example.com',
				'user_id' => 1,
			],
			'client-id'
		);

		$this->assertCount( 1, \Newspack_Newsletters_Contacts::$delete_calls );
		$this->assertSame( 'legacy@example.com', \Newspack_Newsletters_Contacts::$delete_calls[0]['email'] );
	}

	/**
	 * With sync_esp_delete=false, the legacy reader_deleted handler should
	 * call Newspack_Newsletters_Contacts::update_lists() (logged into
	 * $add_and_remove_lists_calls by the mock) instead of delete().
	 */
	public function test_legacy_reader_deleted_with_sync_false_calls_update_lists() {
		\Newspack_Newsletters_Contacts::reset_calls();
		\Newspack\Reader_Activation::update_setting( 'sync_esp_delete', false );

		Contact_Sync_Connector::reader_deleted(
			time(),
			[
				'email'   => 'legacy2@example.com',
				'user_id' => 2,
			],
			'client-id'
		);

		$this->assertCount( 0, \Newspack_Newsletters_Contacts::$delete_calls );
		$this->assertCount( 1, \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls );
		$this->assertSame( 'legacy2@example.com', \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls[0]['email'] );
	}

	/**
	 * When the event payload has no email, the legacy reader_deleted handler
	 * should return early without calling any ESP mutation methods.
	 */
	public function test_legacy_reader_deleted_returns_early_when_email_missing() {
		\Newspack_Newsletters_Contacts::reset_calls();

		Contact_Sync_Connector::reader_deleted(
			time(),
			[ 'user_id' => 3 ],
			'client-id'
		);

		$this->assertCount( 0, \Newspack_Newsletters_Contacts::$delete_calls );
		$this->assertCount( 0, \Newspack_Newsletters_Contacts::$add_and_remove_lists_calls );
	}

	/**
	 * End-to-end: in v1 mode, reader_delete_sync should route through the
	 * Contact_Sync dispatcher and call delete_contact() on a registered
	 * integration configured to handle deletion via the 'delete' mode.
	 */
	public function test_reader_delete_sync_routes_to_handle_account_deletion() {
		// Pre-conditions: v1 mode, one spy integration in 'delete' mode.
		$this->set_metadata_version( '2' );
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
