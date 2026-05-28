<?php
/**
 * Tests for Integration::prepare_contact().
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations;
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
	 * Test that prepare_contact returns contact unchanged in legacy mode.
	 */
	public function test_legacy_mode_returns_unchanged() {
		$this->set_metadata_version( 'legacy' );

		$contact = [
			'email'    => 'test@example.com',
			'metadata' => [
				'NP_Account'           => '123',
				'NP_Registration Date' => '2024-01-01',
			],
		];

		$result = $this->integration->prepare_contact( $contact );

		$this->assertSame( $contact, $result );
	}

	/**
	 * Test that prepare_contact returns contact unchanged when metadata is empty.
	 */
	public function test_empty_metadata_returns_unchanged() {
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );
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
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );

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
		$this->set_metadata_version( '1.0' );

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
