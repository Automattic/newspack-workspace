<?php
/**
 * Tests for per-direction sync capabilities and toggles in the Integration framework.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Newspack\Reader_Activation\Sync;

/**
 * Sync capabilities test case.
 *
 * Covers the push/pull capability declarations (supports_push/supports_pull),
 * the per-direction enable toggles (outgoing_sync_enabled/incoming_sync_enabled),
 * and the dispatch sites that honor them.
 *
 * @group sync-capabilities
 */
class Test_Sync_Capabilities extends \WP_UnitTestCase {

	/**
	 * Push-group settings keys that must follow the push capability.
	 *
	 * @var string[]
	 */
	const PUSH_KEYS = [
		'sync_account_deletion',
		'account_deletion_handling',
		'metadata_prefix',
		'outgoing_sync_enabled',
		'outgoing_metadata_fields',
	];

	/**
	 * Pull-group settings keys that must follow the pull capability.
	 *
	 * @var string[]
	 */
	const PULL_KEYS = [
		'incoming_sync_enabled',
		'incoming_metadata_fields',
	];

	/**
	 * Set up the test environment before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Allow sync on the test (non-production) site so Sync::can_sync() does
		// not bail out inside the dispatcher tests below. Scoped via filter and
		// removed in tear_down.
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		$this->reset_integrations();
	}

	/**
	 * Tear down the test environment after each test.
	 */
	public function tear_down() {
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		delete_option( Integrations::OPTION_NAME );
		\Sample_Integration::$declared_settings_fields = [];
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
	 * Build a pull-only integration (no push capability).
	 *
	 * @param string $id   Integration ID.
	 * @param string $name Integration name.
	 * @return \Sample_Integration
	 */
	private function create_pull_only_integration( $id, $name ) {
		return new class( $id, $name ) extends \Sample_Integration {
			/**
			 * Declare no push capability.
			 *
			 * @return bool
			 */
			public function supports_push(): bool {
				return false;
			}
		};
	}

	/**
	 * Build a push-only integration (no pull capability).
	 *
	 * @param string $id   Integration ID.
	 * @param string $name Integration name.
	 * @return \Sample_Integration
	 */
	private function create_push_only_integration( $id, $name ) {
		return new class( $id, $name ) extends \Sample_Integration {
			/**
			 * Declare no pull capability.
			 *
			 * @return bool
			 */
			public function supports_pull(): bool {
				return false;
			}
		};
	}

	/**
	 * A default integration declares both directions: the push group
	 * (account deletion, prefix, outbound toggle, outgoing fields) and the
	 * pull group (inbound toggle, incoming fields).
	 */
	public function test_default_integration_declares_both_direction_groups() {
		$integration = new \Sample_Integration( 'cap-default', 'Cap Default' );
		$keys        = array_column( $integration->get_settings_fields(), 'key' );

		foreach ( array_merge( self::PUSH_KEYS, self::PULL_KEYS ) as $key ) {
			$this->assertContains( $key, $keys, "Default integration should declare '$key'." );
		}
	}

	/**
	 * A pull-only integration declares none of the push-group fields — no dead
	 * deletion-sync controls, no metadata prefix, no outbound section — while
	 * its own settings and the pull group remain.
	 */
	public function test_pull_only_integration_declares_no_push_group_fields() {
		\Sample_Integration::$declared_settings_fields = [
			[
				'key'     => 'own_api_key',
				'type'    => 'text',
				'default' => '',
			],
		];
		$integration = $this->create_pull_only_integration( 'cap-pull-only', 'Cap Pull Only' );
		$keys = array_column( $integration->get_settings_fields(), 'key' );

		foreach ( self::PUSH_KEYS as $key ) {
			$this->assertNotContains( $key, $keys, "Pull-only integration should not declare '$key'." );
		}
		foreach ( self::PULL_KEYS as $key ) {
			$this->assertContains( $key, $keys, "Pull-only integration should declare '$key'." );
		}
		$this->assertContains( 'own_api_key', $keys, 'Own settings fields remain editable.' );
	}

	/**
	 * A push-only integration declares no pull-group fields.
	 */
	public function test_push_only_integration_declares_no_pull_group_fields() {
		$integration = $this->create_push_only_integration( 'cap-push-only', 'Cap Push Only' );
		$keys        = array_column( $integration->get_settings_fields(), 'key' );

		foreach ( self::PULL_KEYS as $key ) {
			$this->assertNotContains( $key, $keys, "Push-only integration should not declare '$key'." );
		}
		foreach ( self::PUSH_KEYS as $key ) {
			$this->assertContains( $key, $keys, "Push-only integration should declare '$key'." );
		}
	}

	/**
	 * Both directions default to enabled, each toggle pauses only its own
	 * direction, and re-enabling restores it — including the checkbox-false
	 * persistence path (unchecking a default-true checkbox on a fresh site).
	 */
	public function test_direction_toggles_default_enabled_and_pause_independently() {
		$integration = new \Sample_Integration( 'cap-toggles', 'Cap Toggles' );

		$this->assertTrue( $integration->is_push_enabled(), 'Push defaults to enabled.' );
		$this->assertTrue( $integration->is_pull_enabled(), 'Pull defaults to enabled.' );

		$integration->update_settings_field_value( 'outgoing_sync_enabled', false );
		$this->assertFalse( $integration->is_push_enabled(), 'Outbound toggle off pauses push.' );
		$this->assertTrue( $integration->is_pull_enabled(), 'Outbound toggle does not affect pull.' );

		$integration->update_settings_field_value( 'incoming_sync_enabled', false );
		$this->assertFalse( $integration->is_pull_enabled(), 'Inbound toggle off pauses pull.' );

		$integration->update_settings_field_value( 'outgoing_sync_enabled', true );
		$integration->update_settings_field_value( 'incoming_sync_enabled', true );
		$this->assertTrue( $integration->is_push_enabled(), 'Push re-enabled.' );
		$this->assertTrue( $integration->is_pull_enabled(), 'Pull re-enabled.' );
	}

	/**
	 * A missing capability wins over any stored toggle value: the toggle field
	 * isn't declared, its value can't be written through the settings API, and
	 * is_push_enabled() stays false even with a forced option row.
	 */
	public function test_capability_overrides_stored_toggle() {
		$integration = $this->create_pull_only_integration( 'cap-forced', 'Cap Forced' );

		$this->assertFalse(
			$integration->update_settings_field_value( 'outgoing_sync_enabled', true ),
			'Undeclared toggle field is not writable through the settings API.'
		);

		// Even a directly-forced option row must not re-enable a direction the
		// integration does not support.
		update_option( 'newspack_integration_settings_cap-forced_outgoing_sync_enabled', true );
		$this->assertFalse( $integration->is_push_enabled() );
	}

	/**
	 * Pausing a direction preserves the stored field selections, so re-enabling
	 * does not lose the publisher's configuration.
	 */
	public function test_toggling_direction_off_preserves_field_selections() {
		$integration = new \Sample_Integration( 'cap-preserve', 'Cap Preserve' );

		$defaults  = \Newspack\Reader_Activation\Sync\Metadata::get_default_fields();
		$selection = array_values( array_slice( $defaults, 0, 2 ) );
		$this->assertNotEmpty( $selection, 'Precondition: there are default outgoing fields to select.' );
		$integration->update_enabled_outgoing_fields( $selection );
		$integration->update_enabled_incoming_fields( [ 'vip_level' ] );

		$integration->update_settings_field_value( 'outgoing_sync_enabled', false );
		$integration->update_settings_field_value( 'incoming_sync_enabled', false );

		$this->assertSame( $selection, $integration->get_enabled_outgoing_fields(), 'Outgoing selection survives the pause.' );
		$incoming_keys = array_map(
			function ( $field ) {
				return $field->get_key();
			},
			$integration->get_enabled_incoming_fields()
		);
		$this->assertSame( [ 'vip_level' ], $incoming_keys, 'Incoming selection survives the pause.' );
	}

	/**
	 * Sync::has_one_syncable_integration() is a push-path predicate: an active
	 * integration whose outbound sync is paused does not satisfy it, and
	 * re-enabling the toggle restores it.
	 */
	public function test_has_one_syncable_integration_requires_enabled_push() {
		$integration = new \Sample_Integration( 'cap-syncable', 'Cap Syncable' );
		Integrations::register( $integration );
		update_option( Integrations::OPTION_NAME, [ 'cap-syncable' ] );

		$this->assertTrue( Sync::has_one_syncable_integration(), 'Precondition: enabled push-capable integration is syncable.' );

		$integration->update_settings_field_value( 'outgoing_sync_enabled', false );
		$this->assertFalse( Sync::has_one_syncable_integration(), 'Paused outbound sync must not count as syncable.' );

		$errors = Sync::has_one_syncable_integration( true );
		$this->assertWPError( $errors );
		$this->assertContains( 'integration_push_disabled', $errors->get_error_codes() );

		$integration->update_settings_field_value( 'outgoing_sync_enabled', true );
		$this->assertTrue( Sync::has_one_syncable_integration(), 'Re-enabling the toggle restores syncability.' );
	}

	/**
	 * Sync::has_one_syncable_integration() returns false when the only active
	 * integration has no push capability at all.
	 */
	public function test_has_one_syncable_integration_false_for_pull_only() {
		$integration = $this->create_pull_only_integration( 'cap-inbound', 'Cap Inbound' );
		Integrations::register( $integration );
		update_option( Integrations::OPTION_NAME, [ 'cap-inbound' ] );

		$this->assertFalse( Sync::has_one_syncable_integration() );

		$errors = Sync::has_one_syncable_integration( true );
		$this->assertWPError( $errors );
		$this->assertContains( 'integration_push_not_supported', $errors->get_error_codes() );
	}

	/**
	 * The push dispatcher skips integrations whose outbound sync is paused and
	 * resumes pushing once the toggle is re-enabled.
	 */
	public function test_contact_sync_skips_push_disabled_integration() {
		$spy = new \Deletion_Spy_Integration( 'cap-push-spy', 'Cap Push Spy' );
		Integrations::register( $spy );
		update_option( Integrations::OPTION_NAME, [ 'cap-push-spy' ] );

		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [],
		];

		$spy->update_settings_field_value( 'outgoing_sync_enabled', false );
		Contact_Sync::sync( $contact, 'TestContext' );
		$this->assertCount( 0, $spy->push_calls, 'Paused outbound sync must not push.' );

		$spy->update_settings_field_value( 'outgoing_sync_enabled', true );
		Contact_Sync::sync( $contact, 'TestContext' );
		$this->assertCount( 1, $spy->push_calls, 'Re-enabled outbound sync pushes again.' );
	}

	/**
	 * Account-deletion propagation follows the outbound toggle: a paused
	 * integration receives neither hard deletes nor flag-mode pushes, even with
	 * deletion sync itself turned on.
	 */
	public function test_account_deletion_skips_push_disabled_integration() {
		$spy = new \Deletion_Spy_Integration( 'cap-del-spy', 'Cap Del Spy' );
		Integrations::register( $spy );
		update_option( Integrations::OPTION_NAME, [ 'cap-del-spy' ] );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		$spy->update_settings_field_value( 'outgoing_sync_enabled', false );

		Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$this->assertCount( 0, $spy->delete_calls, 'Paused outbound sync must not hard-delete.' );
		$this->assertCount( 0, $spy->push_calls, 'Paused outbound sync must not flag-push.' );

		$spy->update_settings_field_value( 'outgoing_sync_enabled', true );
		Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);
		$this->assertCount( 1, $spy->delete_calls, 'Re-enabled outbound sync propagates the deletion.' );
	}

	/**
	 * Account-deletion propagation never runs for an integration without push
	 * capability, regardless of forced deletion-setting option rows.
	 */
	public function test_account_deletion_skips_push_incapable_integration() {
		$spy = new class( 'cap-del-incapable', 'Cap Del Incapable' ) extends \Deletion_Spy_Integration {
			/**
			 * Declare no push capability.
			 *
			 * @return bool
			 */
			public function supports_push(): bool {
				return false;
			}
		};
		Integrations::register( $spy );
		update_option( Integrations::OPTION_NAME, [ 'cap-del-incapable' ] );
		// Force option rows that would enable deletion sync if the fields were declared.
		update_option( 'newspack_integration_settings_cap-del-incapable_sync_account_deletion', true );
		update_option( 'newspack_integration_settings_cap-del-incapable_account_deletion_handling', 'delete' );

		Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$this->assertCount( 0, $spy->delete_calls );
		$this->assertCount( 0, $spy->push_calls );
	}

	/**
	 * The pull dispatcher skips integrations whose inbound sync is paused and
	 * resumes pulling once the toggle is re-enabled.
	 */
	public function test_contact_pull_skips_pull_disabled_integration() {
		$integration = new class( 'cap-pull-spy', 'Cap Pull Spy' ) extends \Sample_Integration {
			/**
			 * Captured pull_contact_data() calls.
			 *
			 * @var array
			 */
			public $pull_calls = [];

			/**
			 * Pull contact data (records the call).
			 *
			 * @param int $user_id WordPress user ID.
			 * @return array
			 */
			public function pull_contact_data( $user_id ) {
				$this->pull_calls[] = $user_id;
				return [ 'vip_level' => 'gold' ];
			}
		};
		Integrations::register( $integration );
		update_option( Integrations::OPTION_NAME, [ 'cap-pull-spy' ] );
		$integration->update_enabled_incoming_fields( [ 'vip_level' ] );

		$user_id = $this->factory()->user->create();

		$integration->update_settings_field_value( 'incoming_sync_enabled', false );
		Contact_Pull::pull_all( $user_id );
		$this->assertCount( 0, $integration->pull_calls, 'Paused inbound sync must not pull.' );

		$integration->update_settings_field_value( 'incoming_sync_enabled', true );
		Contact_Pull::pull_all( $user_id );
		$this->assertCount( 1, $integration->pull_calls, 'Re-enabled inbound sync pulls again.' );
	}
}
