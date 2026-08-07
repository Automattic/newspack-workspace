<?php
/**
 * Tests for the one-time outgoing-field selection seeding.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\ESP;
use Newspack\Reader_Activation\Sync\Field_Registry;
use Newspack\Reader_Activation\Sync\Metadata;
use Sample_Integration;

/**
 * Field-selection seeding tests.
 *
 * Seeding is what lets every runtime path stop asking which schema a site
 * came from: it materialises the site's current effective default selection
 * as stored ids, once, and retires the schema-origin marker on its way out.
 *
 * @group field-selection-seeding
 */
class Test_Field_Selection_Seeding extends \WP_UnitTestCase {

	/**
	 * The ESP integration's stored outgoing-fields option.
	 *
	 * @var string
	 */
	private const ESP_OPTION = Integration::OUTGOING_FIELDS_OPTION_PREFIX . Integration::ESP_INTEGRATION_ID;

	/**
	 * The retired schema-origin marker, spelled out because production code
	 * no longer exposes a constant for it.
	 *
	 * @var string
	 */
	private const ORIGIN_MARKER = 'newspack_sync_schema_origin';

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_integrations();
		\delete_option( self::ESP_OPTION );
		\delete_option( self::ORIGIN_MARKER );
		\delete_option( Metadata::FIELDS_OPTION );
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		\delete_option( self::ESP_OPTION );
		\delete_option( self::ORIGIN_MARKER );
		\delete_option( Metadata::FIELDS_OPTION );
		Sample_Integration::$is_set_up_value = true;
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
	 * Register a set-up ESP integration, the signal that says "this site was
	 * already syncing before coexistence".
	 */
	private function register_configured_esp() {
		Sample_Integration::$is_set_up_value = true;
		Integrations::register( new Sample_Integration( Integration::ESP_INTEGRATION_ID, 'ESP' ) );
	}

	/**
	 * Assert every seeded id belongs to the expected schema version (or is
	 * version-neutral, which every version's default set includes).
	 *
	 * @param string   $version Expected schema version prefix.
	 * @param string[] $ids     Seeded ids.
	 */
	private function assertAllIdsOnVersion( $version, $ids ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression(
				'/^(' . $version . '|neutral):/',
				$id,
				"Seeded id {$id} does not belong to the {$version} default set."
			);
		}
	}

	/**
	 * The cohort seeding exists for: a legacy site that never opened the field
	 * picker, so it has no stored selection and no marker, and was syncing the
	 * legacy defaults all along. Its selection must be materialised as legacy
	 * ids — seeding the merged set would start pushing the new schema's field
	 * names to the publisher's ESP.
	 */
	public function test_legacy_site_seeds_legacy_defaults() {
		$this->register_configured_esp();

		Field_Registry::seed_default_field_selections();

		$ids = \get_option( self::ESP_OPTION );
		$this->assertIsArray( $ids );
		$this->assertContains( 'v1:account', $ids );
		$this->assertNotContains( 'v2:Account', $ids );
		$this->assertAllIdsOnVersion( 'v1', $ids );
		$this->assertFalse(
			\get_option( self::ORIGIN_MARKER ),
			'The marker must be deleted once seeding has consumed it.'
		);
	}

	/**
	 * A site with no evidence of prior use at all is a fresh install, and
	 * starts on the new schema.
	 */
	public function test_fresh_site_seeds_new_schema_defaults() {
		$this->assertFalse(
			( new ESP() )->is_set_up(),
			'This test needs an unconfigured ESP, or it would look like a legacy site.'
		);

		Field_Registry::seed_default_field_selections();

		$ids = \get_option( self::ESP_OPTION );
		$this->assertIsArray( $ids );
		$this->assertContains( 'v2:Account', $ids );
		$this->assertNotContains( 'v1:account', $ids );
		$this->assertAllIdsOnVersion( 'v2', $ids );
		$this->assertFalse( \get_option( self::ORIGIN_MARKER ) );
	}

	/**
	 * A marker recorded by an earlier release is the site's own answer and
	 * outranks every heuristic below it — here, a configured ESP and a legacy
	 * global fields option, both of which would otherwise say v1. It is
	 * deleted the moment it has been used.
	 */
	public function test_recorded_marker_wins_over_detection_then_is_deleted() {
		\update_option( self::ORIGIN_MARKER, 'v2' );
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );
		$this->register_configured_esp();

		Field_Registry::seed_default_field_selections();

		$ids = \get_option( self::ESP_OPTION );
		$this->assertContains( 'v2:Account', $ids );
		$this->assertNotContains( 'v1:account', $ids );
		$this->assertFalse( \get_option( self::ORIGIN_MARKER ) );
	}

	/**
	 * Seeding is a one-shot for never-configured sites: a stored selection is
	 * the publisher's own decision and is never overwritten — including a
	 * deliberately empty one, which means "push no metadata" and would be
	 * silently undone by a re-seed.
	 */
	public function test_existing_selection_is_never_overwritten() {
		$this->register_configured_esp();
		\update_option( self::ESP_OPTION, [ 'v2:Registration_Date' ] );

		Field_Registry::seed_default_field_selections();

		$this->assertSame( [ 'v2:Registration_Date' ], \get_option( self::ESP_OPTION ) );
		$this->assertFalse(
			\get_option( self::ORIGIN_MARKER ),
			'The marker is dead either way once a selection exists.'
		);

		\update_option( self::ESP_OPTION, [] );

		Field_Registry::seed_default_field_selections();

		$this->assertSame( [], \get_option( self::ESP_OPTION ), 'An empty selection is a choice, not an absence.' );
	}

	/**
	 * Saving a bare display name resolves against the merged registry with no
	 * schema-origin state anywhere: a shared name collapses onto the surviving
	 * v2 id, a renamed legacy field keeps its own, and nothing writes a marker.
	 */
	public function test_bare_name_save_resolves_without_origin_state() {
		$esp = new ESP();
		Integrations::register( $esp );

		$esp->update_enabled_outgoing_fields( [ 'Account', 'Registration Method' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v1:registration_method' ],
			\get_option( self::ESP_OPTION )
		);
		$this->assertFalse( \get_option( self::ORIGIN_MARKER ), 'No code path records a marker any more.' );
	}

	/**
	 * The retired global version switch still decides for a site that set it,
	 * ranking below only the recorded marker.
	 *
	 * Declared last on purpose: the constant cannot be undefined once set, and
	 * this class is the only caller of the seeder, so leaving it defined
	 * cannot reach another test.
	 */
	public function test_version_constant_forces_the_new_schema() {
		if ( defined( 'NEWSPACK_SYNC_METADATA_VERSION' ) ) {
			$this->markTestSkipped( 'NEWSPACK_SYNC_METADATA_VERSION is already defined in this process.' );
		}
		// A configured ESP would otherwise make this look like a legacy site.
		$this->register_configured_esp();
		define( 'NEWSPACK_SYNC_METADATA_VERSION', '1.0' );

		Field_Registry::seed_default_field_selections();

		$ids = \get_option( self::ESP_OPTION );
		$this->assertContains( 'v2:Account', $ids );
		$this->assertNotContains( 'v1:account', $ids );
		$this->assertAllIdsOnVersion( 'v2', $ids );
	}
}
