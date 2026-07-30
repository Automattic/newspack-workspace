<?php
/**
 * Tests for Integration::prepare_contact().
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Field_Registry;
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
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		\delete_option( Field_Registry::SCHEMA_ORIGIN_OPTION );
		Field_Registry::reset();
		$this->reset_integrations();
		Integrations::register_integrations();
		$this->set_metadata_version( 'legacy' );
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
	 * Set the metadata version via reflection.
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
	 * A v1-origin site resolves the enabled legacy field id and prefixes its
	 * raw key; everything else is dropped.
	 */
	public function test_v1_ids_resolve_raw_keys() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		$this->integration->update_enabled_outgoing_fields( [ 'v1:account' ] );

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
	 * A raw key and the prefixed form of the same field resolve to one output
	 * key; the later input wins, matching the pre-refactor normalization.
	 */
	public function test_raw_and_prefixed_forms_of_same_field_collapse() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		$this->integration->update_enabled_outgoing_fields( [ 'v1:account' ] );

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
	 * suffix, in both the raw and already-prefixed forms. The bare key is not a
	 * syncable field and must be dropped, as it was before the refactor.
	 */
	public function test_dynamic_suffix_fields_require_a_suffix() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		$this->integration->update_enabled_outgoing_fields( [ 'v1:signup_page_utm' ] );

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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

		// Get the actual keys map to find valid raw keys.
		$keys_map      = Metadata::get_keys();
		$raw_keys      = array_keys( $keys_map );
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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );
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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

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
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

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
	 * Test that email and name are preserved through prepare_contact.
	 */
	public function test_preserves_email_and_name() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

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
	 * Test that an already-prefixed key whose field is enabled but no longer
	 * present in the live keys map (e.g. because a feature flag turned off the
	 * corresponding metadata class after the field was saved) is filtered out.
	 */
	public function test_already_prefixed_stale_enabled_field_filtered() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

		// Write the enabled-fields option directly, bypassing the
		// update_enabled_outgoing_fields() intersect filter, to simulate a stale
		// saved field name that is no longer in the live keys map.
		\update_option( 'newspack_integration_outgoing_fields_prepare-test', [ 'Stale Field' ] );

		$keys_map = Metadata::get_keys();
		$this->assertNotContains( 'Stale Field', $keys_map, 'Sanity: stale field must not be in the live keys map.' );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [ 'NP_Stale Field' => 'leftover_value' ],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertArrayNotHasKey(
			'NP_Stale Field',
			$result['metadata'],
			'Stale prefixed key must be dropped when its field is no longer available.'
		);
	}

	/**
	 * Test mixed raw and already-prefixed keys in the same contact.
	 */
	public function test_mixed_raw_and_prefixed_keys() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );

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
}
