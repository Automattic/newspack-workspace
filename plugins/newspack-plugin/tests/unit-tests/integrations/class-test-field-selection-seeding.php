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
		\delete_option( 'newspack_setup_complete' );
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		\delete_option( self::ESP_OPTION );
		\delete_option( self::ORIGIN_MARKER );
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
	 * legacy global option, no marker, no constants — and no activation hook
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
	 * Seeding stores a version's ENTIRE definition set, availability included.
	 *
	 * The stored snapshot is permanent while availability is a property of the
	 * moment seeding runs — a fresh install seeds before WooCommerce
	 * Subscriptions is installed or the content gates are switched on. Filtering
	 * unavailable definitions out would bar those fields from ever syncing, with
	 * nothing left to un-bar them. Storing them is inert until their class
	 * lights up (Metadata::get_contact_with_metadata() skips unavailable
	 * classes), so the set is pinned structurally rather than by toggling
	 * availability, which the WooCommerce mocks make unfakeable here.
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
	 * outranks every heuristic below it — here a configured ESP, which would
	 * otherwise say v1. It is deleted the moment it has been used.
	 *
	 * A legacy global fields option is deliberately NOT part of this shape: it
	 * short-circuits ahead of detection entirely, so the ESP can copy it
	 * verbatim (see test_legacy_global_selection_is_copied_not_replaced_by_defaults).
	 */
	public function test_recorded_marker_wins_over_detection_then_is_deleted() {
		\update_option( self::ORIGIN_MARKER, 'v2' );
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
	 * The shape seeding actually has to survive: an in-place plugin update,
	 * where `newspack_activation` never fires. The first read of the ESP's
	 * selection seeds it from the same decision chain the activation hook
	 * would have used, so the site keeps syncing the legacy field set instead
	 * of deriving from the merged all-versions default.
	 *
	 * The second read must come from storage, not from a re-run of detection —
	 * proven by flipping what detection would answer (a `v2` marker) between
	 * the two calls and getting the same legacy ids back.
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

		// Detection would now answer v2. A second read must not consult it.
		\update_option( self::ORIGIN_MARKER, 'v2' );

		$this->assertSame( $first, $esp->get_enabled_outgoing_field_ids() );
		$this->assertSame( $first, \get_option( self::ESP_OPTION ) );
	}

	/**
	 * A never-configured non-ESP integration inherits the ESP's effective
	 * selection (NPPD-2107), and that inheritance read goes through the ESP's
	 * accessor — so it seeds the ESP transitively and inherits the seeded ids.
	 *
	 * Seeding stays the ESP's alone: the inheriting integration must not
	 * materialise a selection of its own, or it would stop tracking the ESP.
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
	 * The lazy path retires the marker just as the activation path does — the
	 * marker's whole purpose is spent the moment a selection is stored, and
	 * leaving it behind would strand dead state on every upgraded site.
	 */
	public function test_lazy_seeding_deletes_the_origin_marker() {
		$esp = $this->register_upgraded_legacy_esp();
		\update_option( self::ORIGIN_MARKER, 'v2' );

		$ids = $esp->get_enabled_outgoing_field_ids();

		// The marker outranks the configured-ESP signal, so it decided this.
		$this->assertContains( 'v2:Account', $ids );
		$this->assertFalse( \get_option( self::ORIGIN_MARKER ) );
	}

	/**
	 * Activation may act on the fresh-install guess, but only for a site that
	 * has not completed setup.
	 *
	 * A completed setup means an existing site — one whose ESP merely happens
	 * to be unconfigured at this moment, which is exactly what the guess cannot
	 * tell apart from a new one. Reactivating the plugin during a Newsletters
	 * outage must not freeze it onto the new schema. This is the prior-usage
	 * guard the retired seed_fresh_install_origin() carried.
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
	 * The pre-integrations global option is the publisher's own selection, and
	 * usually a narrowed one. The seeder must leave it to
	 * ESP::ensure_outgoing_fields_seeded(), which copies it verbatim — writing
	 * the full default set here would shadow it permanently and silently
	 * re-enable every field they had turned off.
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
	 * The lazy path must not act on the fresh-install guess.
	 *
	 * Its discriminator is ESP::is_set_up(), which is transiently false any
	 * time Newspack Newsletters is deactivated or unconfigured — and, as
	 * Test_Contact_Sync_Options' own set_up demonstrates, any time the ESP is
	 * read earlier in a request than its settings are written. Freezing a
	 * legacy site onto the new schema in that window would silently change the
	 * field names its ESP automations key on, permanently.
	 *
	 * Nothing is lost by waiting: an ESP that is not set up cannot sync. Once
	 * it reports itself configured, the next read seeds the legacy set — the
	 * answer the guess would have gotten wrong.
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
