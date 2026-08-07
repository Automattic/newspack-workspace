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
use Newspack\Reader_Activation\Sync\Field_Registry;
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
		// Register under the 'esp' id: the deprecated Metadata field helpers
		// resolve through the ESP integration fallback.
		$this->esp = new Sample_Integration( 'esp', 'ESP' );
		Integrations::register( $this->esp );
		$this->esp->update_metadata_prefix( 'NP_' );
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		// Defensive cleanup: guarantees no test-registered callback survives
		// even if a test fails before reaching its own remove_filter() call.
		\remove_all_filters( 'newspack_esp_sync_normalize_contact' );
		Field_Registry::reset();
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
	 * Legacy site, hand-built partial payload (the live registration-event
	 * flow): the pipeline must enrich, expand UTMs, filter to enabled fields
	 * and prefix.
	 */
	public function test_legacy_normalize_golden() {
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
		$prepared   = $this->esp->prepare_contact( $normalized );

		$this->assertSame( 'reader@example.com', $prepared['email'] );
		$this->assertEquals(
			[
				'NP_Account'             => $user_id,
				'NP_Registration Date'   => '2024-01-15 10:00:00',
				'NP_Registration Page'   => 'https://example.com/signup?utm_source=facebook&utm_medium=social',
				'NP_Registration Method' => 'registration-wall',
				'NP_Signup UTM: source'  => 'facebook',
				'NP_Signup UTM: medium'  => 'social',
			],
			$prepared['metadata']
		);
	}

	/**
	 * Legacy site, end to end: raw-key normalization followed by the
	 * integration's id-resolving prepare_contact() yields the same payload the
	 * pre-refactor pipeline produced.
	 */
	public function test_legacy_end_to_end_payload() {
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

	/**
	 * Class-built contacts (Metadata::get_contact_with_metadata(), the main
	 * outgoing-sync path) must run through the same
	 * `newspack_esp_sync_normalize_contact` filter that hand-built contacts
	 * get via Metadata::normalize_contact_data(), so publisher code hooking
	 * the filter to mutate outgoing contacts keeps firing on the main path.
	 */
	public function test_normalize_filter_fires_on_class_built_contacts() {
		$this->esp->update_enabled_outgoing_fields( [ 'Newsletter Selection', 'Account' ] );

		$call_count = 0;
		// 'newsletter_selection' is a raw key Legacy_Basic declares as a
		// field, but its value is not populated by the normal metadata
		// build — injecting it here is proof the value came from the
		// filter, not from any other code path.
		$callback = function ( $contact ) use ( &$call_count ) {
			++$call_count;
			$contact['metadata']['newsletter_selection'] = 'Weekly';
			return $contact;
		};
		\add_filter( 'newspack_esp_sync_normalize_contact', $callback );

		$user_id = self::factory()->user->create( [ 'user_email' => 'class-built@example.com' ] );
		$contact = Metadata::get_contact_with_metadata( $user_id );

		\remove_filter( 'newspack_esp_sync_normalize_contact', $callback );

		$this->assertGreaterThanOrEqual(
			1,
			$call_count,
			'The normalize filter must fire while building a class-based contact.'
		);

		$prepared = $this->esp->prepare_contact( $contact );

		$this->assertSame( 'Weekly', $prepared['metadata']['NP_Newsletter Selection'] );
	}
}
