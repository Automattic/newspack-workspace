<?php
/**
 * Golden parity tests for the ESP metadata schema coexistence refactor.
 *
 * The expected payload arrays in this file are the refactor's invariant:
 * they were captured against the pre-refactor pipeline and MUST NOT be
 * edited. Later tasks may only adapt the setup (how the site's schema
 * state is expressed), never the expected output.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Reader_Activation_Sync;

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;
use Sample_Integration;

/**
 * Golden parity tests.
 *
 * @group schema-parity
 */
class Test_Schema_Parity extends \WP_UnitTestCase {

	/**
	 * ESP-registered sample integration.
	 *
	 * @var Sample_Integration
	 */
	private $esp;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_integrations();
		// Register under the 'esp' id: legacy normalize resolves enabled
		// fields through the ESP integration fallback.
		$this->esp = new Sample_Integration( 'esp', 'ESP' );
		Integrations::register( $this->esp );
		$this->esp->update_metadata_prefix( 'NP_' );
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
	 * Legacy site, hand-built partial payload (the live registration-event
	 * flow): normalize must enrich, expand UTMs, filter to enabled fields
	 * and prefix.
	 */
	public function test_legacy_normalize_golden() {
		$this->set_metadata_version( 'legacy' );
		$this->esp->update_enabled_outgoing_fields(
			[ 'Account', 'Registration Date', 'Registration Method', 'Registration Page', 'Signup UTM: ' ]
		);

		$user_id = self::factory()->user->create( [ 'user_email' => 'reader@example.com' ] );
		\update_user_meta( $user_id, Reader_Activation::REGISTRATION_METHOD, 'registration-wall' );

		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [
				'account'           => $user_id,
				'registration_date' => '2024-01-15 10:00:00',
				'current_page_url'  => 'https://example.com/signup?utm_source=facebook&utm_medium=social',
				'not_a_field'       => 'must-be-dropped',
			],
		];

		$normalized = Metadata::normalize_contact_data( $contact );

		$this->assertSame( 'reader@example.com', $normalized['email'] );
		$this->assertEquals(
			[
				'NP_Account'             => $user_id,
				'NP_Registration Date'   => '2024-01-15 10:00:00',
				'NP_Registration Page'   => 'https://example.com/signup?utm_source=facebook&utm_medium=social',
				'NP_Registration Method' => 'registration-wall',
				'NP_Signup UTM: source'  => 'facebook',
				'NP_Signup UTM: medium'  => 'social',
			],
			$normalized['metadata']
		);
	}

	/**
	 * Legacy site: prepare_contact is a passthrough today. After Task 5 this
	 * test's SETUP changes (prepare becomes the filtering point) but the
	 * final payload below stays identical.
	 */
	public function test_legacy_prepare_contact_passthrough() {
		$this->set_metadata_version( 'legacy' );
		$this->esp->update_enabled_outgoing_fields( [ 'Account', 'Registration Date' ] );

		$normalized = Metadata::normalize_contact_data(
			[
				'email'    => 'reader@example.com',
				'metadata' => [
					'account'           => 123,
					'registration_date' => '2024-01-15 10:00:00',
				],
			]
		);
		$prepared   = $this->esp->prepare_contact( $normalized );

		$this->assertEquals(
			[
				'NP_Account'           => 123,
				'NP_Registration Date' => '2024-01-15 10:00:00',
			],
			$prepared['metadata']
		);
	}

	/**
	 * V2-flag site: raw keys are filtered and prefixed per integration.
	 */
	public function test_v2_prepare_contact_golden() {
		$this->set_metadata_version( '1.0' );
		$this->esp->update_enabled_outgoing_fields(
			[ 'Registration Date', 'Registration UTM Source' ]
		);

		$contact = [
			'email'    => 'reader@example.com',
			'metadata' => [
				'Registration_Date'       => '2024-01-15 10:00:00',
				'Registration_UTM_Source' => 'facebook',
				'Registration_UTM_Medium' => 'social', // Not enabled — dropped.
				'unknown_key'             => 'dropped',
			],
		];

		$prepared = $this->esp->prepare_contact( $contact );

		$this->assertEquals(
			[
				'NP_Registration Date'       => '2024-01-15 10:00:00',
				'NP_Registration UTM Source' => 'facebook',
			],
			$prepared['metadata']
		);
	}
}
