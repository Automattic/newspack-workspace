<?php
/**
 * Tests for account-deletion handling in the Integration framework.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations;
use Sample_Integration;

/**
 * Account deletion test case.
 *
 * @group account-deletion
 */
class Test_Account_Deletion extends \WP_UnitTestCase {

	/**
	 * Test integration instance.
	 *
	 * @var Sample_Integration
	 */
	private $integration;

	/**
	 * Set up the test environment before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Allow sync on the test (non-production) site so Sync::can_sync() does
		// not bail out inside the dispatcher tests below. Use the filter rather
		// than defining NEWSPACK_ALLOW_READER_SYNC so the change is scoped to
		// this test class and removed in tear_down.
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		$this->reset_integrations();
		$this->integration = new Sample_Integration( 'deletion-test', 'Deletion Test' );
		Integrations::register( $this->integration );
	}

	/**
	 * Tear down the test environment after each test.
	 */
	public function tear_down() {
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
	 * The base Integration::delete_contact() should return a "not_implemented" WP_Error.
	 */
	public function test_delete_contact_default_returns_not_implemented_error() {
		$result = $this->integration->delete_contact( 'reader@example.com' );
		$this->assertWPError( $result );
		$this->assertSame( 'not_implemented', $result->get_error_code() );
	}

	/**
	 * Settings fields should include the new sync_account_deletion and
	 * account_deletion_handling keys auto-appended by the base class.
	 */
	public function test_settings_include_sync_account_deletion_and_handling() {
		$keys = array_column( $this->integration->get_settings_fields(), 'key' );
		$this->assertContains( 'sync_account_deletion', $keys );
		$this->assertContains( 'account_deletion_handling', $keys );
	}

	/**
	 * The account_deletion_handling field should declare a `condition`
	 * predicate that gates it on the sync_account_deletion checkbox.
	 */
	public function test_account_deletion_handling_declares_condition_on_checkbox() {
		$fields   = $this->integration->get_settings_fields();
		$handling = null;
		foreach ( $fields as $field ) {
			if ( 'account_deletion_handling' === $field['key'] ) {
				$handling = $field;
				break;
			}
		}
		$this->assertIsArray( $handling );
		$this->assertSame( 'sync_account_deletion', $handling['condition']['field'] ?? null );
		$this->assertSame( true, $handling['condition']['equals'] ?? null );
	}

	/**
	 * With no legacy option and no per-integration option set,
	 * sync_account_deletion should default to true.
	 */
	public function test_sync_account_deletion_defaults_to_true_when_no_legacy() {
		delete_option( 'newspack_reader_activation_sync_esp_delete' );
		delete_option( 'newspack_integration_settings_deletion-test_sync_account_deletion' );
		$this->assertTrue( (bool) $this->integration->get_settings_field_value( 'sync_account_deletion' ) );
	}

	/**
	 * For integrations that do NOT advertise hard-delete capability (the default),
	 * `account_deletion_handling` should default to `flag` so they don't return
	 * `not_implemented` errors on every deletion.
	 */
	public function test_account_deletion_handling_defaults_to_flag_when_hard_delete_unsupported() {
		delete_option( 'newspack_integration_settings_deletion-test_account_deletion_handling' );
		// Sample_Integration does not override supports_hard_delete(), so default → false.
		$this->assertFalse( $this->integration->supports_hard_delete() );
		$this->assertSame( 'flag', $this->integration->get_settings_field_value( 'account_deletion_handling' ) );
	}

	/**
	 * When the integration supports hard delete, both options should be exposed and the
	 * default should be `delete`. ESP is the canonical example.
	 */
	public function test_account_deletion_handling_defaults_to_delete_when_hard_delete_supported() {
		// Anonymous subclass that opts into hard delete.
		$integration = new class( 'deletion-test-hard', 'Hard Delete' ) extends \Sample_Integration {
			/**
			 * Opt into hard delete for this test.
			 *
			 * @return bool
			 */
			public function supports_hard_delete(): bool {
				return true;
			}
		};
		\Newspack\Reader_Activation\Integrations::register( $integration );

		$keys = array_column( $integration->get_account_deletion_fields(), 'key' );
		$this->assertContains( 'account_deletion_handling', $keys );

		$handling_field = null;
		foreach ( $integration->get_account_deletion_fields() as $field ) {
			if ( 'account_deletion_handling' === $field['key'] ) {
				$handling_field = $field;
				break;
			}
		}
		$this->assertIsArray( $handling_field );
		$option_values = array_column( $handling_field['options'], 'value' );
		$this->assertContains( 'delete', $option_values );
		$this->assertContains( 'flag', $option_values );
		$this->assertSame( 'delete', $handling_field['default'] );
	}

	/**
	 * When the integration does not support hard delete, the `delete` option must
	 * be hidden so publishers can't pick a mode that would just return
	 * `not_implemented` on every deletion.
	 */
	public function test_account_deletion_handling_options_omit_delete_when_unsupported() {
		$handling_field = null;
		foreach ( $this->integration->get_account_deletion_fields() as $field ) {
			if ( 'account_deletion_handling' === $field['key'] ) {
				$handling_field = $field;
				break;
			}
		}
		$this->assertIsArray( $handling_field );
		$option_values = array_column( $handling_field['options'], 'value' );
		$this->assertNotContains( 'delete', $option_values );
		$this->assertContains( 'flag', $option_values );
	}

	/**
	 * A legacy sync_esp_delete=true migrates to sync_account_deletion=true with
	 * handling='delete' (hard delete), persisting both derived options.
	 */
	public function test_migration_legacy_true_maps_to_delete_mode() {
		$integration = new class( 'deletion-test-legacy-true', 'Legacy True' ) extends \Sample_Integration {
			/**
			 * Opt into hard delete so the 'delete' handling target is valid.
			 *
			 * @return bool
			 */
			public function supports_hard_delete(): bool {
				return true;
			}
		};
		Integrations::register( $integration );
		delete_option( 'newspack_integration_settings_deletion-test-legacy-true_sync_account_deletion' );
		delete_option( 'newspack_integration_settings_deletion-test-legacy-true_account_deletion_handling' );
		delete_option( 'newspack_reader_activation_sync_esp_delete' );
		add_option( 'newspack_reader_activation_sync_esp_delete', true );

		$this->assertTrue( (bool) $integration->get_settings_field_value( 'sync_account_deletion' ) );
		$this->assertSame( 'delete', $integration->get_settings_field_value( 'account_deletion_handling' ) );

		// Migration should persist the derived options after resolution.
		$this->assertNotFalse(
			get_option( 'newspack_integration_settings_deletion-test-legacy-true_sync_account_deletion', false )
		);
		$this->assertSame(
			'delete',
			get_option( 'newspack_integration_settings_deletion-test-legacy-true_account_deletion_handling' )
		);
	}

	/**
	 * A legacy sync_esp_delete=false keeps deletion sync ON but maps to handling='flag'
	 * rather than disabling sync — preserving the old "signal the deletion without hard
	 * deleting" posture for opted-out sites. Checked against a hard-delete integration
	 * whose handling default would otherwise be 'delete', so 'flag' proves the mapping.
	 */
	public function test_migration_legacy_false_maps_to_flag_mode() {
		$integration = new class( 'deletion-test-legacy-false', 'Legacy False' ) extends \Sample_Integration {
			/**
			 * Opt into hard delete so the handling default would be 'delete' absent migration.
			 *
			 * @return bool
			 */
			public function supports_hard_delete(): bool {
				return true;
			}
		};
		Integrations::register( $integration );
		delete_option( 'newspack_integration_settings_deletion-test-legacy-false_sync_account_deletion' );
		delete_option( 'newspack_integration_settings_deletion-test-legacy-false_account_deletion_handling' );
		delete_option( 'newspack_reader_activation_sync_esp_delete' );
		add_option( 'newspack_reader_activation_sync_esp_delete', false );

		$this->assertTrue(
			(bool) $integration->get_settings_field_value( 'sync_account_deletion' ),
			'Legacy false still propagated a deletion, so sync stays enabled after migration.'
		);
		$this->assertSame(
			'flag',
			$integration->get_settings_field_value( 'account_deletion_handling' ),
			'Legacy false maps to flag mode, not the hard-delete default.'
		);
	}

	/**
	 * A legacy sync_esp_delete=true on an integration that can't hard-delete must migrate
	 * to handling='flag', not 'delete'. Absent the supports_hard_delete() clamp, the
	 * migration would persist 'delete' for such an integration and the deletion loop would
	 * return `not_implemented` on every deletion — the same gap the field default avoids.
	 */
	public function test_migration_legacy_true_maps_to_flag_when_hard_delete_unsupported() {
		// Sample_Integration does not override supports_hard_delete(), so it returns false.
		$integration = new \Sample_Integration( 'deletion-test-legacy-true-no-hd', 'Legacy True No Hard Delete' );
		Integrations::register( $integration );
		delete_option( 'newspack_integration_settings_deletion-test-legacy-true-no-hd_sync_account_deletion' );
		delete_option( 'newspack_integration_settings_deletion-test-legacy-true-no-hd_account_deletion_handling' );
		delete_option( 'newspack_reader_activation_sync_esp_delete' );
		add_option( 'newspack_reader_activation_sync_esp_delete', true );

		$this->assertFalse(
			$integration->supports_hard_delete(),
			'Guard precondition: this integration cannot hard-delete.'
		);
		$this->assertTrue(
			(bool) $integration->get_settings_field_value( 'sync_account_deletion' ),
			'Legacy true still propagated a deletion, so sync stays enabled after migration.'
		);
		$this->assertSame(
			'flag',
			$integration->get_settings_field_value( 'account_deletion_handling' ),
			'Legacy true clamps to flag when the integration cannot hard-delete, not delete.'
		);

		// The clamped value should be persisted, not recomputed on every read.
		$this->assertSame(
			'flag',
			get_option( 'newspack_integration_settings_deletion-test-legacy-true-no-hd_account_deletion_handling' )
		);
	}

	/**
	 * When an integration is configured with handling='delete', the dispatcher
	 * must call $integration->delete_contact() and not push the contact.
	 */
	public function test_handle_account_deletion_calls_delete_when_handling_delete() {
		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'spy-a', 'Spy A' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		Integrations::enable( 'spy-a' );

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$this->assertCount( 1, $spy->delete_calls );
		$this->assertSame( 'reader@example.com', $spy->delete_calls[0]['email'] );
		$this->assertCount( 0, $spy->push_calls );
	}

	/**
	 * When handling='flag', the dispatcher must push the contact with an
	 * `account_deleted` datetime in metadata and not call delete_contact.
	 */
	public function test_handle_account_deletion_calls_push_with_timestamp_when_handling_flag() {
		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'spy-b', 'Spy B' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'flag' );
		Integrations::enable( 'spy-b' );

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$this->assertCount( 0, $spy->delete_calls );
		$this->assertCount( 1, $spy->push_calls );

		$pushed = $spy->push_calls[0]['contact'];
		$this->assertSame( 'reader@example.com', $pushed['email'] );
		$this->assertArrayHasKey( 'account_deleted', $pushed['metadata'] );
		$this->assertNotFalse(
			strtotime( $pushed['metadata']['account_deleted'] ),
			'account_deleted must be a strtotime-parseable timestamp.'
		);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$pushed['metadata']['account_deleted'],
			'account_deleted must use the Y-m-d H:i:s format that peer datetime metadata uses.'
		);
		// Flag mode must also re-inject the historical membership_status=user-deleted
		// signal under the prefixed key, for backward compatibility with publisher
		// automations that keyed on it before the per-integration deletion settings.
		$prefix = $spy->get_metadata_prefix();
		$this->assertSame(
			'user-deleted',
			$pushed['metadata'][ $prefix . 'Membership_Status' ],
			'Flag mode must re-inject membership_status=user-deleted under the prefixed key.'
		);
	}

	/**
	 * Integrations with sync_account_deletion=false must be skipped entirely:
	 * neither delete_contact nor push_contact_data should be called on them.
	 */
	public function test_handle_account_deletion_skips_when_sync_off() {
		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'spy-c', 'Spy C' );
		Integrations::register( $spy );
		// WP's update_option() short-circuits when storing a boolean false against
		// an absent option (the "no change" check compares against false). Use
		// add_option directly to make sure the value is persisted.
		delete_option( 'newspack_integration_settings_spy-c_sync_account_deletion' );
		add_option( 'newspack_integration_settings_spy-c_sync_account_deletion', false );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		Integrations::enable( 'spy-c' );

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
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
	 * Each integration's deletion routing is independent: a single dispatcher
	 * call can simultaneously delete from one integration, flag-push another,
	 * and skip a third based on per-integration settings.
	 */
	public function test_handle_account_deletion_routes_mixed_integrations_independently() {
		$this->reset_integrations();
		$delete_spy = new \Deletion_Spy_Integration( 'spy-d', 'Spy D' );
		$flag_spy   = new \Deletion_Spy_Integration( 'spy-e', 'Spy E' );
		$off_spy    = new \Deletion_Spy_Integration( 'spy-f', 'Spy F' );
		Integrations::register( $delete_spy );
		Integrations::register( $flag_spy );
		Integrations::register( $off_spy );

		$delete_spy->update_settings_field_value( 'sync_account_deletion', true );
		$delete_spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		$flag_spy->update_settings_field_value( 'sync_account_deletion', true );
		$flag_spy->update_settings_field_value( 'account_deletion_handling', 'flag' );
		// WP's update_option() short-circuits on boolean false against an absent
		// option; persist via add_option to ensure the off state sticks.
		delete_option( 'newspack_integration_settings_spy-f_sync_account_deletion' );
		add_option( 'newspack_integration_settings_spy-f_sync_account_deletion', false );

		Integrations::enable( 'spy-d' );
		Integrations::enable( 'spy-e' );
		Integrations::enable( 'spy-f' );

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$this->assertCount( 1, $delete_spy->delete_calls );
		$this->assertCount( 0, $delete_spy->push_calls );

		$this->assertCount( 0, $flag_spy->delete_calls );
		$this->assertCount( 1, $flag_spy->push_calls );

		$this->assertCount( 0, $off_spy->delete_calls );
		$this->assertCount( 0, $off_spy->push_calls );
	}

	/**
	 * When delete_contact() returns a WP_Error, the dispatcher must fire
	 * `newspack_sync_contact_failed` so Alert_Manager can record the failure.
	 * The payload's `context` stays a string (matching the existing contract),
	 * and a sibling `mode` key carries the deletion mode for downstream filtering.
	 */
	public function test_handle_account_deletion_fires_alert_action_on_delete_failure() {
		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'spy-fail-delete', 'Spy Fail Delete' );
		$spy->delete_result = new \WP_Error( 'boom', 'ESP rejected delete' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		Integrations::enable( 'spy-fail-delete' );

		$captured = [];
		$listener = function ( $payload ) use ( &$captured ) {
			$captured[] = $payload;
		};
		add_action( 'newspack_sync_contact_failed', $listener );

		$result = \Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		remove_action( 'newspack_sync_contact_failed', $listener );

		$this->assertWPError( $result );
		$this->assertCount( 1, $captured, 'newspack_sync_contact_failed must fire once on delete failure.' );
		$this->assertSame( 'spy-fail-delete', $captured[0]['integration_id'] );
		$this->assertSame( 'reader@example.com', $captured[0]['contact']['email'] );
		$this->assertSame( 'TestContext', $captured[0]['context'] );
		$this->assertSame( 'delete', $captured[0]['mode'] );
		$this->assertSame( 'ESP rejected delete', $captured[0]['reason'] );
	}

	/**
	 * When push_contact_data() returns a WP_Error in flag mode, the dispatcher
	 * must fire `newspack_sync_contact_failed` with a sibling `mode` key set to
	 * `flag` (the `context` field stays a string per the existing contract).
	 */
	public function test_handle_account_deletion_fires_alert_action_on_flag_failure() {
		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'spy-fail-flag', 'Spy Fail Flag' );
		$spy->push_result = new \WP_Error( 'boom', 'ESP rejected push' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'flag' );
		Integrations::enable( 'spy-fail-flag' );

		$captured = [];
		$listener = function ( $payload ) use ( &$captured ) {
			$captured[] = $payload;
		};
		add_action( 'newspack_sync_contact_failed', $listener );

		$result = \Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		remove_action( 'newspack_sync_contact_failed', $listener );

		$this->assertWPError( $result );
		$this->assertCount( 1, $captured, 'newspack_sync_contact_failed must fire once on flag-push failure.' );
		$this->assertSame( 'spy-fail-flag', $captured[0]['integration_id'] );
		$this->assertSame( 'reader@example.com', $captured[0]['contact']['email'] );
		$this->assertArrayHasKey( 'account_deleted', $captured[0]['contact']['metadata'] );
		$this->assertSame( 'TestContext', $captured[0]['context'] );
		$this->assertSame( 'flag', $captured[0]['mode'] );
		$this->assertSame( 'ESP rejected push', $captured[0]['reason'] );
	}

	/**
	 * A queued upsert from earlier in the same request (e.g. WCS firing
	 * subscription_updated during the delete_user cascade) must be dropped before
	 * deletion runs, otherwise the shutdown queue would recreate the contact
	 * right after the ESP delete.
	 */
	public function test_handle_account_deletion_drops_queued_sync_for_email() {
		$reflection = new \ReflectionClass( \Newspack\Reader_Activation\Contact_Sync::class );
		$property   = $reflection->getProperty( 'queued_syncs' );
		$property->setAccessible( true );
		$property->setValue(
			null,
			[
				'reader@example.com' => [
					'contexts'     => [ 'subscription_updated' ],
					'contact'      => [
						'email'    => 'reader@example.com',
						'metadata' => [ 'subscription_status' => 'active' ],
					],
					'as_action_id' => null,
				],
				'other@example.com'  => [
					'contexts'     => [ 'subscription_updated' ],
					'contact'      => [ 'email' => 'other@example.com' ],
					'as_action_id' => null,
				],
			]
		);

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$queued = $property->getValue();
		$this->assertArrayNotHasKey(
			'reader@example.com',
			$queued,
			'A queued sync for the deleted email must be dropped before deletion runs.'
		);
		$this->assertArrayHasKey(
			'other@example.com',
			$queued,
			'Queued syncs for unrelated emails must be preserved.'
		);

		// Clean up.
		$property->setValue( null, [] );
	}

	/**
	 * Regression: a sync queued by a *later* event in the same request (after the
	 * deletion already ran) must not be flushed at shutdown, or run_queued_syncs()
	 * would resurrect a just-deleted contact — a silent right-to-be-forgotten
	 * failure that depends on delete_user hook ordering.
	 */
	public function test_run_queued_syncs_does_not_resurrect_deleted_email() {
		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'spy-resurrect', 'Spy Resurrect' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		Integrations::enable( 'spy-resurrect' );

		$reflection = new \ReflectionClass( \Newspack\Reader_Activation\Contact_Sync::class );
		$queued     = $reflection->getProperty( 'queued_syncs' );
		$queued->setAccessible( true );
		$deleted = $reflection->getProperty( 'deleted_emails' );
		$deleted->setAccessible( true );
		$queued->setValue( null, [] );
		$deleted->setValue( null, [] );

		// The deletion runs first and records the email as deleted for this request.
		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);
		$this->assertCount( 1, $spy->delete_calls, 'Deletion should call delete_contact once in delete mode.' );

		// A later event in the same request queues an upsert for the same (and another) email.
		$queued->setValue(
			null,
			[
				'reader@example.com' => [
					'contexts'     => [ 'subscription_updated' ],
					'contact'      => [
						'email'    => 'reader@example.com',
						'metadata' => [],
					],
					'as_action_id' => null,
				],
				'other@example.com'  => [
					'contexts'     => [ 'subscription_updated' ],
					'contact'      => [
						'email'    => 'other@example.com',
						'metadata' => [],
					],
					'as_action_id' => null,
				],
			]
		);

		\Newspack\Reader_Activation\Contact_Sync::run_queued_syncs();

		$pushed_emails = array_map(
			function ( $call ) {
				return $call['contact']['email'] ?? null;
			},
			$spy->push_calls
		);
		$this->assertNotContains(
			'reader@example.com',
			$pushed_emails,
			'A deleted email must not be re-pushed by the shutdown queue.'
		);
		$this->assertContains(
			'other@example.com',
			$pushed_emails,
			'Unrelated queued syncs must still flush at shutdown.'
		);

		// Clean up statics.
		$queued->setValue( null, [] );
		$deleted->setValue( null, [] );
	}

	/**
	 * A transient ESP failure in delete mode must schedule a retry so the
	 * contact does not get stranded in undeleted state (GDPR exposure).
	 */
	public function test_handle_account_deletion_schedules_retry_on_delete_failure() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		\as_unschedule_all_actions( \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK );

		$this->reset_integrations();
		$spy                = new \Deletion_Spy_Integration( 'spy-retry-delete', 'Spy Retry Delete' );
		$spy->delete_result = new \WP_Error( 'transient', 'ESP 503' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		Integrations::enable( 'spy-retry-delete' );

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$pending = \as_get_scheduled_actions(
			[
				'hook'   => \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK,
				'group'  => Integrations::get_action_group( 'spy-retry-delete' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertNotEmpty( $pending, 'A retry must be scheduled on delete failure.' );

		$action_id = array_key_first( $pending );
		$args      = \ActionScheduler::store()->fetch_action( $action_id )->get_args();
		$this->assertSame( 'spy-retry-delete', $args[0]['integration_id'] );
		$this->assertSame( 'delete', $args[0]['mode'] );
		$this->assertSame( 'reader@example.com', $args[0]['email'] );
		$this->assertSame( 1, $args[0]['retry_count'] );

		\as_unschedule_all_actions( \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK );
	}

	/**
	 * A transient ESP failure in flag mode must schedule a retry, and the retry
	 * payload must carry the already-prepared contact so we don't try to rebuild
	 * it from a user that no longer exists.
	 */
	public function test_handle_account_deletion_schedules_retry_on_flag_failure() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		\as_unschedule_all_actions( \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK );

		$this->reset_integrations();
		$spy              = new \Deletion_Spy_Integration( 'spy-retry-flag', 'Spy Retry Flag' );
		$spy->push_result = new \WP_Error( 'transient', 'ESP 429' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'flag' );
		Integrations::enable( 'spy-retry-flag' );

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$pending = \as_get_scheduled_actions(
			[
				'hook'   => \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK,
				'group'  => Integrations::get_action_group( 'spy-retry-flag' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertNotEmpty( $pending, 'A retry must be scheduled on flag-push failure.' );

		$action_id = array_key_first( $pending );
		$args      = \ActionScheduler::store()->fetch_action( $action_id )->get_args();
		$this->assertSame( 'flag', $args[0]['mode'] );
		$this->assertSame( 'reader@example.com', $args[0]['email'] );
		$this->assertArrayHasKey( 'contact', $args[0] );
		$prefixed_key = $spy->get_metadata_prefix() . 'Account_Deleted';
		$this->assertArrayHasKey(
			$prefixed_key,
			$args[0]['contact']['metadata'] ?? [],
			'Retry payload must carry the already-prepared flag-mode contact.'
		);

		\as_unschedule_all_actions( \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK );
	}

	/**
	 * Successful retry in delete mode re-invokes delete_contact() and does not
	 * schedule another retry.
	 */
	public function test_execute_deletion_retry_delete_mode_succeeds() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		\as_unschedule_all_actions( \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK );

		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'spy-retry-exec-delete', 'Spy Retry Exec Delete' );
		Integrations::register( $spy );
		Integrations::enable( 'spy-retry-exec-delete' );

		\Newspack\Reader_Activation\Contact_Sync::execute_deletion_retry(
			[
				'integration_id' => 'spy-retry-exec-delete',
				'mode'           => 'delete',
				'email'          => 'reader@example.com',
				'contact'        => [],
				'context'        => 'TestContext',
				'retry_count'    => 1,
			]
		);

		$this->assertCount( 1, $spy->delete_calls );
		$this->assertSame( 'reader@example.com', $spy->delete_calls[0]['email'] );

		$pending = \as_get_scheduled_actions(
			[
				'hook'   => \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK,
				'group'  => Integrations::get_action_group( 'spy-retry-exec-delete' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'No retry should be scheduled on success.' );
	}

	/**
	 * On the final retry, execute_deletion_retry() throws so ActionScheduler
	 * marks the action as failed.
	 */
	public function test_execute_deletion_retry_throws_on_max_retry_failure() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		\as_unschedule_all_actions( \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK );

		$this->reset_integrations();
		$spy                = new \Deletion_Spy_Integration( 'spy-retry-max', 'Spy Retry Max' );
		$spy->delete_result = new \WP_Error( 'persistent', 'Still failing' );
		Integrations::register( $spy );
		Integrations::enable( 'spy-retry-max' );

		$threw = false;
		try {
			\Newspack\Reader_Activation\Contact_Sync::execute_deletion_retry(
				[
					'integration_id' => 'spy-retry-max',
					'mode'           => 'delete',
					'email'          => 'reader@example.com',
					'contact'        => [],
					'context'        => 'TestContext',
					'retry_count'    => \Newspack\Reader_Activation\Contact_Sync::MAX_RETRIES,
				]
			);
		} catch ( \Exception $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw, 'Final retry must throw so ActionScheduler marks the action as failed.' );
	}

	/**
	 * An enabled integration whose external prerequisites are not configured
	 * (is_set_up() === false) must be skipped by the deletion dispatcher, just
	 * as the regular sync path skips it via get_active_configured_integrations().
	 * Otherwise a deletion would perform I/O and schedule Action Scheduler retries
	 * against an integration the admin never finished setting up.
	 */
	public function test_handle_account_deletion_skips_unconfigured_integration() {
		$this->reset_integrations();
		$spy            = new \Deletion_Spy_Integration( 'spy-unconfigured', 'Spy Unconfigured' );
		$spy->is_set_up = false;
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', 'delete' );
		Integrations::enable( 'spy-unconfigured' );

		\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
			'reader@example.com',
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'TestContext'
		);

		$this->assertCount( 0, $spy->delete_calls, 'Unconfigured integration must not have delete_contact called.' );
		$this->assertCount( 0, $spy->push_calls, 'Unconfigured integration must not have push_contact_data called.' );
	}

	/**
	 * The deletion retry executor must abort the retry chain when the integration
	 * is no longer set up, mirroring the guard in execute_integration_retry(). A
	 * retry firing against an unconfigured integration would re-schedule itself
	 * indefinitely instead of dropping cleanly.
	 */
	public function test_execute_deletion_retry_aborts_when_integration_not_set_up() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
		\as_unschedule_all_actions( \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK );

		$this->reset_integrations();
		$spy            = new \Deletion_Spy_Integration( 'spy-retry-unconfigured', 'Spy Retry Unconfigured' );
		$spy->is_set_up = false;
		Integrations::register( $spy );
		Integrations::enable( 'spy-retry-unconfigured' );

		\Newspack\Reader_Activation\Contact_Sync::execute_deletion_retry(
			[
				'integration_id' => 'spy-retry-unconfigured',
				'mode'           => 'delete',
				'email'          => 'reader@example.com',
				'contact'        => [],
				'context'        => 'TestContext',
				'retry_count'    => 1,
			]
		);

		$this->assertCount( 0, $spy->delete_calls, 'Retry against an unconfigured integration must not call delete_contact.' );

		$pending = \as_get_scheduled_actions(
			[
				'hook'   => \Newspack\Reader_Activation\Contact_Sync::RETRY_DELETION_HOOK,
				'group'  => Integrations::get_action_group( 'spy-retry-unconfigured' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertEmpty( $pending, 'Aborted retry chain must not schedule a further retry.' );
	}

	/**
	 * In v1 metadata mode, Integration::prepare_contact() strips metadata keys that
	 * are not registered in Sync\Metadata::get_keys() and enabled_outgoing_fields.
	 * The dispatcher must re-inject account_deleted (with the integration's prefix)
	 * AFTER prepare_contact() so the deletion signal still reaches the ESP.
	 */
	public function test_handle_account_deletion_flag_preserves_account_deleted_in_v1_mode() {
		// Set metadata version to non-legacy via reflection.
		$reflection = new \ReflectionClass( \Newspack\Reader_Activation\Sync\Metadata::class );
		$property   = $reflection->getProperty( 'version' );
		$property->setAccessible( true );
		$original_version = $property->getValue();
		$property->setValue( null, '2' );

		try {
			$this->reset_integrations();
			$spy = new \Deletion_Spy_Integration( 'spy-v1-flag', 'Spy V1 Flag' );
			Integrations::register( $spy );
			$spy->update_settings_field_value( 'sync_account_deletion', true );
			$spy->update_settings_field_value( 'account_deletion_handling', 'flag' );
			Integrations::enable( 'spy-v1-flag' );

			\Newspack\Reader_Activation\Contact_Sync::handle_account_deletion(
				'reader@example.com',
				[
					'email'    => 'reader@example.com',
					'metadata' => [],
				],
				'TestContext'
			);

			$this->assertCount( 1, $spy->push_calls );
			$pushed = $spy->push_calls[0]['contact'];

			// Account_Deleted must survive prepare_contact in v1 mode, prefixed by the integration.
			// Title_Case_With_Underscores matches peer prefixed metadata (e.g. NP_Registration_Date).
			$prefix       = $spy->get_metadata_prefix();
			$prefixed_key = $prefix . 'Account_Deleted';
			$this->assertArrayHasKey(
				$prefixed_key,
				$pushed['metadata'],
				'Prefixed Account_Deleted must be present in the v1-mode flag-push payload.'
			);
			$this->assertNotFalse(
				strtotime( $pushed['metadata'][ $prefixed_key ] ),
				'Account_Deleted must be a strtotime-parseable timestamp in v1 mode.'
			);
			$this->assertMatchesRegularExpression(
				'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
				$pushed['metadata'][ $prefixed_key ],
				'Account_Deleted must use the Y-m-d H:i:s format that peer datetime metadata uses.'
			);
		} finally {
			$property->setValue( null, $original_version );
		}
	}
}
