<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests outbound field filtering of legacy-schema metadata in
 * Integration::prepare_contact (NPPD-2107).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/class-failing-sample-integration.php';
require_once __DIR__ . '/../../mocks/newsletters-mocks.php';

/**
 * Legacy-schema field names through prepare_contact(): every integration
 * applies its own outbound selection, and one that never saved a selection
 * inherits the ESP's.
 *
 * @group Integration_Outbound_Filter
 */
class Test_Integration_Outbound_Legacy extends WP_UnitTestCase {

	private $integration;

	public function set_up() {
		parent::set_up();
		// Deterministic registry — built-ins only (incl. the esp integration
		// the inheritance tests rely on) — regardless of suite order.
		$this->reset_integrations();
		Integrations::register_integrations();
		Failing_Sample_Integration::reset();
		$this->integration = new Failing_Sample_Integration( 'outbound_mock', 'Outbound Mock' );
	}

	public function tear_down() {
		Failing_Sample_Integration::reset();
		delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'outbound_mock' );
		delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		delete_option( Metadata::FIELDS_OPTION );
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
	 * A contact carrying legacy-schema metadata in already-prefixed form,
	 * plus unprefixed sync-control keys.
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
	 * The `esp` integration is not exempt: an explicitly saved empty selection
	 * means no metadata fields there either.
	 */
	public function test_esp_integration_is_not_exempt_from_filtering() {
		$esp_like = new Failing_Sample_Integration( 'esp', 'ESP-ish' );
		$esp_like->update_enabled_outgoing_fields( [] );

		$prepared = $esp_like->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[ 'status_if_new' => 'transactional' ],
			$prepared['metadata'],
			'Filtering is unconditional, including for the esp integration.'
		);
	}

	/**
	 * An integration that never saved an outbound selection inherits the ESP
	 * integration's effective selection, preserving what pre-existing sites
	 * sync (and what the Outbound UI shows).
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
	 * unprefixed key that no metadata class declares is dropped, so it cannot
	 * bypass the outbound selection filter.
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
			'Unregistered unprefixed keys outside SYNC_CONTROL_KEYS are dropped.'
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
	 * With no ESP integration and no legacy global option, the fallback is the
	 * era-scoped default selection. A site whose ESP is configured came from
	 * the legacy schema, so its legacy payload passes through intact.
	 */
	public function test_esp_registry_miss_on_a_legacy_era_site_keeps_the_payload() {
		$this->reset_integrations();
		delete_option( Metadata::FIELDS_OPTION );
		( new Integrations\ESP() )->update_settings_field_value( 'mailchimp_audience_id', '123' );

		$contact  = $this->legacy_contact();
		$prepared = $this->integration->prepare_contact( $contact );

		$this->assertSame(
			$contact['metadata'],
			$prepared['metadata'],
			'The v1 era default selection keeps the full legacy payload intact.'
		);
	}

	/**
	 * The same fallback on a fresh install resolves to the new schema, whose
	 * selection has no legacy names in it.
	 */
	public function test_esp_registry_miss_on_a_fresh_install_drops_legacy_fields() {
		$this->reset_integrations();
		delete_option( Metadata::FIELDS_OPTION );

		$prepared = $this->integration->prepare_contact( $this->legacy_contact() );

		$this->assertSame(
			[ 'status_if_new' => 'transactional' ],
			$prepared['metadata'],
			'The v2 era default selection enables no legacy field names.'
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
	 * `newspack_ras_metadata_keys` lets any plugin register labels, so a
	 * registered label ending in `: ` could prefix another label and carry that
	 * other field past the selection. Only the pipeline's own UTM keys get
	 * suffix-match semantics.
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
	 * The pipeline's UTM labels keep their suffix-match semantics: sub-keys are
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

	public function test_contact_without_metadata_is_untouched() {
		$contact = [ 'email' => 'reader@example.com' ];
		$this->assertSame( $contact, $this->integration->prepare_contact( $contact ) );
	}
}
