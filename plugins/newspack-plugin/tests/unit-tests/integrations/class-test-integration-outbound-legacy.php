<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests legacy-mode outbound field filtering in Integration::prepare_contact (NPPD-2107).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/class-failing-sample-integration.php';

/**
 * Legacy-mode prepare_contact(): non-ESP integrations must apply their own
 * outbound selection; the esp integration keeps the pre-filtered data.
 *
 * @group Integration_Outbound_Filter
 */
class Test_Integration_Outbound_Legacy extends WP_UnitTestCase {

	private $integration;

	public function set_up() {
		parent::set_up();
		// Metadata::$version defaults to 'legacy'; assert it so a future
		// default flip fails loudly here instead of silently changing what
		// these tests exercise.
		$this->assertSame( 'legacy', Metadata::get_version() );
		// Deterministic registry — built-ins only (incl. the esp integration
		// the inheritance tests rely on) — regardless of suite order.
		$this->reset_integrations();
		Integrations::register_integrations();
		Failing_Sample_Integration::reset();
		$this->integration = new Failing_Sample_Integration( 'outbound_mock', 'Outbound Mock' );
	}

	public function tear_down() {
		Failing_Sample_Integration::reset();
		$this->reset_integrations();
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset the integrations registry via reflection, mirroring the sibling
	 * suites so tests are order-independent.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * A legacy-pipeline contact: prefixed metadata plus unprefixed
	 * sync-control keys, as emitted by the legacy metadata classes.
	 *
	 * @return array
	 */
	private function legacy_contact() {
		return [
			'email'    => 'reader@example.com',
			'metadata' => [
				'NP_Membership Status'  => 'Monthly Donor',
				'NP_Total Paid'         => '25.00',
				'NP_Signup UTM: source' => 'newsletter',
				'status_if_new'         => 'transactional',
			],
		];
	}

	/**
	 * The bug this file guards against (NPPD-2107): an integration whose
	 * Outbound selection was explicitly saved as empty must not receive the
	 * full legacy field set.
	 */
	public function test_explicit_empty_selection_strips_all_prefixed_fields() {
		$this->integration->update_enabled_outgoing_fields( [] );

		$prepared = $this->integration->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[ 'status_if_new' => 'transactional' ],
			$prepared['metadata'],
			'With an explicitly empty outbound selection, only unprefixed sync-control keys survive.'
		);
	}

	/**
	 * An integration that never saved an outbound selection inherits the ESP
	 * integration's effective selection in legacy mode, preserving what
	 * pre-existing legacy sites sync (and what the Outbound UI shows).
	 */
	public function test_unsaved_selection_inherits_esp_selection() {
		$esp = Integrations::get_integration( 'esp' );
		$this->assertNotNull( $esp, 'The built-in esp integration must be registered.' );
		$esp->update_enabled_outgoing_fields( [ 'Membership Status' ] );

		$prepared = $this->integration->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[
				'NP_Membership Status' => 'Monthly Donor',
				'status_if_new'        => 'transactional',
			],
			$prepared['metadata'],
			'With no saved selection of its own, the integration filters by the ESP selection.'
		);
	}

	/**
	 * A corrupt (non-array) stored selection is treated as unsaved: the
	 * integration falls through to ESP-selection inheritance instead of
	 * fataling or stripping everything.
	 */
	public function test_corrupt_stored_selection_falls_back_to_inheritance() {
		update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'outbound_mock', 'corrupt' );
		$esp = Integrations::get_integration( 'esp' );
		$esp->update_enabled_outgoing_fields( [ 'Membership Status' ] );

		$prepared = $this->integration->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[
				'NP_Membership Status' => 'Monthly Donor',
				'status_if_new'        => 'transactional',
			],
			$prepared['metadata'],
			'A non-array stored option is ignored and the ESP selection applies.'
		);
	}

	/**
	 * Only the known sync-control keys pass through unprefixed; any other
	 * unprefixed key is dropped so it cannot bypass the outbound selection
	 * filter.
	 */
	public function test_unknown_unprefixed_keys_are_dropped() {
		$this->integration->update_enabled_outgoing_fields( [ 'Membership Status' ] );

		$contact                              = $this->legacy_contact();
		$contact['metadata']['internal_flag'] = 'should-not-sync';

		$prepared = $this->integration->prepare_contact( $contact );

		$this->assertSame(
			[
				'NP_Membership Status' => 'Monthly Donor',
				'status_if_new'        => 'transactional',
			],
			$prepared['metadata'],
			'Unprefixed keys outside SYNC_CONTROL_KEYS are dropped.'
		);
	}

	/**
	 * With no ESP integration in the registry (pre-init or a directly
	 * constructed integration), inheritance mirrors the ESP integration's
	 * own fallback chain — legacy global option first — rather than failing
	 * closed to an empty selection.
	 */
	public function test_esp_registry_miss_falls_back_to_legacy_global_option() {
		$this->reset_integrations();
		update_option( Metadata::FIELDS_OPTION, [ 'Membership Status' ] );

		$prepared = $this->integration->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[
				'NP_Membership Status' => 'Monthly Donor',
				'status_if_new'        => 'transactional',
			],
			$prepared['metadata'],
			'Without a registered ESP integration, the legacy global option applies.'
		);
	}

	/**
	 * With no ESP integration and no legacy global option, the fallback is
	 * the full default field set — the pre-selection passthrough behavior.
	 */
	public function test_esp_registry_miss_without_global_option_keeps_defaults() {
		$this->reset_integrations();
		delete_option( Metadata::FIELDS_OPTION );

		$contact  = $this->legacy_contact();
		$prepared = $this->integration->prepare_contact( $contact );

		$this->assertSame(
			$contact['metadata'],
			$prepared['metadata'],
			'Default-fields fallback keeps the full legacy payload intact.'
		);
	}

	public function test_selection_filters_by_label_including_utm_prefix_shape() {
		$this->integration->update_enabled_outgoing_fields( [ 'Membership Status', 'Signup UTM: ' ] );

		$prepared = $this->integration->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[
				'NP_Membership Status'  => 'Monthly Donor',
				'NP_Signup UTM: source' => 'newsletter',
				'status_if_new'         => 'transactional',
			],
			$prepared['metadata'],
			'Exact labels and the `Label: ` UTM shape match; unselected fields are dropped.'
		);
	}

	public function test_esp_integration_keeps_legacy_data_unchanged() {
		$esp_like = new Failing_Sample_Integration( 'esp', 'ESP-ish' );
		$contact  = $this->legacy_contact();

		$this->assertSame(
			$contact,
			$esp_like->prepare_contact( $contact ),
			'The esp integration takes legacy data as-is: the legacy pipeline already filtered by its config.'
		);
	}

	public function test_contact_without_metadata_is_untouched() {
		$contact = [ 'email' => 'reader@example.com' ];
		$this->assertSame( $contact, $this->integration->prepare_contact( $contact ) );
	}
}
