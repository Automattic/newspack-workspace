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
 * as stored ids, once.
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
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_integrations();
		\delete_option( self::ESP_OPTION );
		\delete_option( Metadata::FIELDS_OPTION );
		\delete_option( 'newspack_setup_complete' );
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		\delete_option( self::ESP_OPTION );
		\delete_option( Metadata::FIELDS_OPTION );
		\delete_option( 'newspack_setup_complete' );
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
	 * Build the shape an in-place plugin update leaves behind: a real ESP
	 * integration that is configured and syncing, no stored selection, no
	 * legacy global option, no constants — and no activation hook
	 * ever having fired.
	 *
	 * The real ESP class is required rather than the sample: only it carries
	 * the lazy-seeding override, and only its is_set_up() reads the stored
	 * provider configuration this sets up.
	 *
	 * @return ESP The registered ESP integration.
	 */
	private function register_upgraded_legacy_esp() {
		$esp = new ESP();
		Integrations::register( $esp );
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );
		$this->assertTrue(
			$esp->is_set_up(),
			'This shape needs a configured ESP, or detection would read the site as a fresh install.'
		);
		return $esp;
	}

	/**
	 * Seeding stores a version's ENTIRE definition set, availability included
	 * — filtering unavailable definitions out now would bar those fields from
	 * ever syncing, with nothing left to un-bar them later.
	 */
	public function test_seeding_stores_every_definition_of_the_version() {
		$expected = [];
		foreach ( Field_Registry::get_definitions() as $id => $definition ) {
			if ( Field_Registry::VERSION_V2 === $definition['version'] || Field_Registry::VERSION_NEUTRAL === $definition['version'] ) {
				$expected[] = $id;
			}
		}
		// Non-vacuous only if the version really does declare fields whose
		// classes can be unavailable.
		$this->assertContains( 'v2:Donor_Status', $expected );
		$this->assertContains( 'v2:Subscriber_Status', $expected );

		Field_Registry::seed_default_field_selections();

		$this->assertEqualsCanonicalizing( $expected, \get_option( self::ESP_OPTION ) );
	}

	/**
	 * A legacy site with no stored selection must seed its
	 * legacy defaults, not the merged set — or its ESP would suddenly start
	 * receiving the new schema's field names.
	 */
	public function test_legacy_site_seeds_legacy_defaults() {
		$this->register_configured_esp();

		Field_Registry::seed_default_field_selections();

		$ids = \get_option( self::ESP_OPTION );
		$this->assertIsArray( $ids );
		$this->assertContains( 'v1:account', $ids );
		$this->assertNotContains( 'v2:Account', $ids );
		$this->assertAllIdsOnVersion( 'v1', $ids );
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

		\update_option( self::ESP_OPTION, [] );

		Field_Registry::seed_default_field_selections();

		$this->assertSame( [], \get_option( self::ESP_OPTION ), 'An empty selection is a choice, not an absence.' );
	}

	/**
	 * Saving a bare display name resolves against the merged registry: a
	 * shared name collapses onto the surviving v2 id, and a renamed legacy
	 * field keeps its own.
	 */
	public function test_bare_name_save_resolves_without_origin_state() {
		$esp = new ESP();
		Integrations::register( $esp );

		$esp->update_enabled_outgoing_fields( [ 'Account', 'Registration Method' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v1:registration_method' ],
			\get_option( self::ESP_OPTION )
		);
	}

	/**
	 * An in-place plugin update, where `newspack_activation` never fires,
	 * must still seed on the ESP's first read — and a second read must come
	 * from storage, not a re-run of detection.
	 */
	public function test_in_place_update_seeds_on_first_read() {
		$esp = $this->register_upgraded_legacy_esp();

		$first = $esp->get_enabled_outgoing_field_ids();

		$this->assertContains( 'v1:account', $first );
		$this->assertNotContains( 'v2:Account', $first );
		$this->assertAllIdsOnVersion( 'v1', $first );
		$this->assertSame(
			$first,
			\get_option( self::ESP_OPTION ),
			'The first read must have persisted the selection, not merely derived it.'
		);

		$this->assertSame( $first, $esp->get_enabled_outgoing_field_ids() );
		$this->assertSame( $first, \get_option( self::ESP_OPTION ) );
	}

	/**
	 * A never-configured non-ESP integration inherits the ESP's selection
	 * (NPPD-2107) by reading through it, seeding the ESP transitively — but
	 * must not materialise a selection of its own.
	 */
	public function test_non_esp_inheritance_seeds_the_esp_transitively() {
		$this->register_upgraded_legacy_esp();
		$other = new Sample_Integration( 'inheriting-test', 'Inheriting Test' );
		Integrations::register( $other );

		$inherited = $other->get_enabled_outgoing_field_ids();

		$this->assertContains( 'v1:account', $inherited );
		$this->assertNotContains( 'v2:Account', $inherited );
		$this->assertSame(
			\get_option( self::ESP_OPTION ),
			$inherited,
			'The inherited set must be exactly the ESP\'s seeded selection.'
		);
		$this->assertNull(
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'inheriting-test', null ),
			'Only the ESP seeds; an inheriting integration must keep tracking it.'
		);

		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'inheriting-test' );
	}

	/**
	 * Activation only acts on the fresh-install guess for a site that has
	 * never completed setup — an existing site whose ESP is momentarily
	 * unconfigured must not be frozen onto the new schema.
	 */
	public function test_activation_declines_the_guess_on_a_set_up_site() {
		\update_option( 'newspack_setup_complete', '1' );
		$this->assertFalse( ( new ESP() )->is_set_up(), 'This test needs the unconfident path.' );

		Field_Registry::seed_default_field_selections();

		$this->assertNull(
			\get_option( self::ESP_OPTION, null ),
			'A site that has completed setup is not a fresh install, whatever the ESP looks like right now.'
		);
	}

	/**
	 * The seeder must leave a publisher's narrowed legacy global selection to
	 * ESP::ensure_outgoing_fields_seeded() to copy verbatim, not overwrite it
	 * with the full default set.
	 */
	public function test_legacy_global_selection_is_copied_not_replaced_by_defaults() {
		$narrowed = [ 'Account', 'Registration Date' ];
		\update_option( Metadata::FIELDS_OPTION, $narrowed );
		$esp = $this->register_upgraded_legacy_esp();

		// Activation runs first, then the ESP's own read.
		Field_Registry::seed_default_field_selections();
		$esp->get_enabled_outgoing_field_ids();

		$stored = \get_option( self::ESP_OPTION );
		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v2:Registration_Date' ],
			$stored,
			'The stored selection must be the publisher\'s narrowed set, migrated to ids — not the full default set.'
		);
		$this->assertNotContains( 'v1:referer', $stored, 'A field they had turned off must stay off.' );
	}

	/**
	 * The lazy path must not act while ESP::is_set_up() is transiently false:
	 * an unconfigured ESP can't sync yet, so seeding must wait for a
	 * confident read instead of freezing the wrong schema.
	 */
	public function test_lazy_seeding_defers_an_unconfident_detection() {
		$esp = new ESP();
		Integrations::register( $esp );
		$this->assertFalse( $esp->is_set_up(), 'This test needs the unconfident path.' );

		$derived = $esp->get_enabled_outgoing_field_ids();

		$this->assertNotEmpty( $derived, 'The read still answers, from the merged defaults.' );
		$this->assertNull(
			\get_option( self::ESP_OPTION, null ),
			'An unconfident guess must never be frozen into a stored selection.'
		);

		// The ESP comes back. Now detection has evidence, and it says legacy.
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );

		$seeded = $esp->get_enabled_outgoing_field_ids();

		$this->assertContains( 'v1:account', $seeded );
		$this->assertNotContains( 'v2:Account', $seeded );
		$this->assertSame( $seeded, \get_option( self::ESP_OPTION ) );
	}

	/*
	 * The retired `NEWSPACK_SYNC_METADATA_VERSION` / `..._VERSION_1` branch of
	 * detect_retired_schema_version() is deliberately NOT covered here.
	 *
	 * A PHP constant cannot be undefined once set, and seeding is now reachable
	 * from any ESP read (see ESP::ensure_outgoing_fields_seeded()), so a test
	 * that defined it would force a confident "new schema" detection for every
	 * test that ran afterwards in the same process — which is exactly what it
	 * did before this note replaced it, breaking eight unrelated tests. The
	 * retired origin machinery never covered this branch either, for the same
	 * reason. It is six lines of straight defined() checks, carried over
	 * verbatim, and it decides only the one-shot seeding input.
	 */
}
