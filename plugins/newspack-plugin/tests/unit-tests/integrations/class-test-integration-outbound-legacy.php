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

	/**
	 * The de-prefixing in prepare_contact_legacy() must use the legacy
	 * pipeline's prefix, not this integration's own. Every other case here runs
	 * with the mock's prefix implicitly equal to the ESP's `NP_`, which would
	 * keep passing if that choice were ever "simplified" to the per-integration
	 * accessor — while dropping every field on a site whose non-ESP integration
	 * carries a custom prefix (the newspack-manager ActiveCampaign case).
	 */
	public function test_custom_integration_prefix_does_not_affect_legacy_matching() {
		$this->integration->update_metadata_prefix( 'AC_' );
		$this->assertSame( 'AC_', $this->integration->get_metadata_prefix() );
		$this->assertSame( 'NP_', Metadata::get_prefix(), 'The legacy pipeline prefix stays NP_.' );

		$this->integration->update_enabled_outgoing_fields( [ 'Membership Status', 'Signup UTM: ' ] );

		$prepared = $this->integration->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[
				'NP_Membership Status'  => 'Monthly Donor',
				'NP_Signup UTM: source' => 'newsletter',
				'status_if_new'         => 'transactional',
			],
			$prepared['metadata'],
			'Legacy data keeps matching by the pipeline prefix when the integration has its own.'
		);
	}

	/**
	 * `newspack_ras_metadata_keys` lets any plugin register labels, so a
	 * registered label ending in `: ` could prefix another label and carry that
	 * other field past the selection. Only the pipeline's own UTM keys get
	 * prefix-match semantics.
	 */
	public function test_registered_label_ending_in_colon_space_does_not_prefix_match() {
		$add_labels = function ( $keys ) {
			$keys['partner_ref']        = 'Partner: ';
			$keys['partner_ref_secret'] = 'Partner: Secret';
			return $keys;
		};
		add_filter( 'newspack_ras_metadata_keys', $add_labels );

		try {
			$this->integration->update_enabled_outgoing_fields( [ 'Partner: ' ] );

			$contact = $this->legacy_contact();
			$contact['metadata']['NP_Partner: ']       = 'acme';
			$contact['metadata']['NP_Partner: Secret'] = 'do-not-sync';

			$prepared = $this->integration->prepare_contact( $contact );

			$this->assertArrayHasKey( 'NP_Partner: ', $prepared['metadata'], 'The enabled label itself still matches exactly.' );
			$this->assertArrayNotHasKey(
				'NP_Partner: Secret',
				$prepared['metadata'],
				'A separate label that the enabled one happens to prefix must not be carried through.'
			);
		} finally {
			remove_filter( 'newspack_ras_metadata_keys', $add_labels );
		}
	}

	/**
	 * The pipeline's UTM labels keep their prefix-match semantics: sub-keys are
	 * how those fields are emitted in the first place.
	 */
	public function test_utm_label_still_matches_its_suffixed_sub_keys() {
		$this->integration->update_enabled_outgoing_fields( [ 'Signup UTM: ' ] );

		$contact                                        = $this->legacy_contact();
		$contact['metadata']['NP_Signup UTM: campaign'] = 'spring';

		$prepared = $this->integration->prepare_contact( $contact );

		$this->assertSame(
			[
				'NP_Signup UTM: source'   => 'newsletter',
				'status_if_new'           => 'transactional',
				'NP_Signup UTM: campaign' => 'spring',
			],
			$prepared['metadata'],
			'Every UTM sub-key of an enabled UTM label syncs.'
		);
	}

	/**
	 * `newspack_ras_metadata_key` reshapes the full prefixed key. Under
	 * inheritance — the no-regression path, where the payload is supposed to be
	 * unchanged — such keys must still match rather than being dropped wholesale.
	 */
	public function test_renamed_keys_survive_under_inheritance() {
		$rename = function ( $key, $prefix, $name ) {
			return 'CUSTOM_' . $name;
		};
		add_filter( 'newspack_ras_metadata_key', $rename, 10, 3 );

		try {
			$esp = Integrations::get_integration( 'esp' );
			$esp->update_enabled_outgoing_fields( [ 'Membership Status', 'Signup UTM: ' ] );

			$contact = [
				'email'    => 'reader@example.com',
				'metadata' => [
					'CUSTOM_Membership Status'  => 'Monthly Donor',
					'CUSTOM_Signup UTM: source' => 'newsletter',
					'status_if_new'             => 'transactional',
				],
			];

			$prepared = $this->integration->prepare_contact( $contact );

			$this->assertSame(
				$contact['metadata'],
				$prepared['metadata'],
				'Keys renamed by newspack_ras_metadata_key still match the inherited selection.'
			);
		} finally {
			remove_filter( 'newspack_ras_metadata_key', $rename, 10 );
		}
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

	/**
	 * Inheritance is legacy-only. In v1 mode an unsaved selection still means
	 * "no fields": inheriting there would push every default field for an
	 * integration whose Outbound panel is empty.
	 */
	public function test_v1_mode_unsaved_selection_returns_empty() {
		$reflection = new \ReflectionClass( Metadata::class );
		$property   = $reflection->getProperty( 'version' );
		$property->setAccessible( true );
		$original_version = $property->getValue();
		$property->setValue( null, '1.0' );

		try {
			$esp = Integrations::get_integration( 'esp' );
			$esp->update_enabled_outgoing_fields( [ 'Membership Status' ] );

			$this->assertSame(
				[],
				$this->integration->get_enabled_outgoing_fields(),
				'An unsaved selection inherits nothing outside legacy mode.'
			);

			$prepared = $this->integration->prepare_contact( $this->legacy_contact() );
			$this->assertSame(
				[],
				$prepared['metadata'],
				'With no enabled fields, v1 prepare_contact() keeps no metadata.'
			);
		} finally {
			$property->setValue( null, $original_version );
		}
	}
}
