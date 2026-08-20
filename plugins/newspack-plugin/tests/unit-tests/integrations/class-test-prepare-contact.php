<?php
/**
 * Tests for Integration::prepare_contact().
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\ESP;
use Newspack\Reader_Activation\Sync\Metadata;
use Sample_Integration;

/**
 * Tests for Integration::prepare_contact().
 *
 * @group prepare_contact
 */
class Test_Prepare_Contact extends \WP_UnitTestCase {

	/**
	 * Integration instance.
	 *
	 * @var Sample_Integration
	 */
	private $integration;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_integrations();

		$this->integration = new Sample_Integration( 'prepare-test', 'Prepare Test' );
		Integrations::register( $this->integration );
		$this->integration->update_metadata_prefix( 'NP_' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		foreach ( [ 'prepare-test', 'inheritor', 'esp' ] as $id ) {
			\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . $id );
			\delete_option( Integration::METADATA_PREFIX_OPTION_PREFIX . $id );
		}
		\delete_option( Metadata::FIELDS_OPTION );
		$this->reset_integrations();
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset integrations registry via reflection.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * A legacy raw key resolves through the merged catalog and is prefixed
	 * with the field's canonical ESP name; everything else is dropped.
	 */
	public function test_legacy_raw_keys_resolve_through_catalog() {
		$this->integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				'account'   => 5,
				'unrelated' => 'x',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame( [ 'NP_Account' => 5 ], $result['metadata'] );
	}

	/**
	 * A second integration with no Outbound selection of its own must
	 * inherit and push only the ESP's selection, not the full default set
	 * (NPPD-2107).
	 */
	public function test_never_configured_integration_pushes_only_inherited_esp_fields() {
		$esp = new ESP();
		Integrations::register( $esp );
		$esp->update_enabled_outgoing_fields( [ 'Account' ] );

		// A second integration with no stored Outbound selection at all.
		$inheritor = new Sample_Integration( 'inheritor', 'Inheritor' );
		Integrations::register( $inheritor );
		$inheritor->update_metadata_prefix( 'NP_' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'inheritor' );

		$result = $inheritor->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [
					'account'                => 5,
					'total_paid'             => '120.00',
					'membership_status'      => 'Monthly Donor',
					'signup_page_utm_source' => 'newsletter',
				],
			]
		);

		$this->assertSame(
			[ 'NP_Account' => 5 ],
			$result['metadata'],
			'A never-configured integration pushes exactly the ESP selection.'
		);
	}

	/**
	 * Inheritance yields to an explicit save, including an empty one: saving
	 * with nothing checked means "push no metadata fields", not "inherit".
	 */
	public function test_explicit_empty_selection_beats_inheritance() {
		$esp = new ESP();
		Integrations::register( $esp );
		$esp->update_enabled_outgoing_fields( [ 'Account' ] );

		$this->integration->update_enabled_outgoing_fields( [] );

		$result = $this->integration->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [
					'account'       => 5,
					'status_if_new' => 'transactional',
				],
			]
		);

		$this->assertSame(
			[ 'status_if_new' => 'transactional' ],
			$result['metadata'],
			'Only unprefixed sync-control keys survive an explicitly empty selection.'
		);
	}

	/**
	 * A raw key and the prefixed form of the same field resolve to one output
	 * key; the later input wins.
	 */
	public function test_raw_and_prefixed_forms_of_same_field_collapse() {
		$this->integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$result = $this->integration->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [
					'account'    => 'raw',
					'NP_Account' => 'prefixed',
				],
			]
		);

		$this->assertSame( [ 'NP_Account' => 'prefixed' ], $result['metadata'] );
	}

	/**
	 * Dynamic-suffix fields (legacy UTM) only match keys that actually carry a
	 * suffix, in both the raw and already-prefixed forms. A bare family key
	 * carries no value of its own and must be dropped in either form.
	 */
	public function test_dynamic_suffix_fields_require_a_suffix() {
		$this->integration->update_enabled_outgoing_fields( [ 'Signup UTM: ' ] );

		$result = $this->integration->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [
					'signup_page_utm_source' => 'facebook',
					'NP_Signup UTM: medium'  => 'social',
					'signup_page_utm'        => 'no-suffix',
					'signup_page_utm_'       => 'empty-suffix',
					'NP_Signup UTM: '        => 'prefixed-no-suffix',
				],
			]
		);

		$this->assertSame(
			[
				'NP_Signup UTM: source' => 'facebook',
				'NP_Signup UTM: medium' => 'social',
			],
			$result['metadata']
		);
	}

	/**
	 * The ESP list-status keys are transport-level directives, not metadata
	 * fields, so they pass through even with no enabled outgoing fields.
	 */
	public function test_status_keys_pass_through_unfiltered() {
		$this->integration->update_enabled_outgoing_fields( [] );

		$result = $this->integration->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [
					'status'        => 'subscribed',
					'status_if_new' => 'pending',
					'account'       => 1,
				],
			]
		);

		$this->assertSame(
			[
				'status'        => 'subscribed',
				'status_if_new' => 'pending',
			],
			$result['metadata']
		);
	}

	/**
	 * Test that prepare_contact returns contact unchanged when metadata is empty.
	 */
	public function test_empty_metadata_returns_unchanged() {
		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame( $contact, $result );
	}

	/**
	 * Test that prepare_contact returns contact unchanged when metadata key is missing.
	 */
	public function test_missing_metadata_key_returns_unchanged() {
		$contact = [
			'email' => 'test@example.com',
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame( $contact, $result );
	}

	/**
	 * Test that prepare_contact filters to enabled fields and adds prefix.
	 */
	public function test_filters_and_prefixes_raw_keys() {
		// Get the actual keys map to find valid raw keys.
		$keys_map      = Metadata::get_keys();
		$enabled_field = reset( $keys_map );
		$raw_key       = array_search( $enabled_field, $keys_map, true );

		// Enable only the first field.
		$this->integration->update_enabled_outgoing_fields( [ $enabled_field ] );

		// Pick a second field that should be filtered out.
		$disabled_field   = null;
		$disabled_raw_key = null;
		foreach ( $keys_map as $k => $v ) {
			if ( $v !== $enabled_field ) {
				$disabled_field   = $v;
				$disabled_raw_key = $k;
				break;
			}
		}

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				$raw_key          => 'value1',
				$disabled_raw_key => 'value2',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		// Enabled field should be prefixed.
		$this->assertArrayHasKey( 'NP_' . $enabled_field, $result['metadata'] );
		$this->assertSame( 'value1', $result['metadata'][ 'NP_' . $enabled_field ] );

		// Disabled field should be excluded.
		$this->assertArrayNotHasKey( 'NP_' . $disabled_field, $result['metadata'] );
		$this->assertArrayNotHasKey( $disabled_raw_key, $result['metadata'] );
	}

	/**
	 * Test that prepare_contact uses integration-specific prefix.
	 */
	public function test_uses_integration_prefix() {
		$this->integration->update_metadata_prefix( 'CUSTOM_' );

		$keys_map      = Metadata::get_keys();
		$enabled_field = reset( $keys_map );
		$raw_key       = array_search( $enabled_field, $keys_map, true );

		$this->integration->update_enabled_outgoing_fields( [ $enabled_field ] );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [ $raw_key => 'value1' ],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertArrayHasKey( 'CUSTOM_' . $enabled_field, $result['metadata'] );
		$this->assertArrayNotHasKey( 'NP_' . $enabled_field, $result['metadata'] );
	}

	/**
	 * Test that already-prefixed keys are kept as-is and not double-prefixed.
	 */
	public function test_already_prefixed_keys_not_double_prefixed() {
		$keys_map      = Metadata::get_keys();
		$enabled_field = reset( $keys_map );

		$this->integration->update_enabled_outgoing_fields( [ $enabled_field ] );

		$prefixed_key = 'NP_' . $enabled_field;

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [ $prefixed_key => 'already_prefixed_value' ],
		];

		$result = $this->integration->prepare_contact( $contact );

		// Should keep the prefixed key as-is.
		$this->assertArrayHasKey( $prefixed_key, $result['metadata'] );
		$this->assertSame( 'already_prefixed_value', $result['metadata'][ $prefixed_key ] );

		// Should NOT double-prefix.
		$this->assertArrayNotHasKey( 'NP_NP_' . $enabled_field, $result['metadata'] );
	}

	/**
	 * Test that already-prefixed keys for disabled fields are filtered out.
	 */
	public function test_already_prefixed_disabled_fields_filtered() {
		$keys_map = Metadata::get_keys();
		$fields   = array_values( $keys_map );

		// Enable only the first field.
		$this->integration->update_enabled_outgoing_fields( [ $fields[0] ] );

		// Pass a prefixed key for a disabled field.
		$disabled_field = $fields[1] ?? $fields[0]; // fallback if only one field.
		if ( $disabled_field !== $fields[0] ) {
			$contact = [
				'email'    => 'test@example.com',
				'metadata' => [ 'NP_' . $disabled_field => 'should_be_filtered' ],
			];

			$result = $this->integration->prepare_contact( $contact );

			$this->assertArrayNotHasKey( 'NP_' . $disabled_field, $result['metadata'] );
		}
	}

	/**
	 * Test that unknown raw keys not in the keys map are excluded.
	 */
	public function test_unknown_keys_excluded() {
		$keys_map = Metadata::get_keys();
		$this->integration->update_enabled_outgoing_fields( array_values( $keys_map ) );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				'nonexistent_key'    => 'value1',
				'another_random_key' => 'value2',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertEmpty( $result['metadata'] );
	}

	/**
	 * An explicitly-supplied prefixed value must survive raw enrichment that
	 * resolves to the same output key. The URL-derived UTM expansion adds a
	 * raw key alongside the supplied prefixed one; without precedence, the
	 * raw key would overwrite the caller's value.
	 */
	public function test_supplied_prefixed_value_wins_over_raw_expansion() {
		$this->integration->update_enabled_outgoing_fields( [ 'Signup UTM: ' ] );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				'NP_Signup UTM: source'  => 'supplied',
				'signup_page_utm_source' => 'derived',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame( 'supplied', $result['metadata']['NP_Signup UTM: source'] );
	}

	/**
	 * The same precedence applies to a non-dynamic field: an explicitly
	 * supplied prefixed value is not overwritten by a raw key resolving to
	 * the same ESP name.
	 */
	public function test_supplied_prefixed_value_wins_over_raw_key() {
		$this->integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				'NP_Account' => 'supplied',
				'account'    => 'raw',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame( 'supplied', $result['metadata']['NP_Account'] );
	}

	/**
	 * Test that email and name are preserved through prepare_contact.
	 */
	public function test_preserves_email_and_name() {
		$contact = [
			'email'    => 'test@example.com',
			'name'     => 'Test User',
			'metadata' => [],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame( 'test@example.com', $result['email'] );
		$this->assertSame( 'Test User', $result['name'] );
	}

	/**
	 * A prefixed key unknown to the catalog passes through (indistinguishable
	 * from an injected custom field), while a prefixed key naming a
	 * registered-but-disabled field is dropped.
	 */
	public function test_already_prefixed_keys_follow_catalog_contract() {
		// Write the enabled-fields option directly, bypassing the
		// update_enabled_outgoing_fields() intersect filter, to simulate a stale
		// saved field name that is no longer in the live keys map — the
		// integration ends up with nothing enabled.
		\update_option( 'newspack_integration_outgoing_fields_prepare-test', [ 'Stale Field' ] );

		$keys_map = Metadata::get_keys();
		$this->assertNotContains( 'Stale Field', $keys_map, 'Sanity: stale field must not be in the live keys map.' );

		// Pick a registered, non-dynamic field name — not enabled here, since
		// the stored selection resolves to nothing.
		$registered_name = null;
		foreach ( Metadata::get_all_fields() as $name ) {
			if ( ': ' !== substr( $name, -2 ) ) {
				$registered_name = $name;
				break;
			}
		}
		$this->assertNotNull( $registered_name, 'Sanity: a non-dynamic field exists.' );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				'NP_Stale Field'         => 'leftover_value',
				'NP_' . $registered_name => 'disabled_value',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame(
			'leftover_value',
			$result['metadata']['NP_Stale Field'] ?? null,
			'A prefixed key unknown to the catalog passes through.'
		);
		$this->assertArrayNotHasKey(
			'NP_' . $registered_name,
			$result['metadata'],
			'A prefixed key naming a registered-but-disabled field is dropped.'
		);
	}

	/**
	 * Test mixed raw and already-prefixed keys in the same contact.
	 */
	public function test_mixed_raw_and_prefixed_keys() {
		$keys_map = Metadata::get_keys();
		$fields   = array_values( $keys_map );
		$raw_keys = array_keys( $keys_map );

		if ( count( $fields ) < 2 ) {
			$this->markTestSkipped( 'Need at least 2 fields to test mixed keys.' );
		}

		// Enable both fields.
		$this->integration->update_enabled_outgoing_fields( [ $fields[0], $fields[1] ] );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				$raw_keys[0]       => 'raw_value',
				'NP_' . $fields[1] => 'prefixed_value',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		// Raw key should be prefixed.
		$this->assertArrayHasKey( 'NP_' . $fields[0], $result['metadata'] );
		$this->assertSame( 'raw_value', $result['metadata'][ 'NP_' . $fields[0] ] );

		// Already-prefixed key should remain.
		$this->assertArrayHasKey( 'NP_' . $fields[1], $result['metadata'] );
		$this->assertSame( 'prefixed_value', $result['metadata'][ 'NP_' . $fields[1] ] );
	}

	/**
	 * One enabled name aliases both members of a value-equivalent pair: the
	 * keys getter reports both input raw keys and the single ESP key they
	 * share. This is the merged map's aliasing contract — callers still
	 * hand-build contacts with legacy raw keys (the deletion connector passes
	 * `account`) and the pair's values are identical by declaration.
	 */
	public function test_enabled_name_aliases_both_raw_keys_of_a_pair() {
		$this->integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$this->assertEqualsCanonicalizing(
			[ 'account', 'Account' ],
			array_values( $this->integration->get_enabled_outgoing_fields_keys( false ) ),
			'Both raw keys of the pair accept input for the one enabled name.'
		);
		$this->assertSame(
			[ 'NP_Account' ],
			array_values( $this->integration->get_enabled_outgoing_fields_keys( true ) ),
			'The pair emits one ESP key, not two.'
		);
	}

	/**
	 * The legacy raw key of a value-equivalent pair reaches the same ESP field
	 * as its new-schema twin.
	 */
	public function test_legacy_raw_key_input_reaches_canonical_name() {
		$this->integration->update_enabled_outgoing_fields( [ 'Account' ] );

		$result = $this->integration->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [ 'account' => 42 ],
			]
		);

		$this->assertSame( 42, $result['metadata']['NP_Account'] ?? null, 'Legacy raw key must map through the shared name.' );
	}

	/**
	 * Registration Page's equivalence spans two legacy raw keys
	 * (`registration_page` and `current_page_url`): a hand-built contact using
	 * the event-time key must keep syncing under the shared name.
	 */
	public function test_second_legacy_raw_key_of_a_pair_reaches_canonical_name() {
		$this->integration->update_enabled_outgoing_fields( [ 'Registration Page' ] );

		$result = $this->integration->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [ 'current_page_url' => 'https://example.com/signup?utm_source=facebook' ],
			]
		);

		$this->assertSame(
			'https://example.com/signup?utm_source=facebook',
			$result['metadata']['NP_Registration Page'] ?? null,
			'The event-time raw key must map through the shared name.'
		);
	}

	/**
	 * Both versions of a changed-meaning field ("Last Payment Amount" counts
	 * every payment including donations; "Last Subscription Payment Amount"
	 * only the current subscription) can be enabled at once, each reaching
	 * the provider as its own ESP field.
	 */
	public function test_both_versions_of_a_renamed_field_reach_the_provider() {
		$this->integration->update_enabled_outgoing_fields( [ 'Last Payment Amount', 'Last Subscription Payment Amount' ] );

		$result = $this->integration->prepare_contact(
			[
				'email'    => 'test@example.com',
				'metadata' => [
					'last_payment_amount'              => '120.00',
					'Last_Subscription_Payment_Amount' => '15.00',
				],
			]
		);

		$this->assertSame(
			[
				'NP_Last Payment Amount'              => '120.00',
				'NP_Last Subscription Payment Amount' => '15.00',
			],
			$result['metadata'],
			'Each version must land on its own ESP field name.'
		);
	}
}
