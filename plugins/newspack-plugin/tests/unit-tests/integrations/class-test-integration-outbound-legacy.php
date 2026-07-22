<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests legacy-mode outbound field filtering in Integration::prepare_contact (NPPD-2107).
 *
 * @package Newspack\Tests
 */

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
		Failing_Sample_Integration::reset();
		$this->integration = new Failing_Sample_Integration( 'outbound_mock', 'Outbound Mock' );
	}

	public function tear_down() {
		Failing_Sample_Integration::reset();
		parent::tear_down();
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

		delete_option( 'newspack_integration_outgoing_fields_esp' );

		$this->assertSame(
			[
				'NP_Membership Status' => 'Monthly Donor',
				'status_if_new'        => 'transactional',
			],
			$prepared['metadata'],
			'With no saved selection of its own, the integration filters by the ESP selection.'
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
