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
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Field_Registry;
use Newspack\Reader_Activation\Sync\Metadata;
use Sample_Integration;

// The value-level parity tests build contacts through the metadata classes,
// and the legacy half of every shared field is read off a WC_Customer.
require_once __DIR__ . '/../../mocks/wc-mocks.php';

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
		// Enabled and set up, so the metadata classes its selection needs are
		// actually computed — Metadata::get_sync_metadata_classes() scopes to
		// the integrations the push path delivers to.
		Integrations::enable( 'esp' );
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
		Integrations::disable( 'esp' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
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

	/**
	 * The five ESP names both schemas share (Account, Connected Account,
	 * Registration Date, Registration Page, Total Paid), which are the ids a
	 * legacy site's stored display names migrate onto.
	 *
	 * @var array<string, string[]>
	 */
	private const SHARED_FIELD_IDS = [
		'v1' => [ 'v1:account', 'v1:connected_account', 'v1:registration_date', 'v1:registration_page', 'v1:current_page_url', 'v1:total_paid' ],
		'v2' => [ 'v2:Account', 'v2:Connected_Account', 'v2:Registration_Date', 'v2:Registration_Page', 'v2:Total_Paid' ],
	];

	/**
	 * Store a selection of field ids for the ESP integration, bypassing name
	 * resolution so the test states the stored shape exactly.
	 *
	 * @param string[] $ids Field ids.
	 */
	private function store_selection( array $ids ) {
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', $ids, false );
	}

	/**
	 * Build the outgoing payload the production path produces for a reader.
	 *
	 * @param int $user_id Reader user id.
	 *
	 * @return array Prepared contact.
	 */
	private function build_payload( $user_id ) {
		return $this->esp->prepare_contact( Metadata::get_contact_with_metadata( $user_id ) );
	}

	/**
	 * The load-bearing parity guarantee, at value level rather than key level.
	 *
	 * A migrated legacy site's stored display names resolve onto the new
	 * schema's ids for every shared field, so the new schema's classes start
	 * producing values the legacy pipeline used to produce. Those values must
	 * be identical — including which keys are present at all, since a key the
	 * legacy pipeline omitted arrives at the provider as an empty string and
	 * Mailchimp writes blanks straight over live merge-field data.
	 *
	 * This reader is the divergence case: no SSO connection and no recorded
	 * registration page, which is most of a typical audience.
	 */
	public function test_shared_fields_produce_identical_payload_after_id_migration() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'shared-fields@example.com',
				'first_name' => 'Pat',
				'last_name'  => 'Reader',
			]
		);

		$this->store_selection( self::SHARED_FIELD_IDS['v1'] );
		$legacy = $this->build_payload( $user_id );

		$this->store_selection( self::SHARED_FIELD_IDS['v2'] );
		$migrated = $this->build_payload( $user_id );

		$legacy_metadata   = $legacy['metadata'];
		$migrated_metadata = $migrated['metadata'];
		ksort( $legacy_metadata );
		ksort( $migrated_metadata );

		$this->assertSame( $legacy['email'], $migrated['email'] );
		$this->assertSame(
			$legacy_metadata,
			$migrated_metadata,
			'Migrating a shared field to its new-schema id must not change a single value, or its type.'
		);

		// Non-vacuous: the shared fields this reader does have must be there.
		$this->assertArrayHasKey( 'NP_Account', $migrated_metadata );
		$this->assertArrayHasKey( 'NP_Registration Date', $migrated_metadata );

		// The two keys the legacy pipeline omitted for this reader must stay
		// omitted — this is the blanking the fix exists to prevent.
		$this->assertArrayNotHasKey( 'NP_Connected Account', $migrated_metadata );
		$this->assertArrayNotHasKey( 'NP_Registration Page', $migrated_metadata );

		// Nothing else arrives blank either. Total Paid is the one exception:
		// the legacy pipeline deliberately clears it for a reader with no
		// current-product order, and that erase semantics is preserved.
		$this->assertSame(
			[ 'NP_Total Paid' ],
			array_keys( array_filter( $migrated_metadata, fn( $value ) => '' === $value ) ),
			'A new-schema producer emitted an empty value the legacy pipeline never sent.'
		);
	}

	/**
	 * The mirror case: a reader who does have an SSO connection and a recorded
	 * registration page must still get both values on either set of ids, so
	 * the omit-when-empty rule can never be mistaken for omit-always.
	 */
	public function test_shared_fields_still_carry_values_after_id_migration() {
		$user_id = self::factory()->user->create( [ 'user_email' => 'sso-reader@example.com' ] );
		\update_user_meta( $user_id, Reader_Activation::CONNECTED_ACCOUNT, 'google' );
		\update_user_meta( $user_id, Reader_Activation::REGISTRATION_PAGE, 'https://example.com/newsletter' );

		$this->store_selection( self::SHARED_FIELD_IDS['v1'] );
		$legacy = $this->build_payload( $user_id );

		$this->store_selection( self::SHARED_FIELD_IDS['v2'] );
		$migrated = $this->build_payload( $user_id );

		$legacy_metadata   = $legacy['metadata'];
		$migrated_metadata = $migrated['metadata'];
		ksort( $legacy_metadata );
		ksort( $migrated_metadata );

		$this->assertSame( $legacy_metadata, $migrated_metadata );
		$this->assertSame( 'google', $migrated_metadata['NP_Connected Account'] );
		$this->assertSame( 'https://example.com/newsletter', $migrated_metadata['NP_Registration Page'] );
	}
}
