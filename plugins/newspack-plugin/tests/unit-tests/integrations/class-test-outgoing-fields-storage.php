<?php
/**
 * Tests for outgoing-fields storage format, migration and validation.
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
 * Outgoing fields storage tests.
 *
 * @group outgoing-fields-storage
 */
class Test_Outgoing_Fields_Storage extends \WP_UnitTestCase {

	/**
	 * Integration under test.
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
		$this->integration = new Sample_Integration( 'storage-test', 'Storage Test' );
		Integrations::register( $this->integration );
		\delete_option( Field_Registry::SCHEMA_ORIGIN_OPTION );
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );
		\delete_option( Field_Registry::SCHEMA_ORIGIN_OPTION );
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
	 * A v1-origin site with legacy display-name storage migrates to ids on
	 * read, and the option is written back in the new format.
	 */
	public function test_legacy_format_migrates_to_v1_ids_on_read() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'Account', 'Last Payment Amount', 'Signup UTM: ' ]
		);

		$ids = $this->integration->get_enabled_outgoing_field_ids();

		// The value-equivalent Account pair comes back as its v2 twin —
		// migration is a write path and applies the equivalence upgrade;
		// divergent fields stay on their v1 ids.
		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v1:last_payment_amount', 'v1:signup_page_utm' ],
			$ids
		);
		// Written back in the new format.
		$stored = \get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		$this->assertEqualsCanonicalizing( $ids, $stored );
	}

	/**
	 * When a stored display name fails to resolve — e.g. the plugin that
	 * declares its field is inactive — the write-back is skipped so the
	 * unresolved name survives in the option for a later retry. Once every
	 * stored entry resolves, the write-back proceeds as normal.
	 */
	public function test_migration_skips_write_back_when_name_unresolvable() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'Account', 'Field That Does Not Exist' ]
		);

		$ids = $this->integration->get_enabled_outgoing_field_ids();

		$this->assertEqualsCanonicalizing( [ 'v2:Account' ], $ids );
		// Not written back: the option must still hold the original names so
		// migration can retry once the missing field becomes resolvable.
		$this->assertEqualsCanonicalizing(
			[ 'Account', 'Field That Does Not Exist' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);

		// Once every stored entry resolves, the write-back proceeds normally.
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', [ 'Account' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account' ],
			$this->integration->get_enabled_outgoing_field_ids()
		);
		$this->assertEqualsCanonicalizing(
			[ 'v2:Account' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * Legacy maps two raw keys (registration_page, current_page_url) to this
	 * one name. Migration resolves the stored name to both ids and the
	 * equivalence upgrade then collapses both onto the single v2 field they
	 * share — one id, one ESP field, no payload lost. That both raw keys are
	 * resolved and aliased is pinned in Test_Field_Registry.
	 */
	public function test_migration_resolves_multi_raw_key_names_to_all_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'Registration Page' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v2:Registration_Page' ],
			$this->integration->get_enabled_outgoing_field_ids()
		);
	}

	/**
	 * A v2-origin site with legacy display-name storage migrates to v2 ids
	 * on read.
	 *
	 * "Total Paid" is claimed by both schemas, so the v2-origin resolution
	 * picks the v2 definition. A name only the legacy schema declares (the
	 * renamed payment fields, say) would resolve to its v1 id even here, and
	 * must: the stored name is what the publisher's ESP field is called, and
	 * the legacy definition is the one that feeds it.
	 */
	public function test_legacy_format_migrates_to_v2_ids_when_origin_is_v2() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'Total Paid', 'Registration UTM Source' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v2:Total_Paid', 'v2:Registration_UTM_Source' ],
			$this->integration->get_enabled_outgoing_field_ids()
		);
	}

	/**
	 * Reading enabled fields still returns display names, derived from
	 * stored ids, for the old settings UI.
	 */
	public function test_display_names_api_still_returns_names() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'v1:account', 'v1:last_payment_amount' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'Account', 'Last Payment Amount' ],
			$this->integration->get_enabled_outgoing_fields()
		);
	}

	/**
	 * Updating enabled fields accepts a mix of display names and ids, and
	 * stores everything as ids.
	 *
	 * The update tests below use always-available fields (Legacy_Basic,
	 * Identity, Registration classes): WooCommerce-gated fields
	 * (Legacy_Payment, Subscription, Donation) are 'available' => false in
	 * the unit-test env and update_enabled_outgoing_fields() drops
	 * unavailable fields — same behavior as the old get_default_fields()
	 * intersection. Migration (get_enabled_outgoing_field_ids) deliberately
	 * does NOT gate on availability, preserving stored selections for when
	 * WC activates.
	 */
	public function test_update_accepts_names_and_ids_and_stores_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );

		$this->integration->update_enabled_outgoing_fields( [ 'Account', 'v2:Registration_UTM_Source' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v2:Registration_UTM_Source' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * Both versions of a field can be enabled at once: they no longer share an
	 * ESP name, so there is nothing to arbitrate and nothing is dropped. This
	 * is the behavior that replaced the keep-first-version rule.
	 *
	 * `v1:last_payment_amount` and `v2:Last_Payment_Amount` are the changed-
	 * meaning pair — legacy counts any payment including donations, the new
	 * field only the current non-donation subscription — so they are two
	 * separate ESP fields and a publisher may want both during a transition.
	 * Storage does not gate on availability, unlike update(), which is why
	 * this asserts through the migration read.
	 */
	public function test_both_versions_of_a_renamed_field_coexist() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'v1:last_payment_amount', 'v2:Last_Payment_Amount' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v1:last_payment_amount', 'v2:Last_Payment_Amount' ],
			$this->integration->get_enabled_outgoing_field_ids()
		);
		$this->assertEqualsCanonicalizing(
			[ 'Last Payment Amount', 'Last Subscription Payment Amount' ],
			$this->integration->get_enabled_outgoing_fields(),
			'The two versions must resolve to two distinct ESP field names.'
		);
	}

	/**
	 * A save carrying both versions of a field stores both — no version
	 * validation remains. The equivalence upgrade still applies, so a pair
	 * that is one shared field collapses to the surviving v2 id rather than
	 * being stored twice.
	 */
	public function test_update_stores_both_versions_without_validation() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );

		$this->integration->update_enabled_outgoing_fields(
			[ 'v1:registration_method', 'v2:Registration_Strategy', 'v1:registration_date', 'v2:Registration_Date' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v1:registration_method', 'v2:Registration_Strategy', 'v2:Registration_Date' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * A fresh site — no outgoing-fields options, no legacy global fields
	 * option — defaults its schema origin to v2.
	 */
	public function test_fresh_site_origin_defaults_to_v2() {
		$this->assertSame( 'v2', Field_Registry::get_schema_origin() );
	}

	/**
	 * A site with existing outgoing-field selections (any integration) is
	 * detected as a v1-origin site.
	 */
	public function test_existing_legacy_site_origin_is_v1() {
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', [ 'Account' ] );
		$this->assertSame( 'v1', Field_Registry::get_schema_origin() );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
	}

	/**
	 * A site with the pre-integrations global fields option set (and no
	 * per-integration outgoing-fields options, and no ESP registered) is
	 * detected as a v1-origin site. This exercises the global-option branch,
	 * which runs before the ESP-configured check, on its own.
	 */
	public function test_legacy_global_fields_option_origin_is_v1() {
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );
		$this->assertSame( 'v1', Field_Registry::get_schema_origin() );
	}

	/**
	 * A site with a configured ESP integration but no stored outgoing-field
	 * selections and no legacy global fields option is an existing legacy
	 * site that has been syncing dynamic defaults all along — not a fresh
	 * install — so it is detected as v1.
	 */
	public function test_configured_esp_with_no_selections_origin_is_v1() {
		Sample_Integration::$is_set_up_value = true;
		Integrations::register( new Sample_Integration( 'esp', 'ESP' ) );

		$this->assertSame( 'v1', Field_Registry::get_schema_origin() );
	}

	/**
	 * Origin detection's final fallback (see detect_schema_origin()) needs a
	 * registered 'esp' integration to tell a genuinely fresh site from a
	 * legacy site whose ESP integration simply hasn't registered yet (e.g.
	 * get_schema_origin() is called before init priority 5). Without that
	 * signal, get_schema_origin() must not persist its guess — persisting a
	 * wrong 'v2' would freeze it forever. Once the registry is populated, a
	 * later call detects and persists the real answer.
	 */
	public function test_origin_skips_persist_when_esp_not_yet_registered() {
		$this->assertSame( 'v2', Field_Registry::get_schema_origin() );
		$this->assertFalse( \get_option( Field_Registry::SCHEMA_ORIGIN_OPTION ) );

		Sample_Integration::$is_set_up_value = true;
		Integrations::register( new Sample_Integration( 'esp', 'ESP' ) );

		$this->assertSame( 'v1', Field_Registry::get_schema_origin() );
		$this->assertSame( 'v1', \get_option( Field_Registry::SCHEMA_ORIGIN_OPTION ) );
	}

	/**
	 * When detection runs without persisting (no ESP registered yet), the
	 * result is cached for the rest of the request so repeated calls don't
	 * re-run detection — including its options-table LIKE query. Changing a
	 * detection input mid-request is therefore not observed until
	 * Field_Registry::reset() drops the cache.
	 */
	public function test_origin_detection_is_cached_per_request_and_cleared_by_reset() {
		$this->assertSame( 'v2', Field_Registry::get_schema_origin() );

		// A v1 signal appears after the first detection. Without an ESP
		// registered there is no re-detect trigger, so the cached value stands.
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );
		$this->assertSame(
			'v2',
			Field_Registry::get_schema_origin(),
			'A second call must be served from the per-request cache, not re-detected.'
		);

		Field_Registry::reset();
		$this->assertSame(
			'v1',
			Field_Registry::get_schema_origin(),
			'reset() must clear the detection cache so the next call re-detects.'
		);
	}

	/**
	 * The concrete ESP integration overrides get_enabled_outgoing_fields()
	 * directly (rather than inheriting the base Integration behavior), so it
	 * needs its own coverage: stored ids resolve back to display names.
	 */
	public function test_esp_stored_ids_return_names() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp',
			[ 'v1:account', 'v1:registration_date' ]
		);

		$esp = new ESP();

		$this->assertEqualsCanonicalizing(
			[ 'Account', 'Registration Date' ],
			$esp->get_enabled_outgoing_fields()
		);
	}

	/**
	 * ESP's override provides lazy migration from the legacy GLOBAL fields
	 * option (Metadata::FIELDS_OPTION) — a behavior the base Integration
	 * class does not have — seeding the per-integration option on first read.
	 */
	public function test_esp_global_option_migrates_to_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );

		$esp = new ESP();

		$this->assertEqualsCanonicalizing( [ 'Account' ], $esp->get_enabled_outgoing_fields() );
		$this->assertEqualsCanonicalizing(
			[ 'v2:Account' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' )
		);
	}

	/**
	 * With neither the per-integration option nor the legacy global option
	 * ever stored, the ESP falls back to all available fields resolved to
	 * ids against the schema origin, without persisting the fallback.
	 */
	public function test_esp_defaults_resolve_to_ids_without_persisting() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );

		$esp = new ESP();
		$ids = $esp->get_enabled_outgoing_field_ids();

		$this->assertNotEmpty( $ids );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^(v1|v2|neutral):/', $id );
		}
		// Not canonicalized (NPPD-2067): a v1-origin site resolves its own
		// version's id, un-upgraded. See test_esp_does_not_inherit_from_itself
		// and Test_Sync_Metadata_Classes for why.
		$this->assertContains( 'v1:account', $ids );
		$this->assertNotContains( 'v2:Account', $ids );
		$this->assertNull( \get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', null ) );
	}

	/**
	 * Derived id sets are deliberately NOT canonicalized (NPPD-2067): a field
	 * the two schemas share is reported under whichever version name
	 * resolution answers in, even though that is not the id a save would
	 * store. Upgrading these on the way out looked more consistent, but the
	 * version prefix is also what Metadata::get_sync_metadata_classes() reads
	 * to decide which metadata classes to instantiate — canonicalizing a
	 * v1-origin site's derived defaults to v2 ids pulled the v2
	 * Engagement/Subscription compute (wc_get_orders(),
	 * wcs_get_users_subscriptions()) into every sync for the large cohort of
	 * v1-origin sites with no stored selection. See
	 * Test_Sync_Metadata_Classes::test_no_stored_selection_skips_v2_classes_for_shared_fields.
	 *
	 * The payload is unaffected: prepare_contact() resolves an id to its ESP
	 * name/raw key regardless of version, so an un-upgraded v1 id for an
	 * equivalent field still emits under the shared canonical name (see
	 * Test_Prepare_Contact::test_unupgraded_default_id_still_emits_canonical_name).
	 * Read-only either way: nothing here may write the option, or the derived
	 * set would freeze and stop tracking the ESP.
	 */
	public function test_derived_id_sets_are_not_canonicalized() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );

		// Defaults (the ESP's own fallback): 'Account' resolves to v1 on a
		// v1-origin site, and is reported as its own v1 id, un-upgraded.
		$defaults = ( new ESP() )->get_enabled_outgoing_field_ids();
		$this->assertContains( 'v1:account', $defaults );
		$this->assertNotContains( 'v2:Account', $defaults );

		// The legacy-global inheritance fallback resolves names the same way.
		\update_option( Metadata::FIELDS_OPTION, [ 'Account', 'Registration Method' ] );
		$this->assertNull( Integrations::get_integration( 'esp' ), 'This covers the registry-miss path.' );
		$this->assertEqualsCanonicalizing(
			[ 'v1:account', 'v1:registration_method' ],
			$this->integration->get_enabled_outgoing_field_ids(),
			'Neither a shared nor a legacy-only field is upgraded on this read path.'
		);
		$this->assertNull( \get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', null ) );
	}

	/**
	 * A never-configured push integration inherits the ESP integration's
	 * effective selection rather than the full default set (NPPD-2107): a
	 * newly connected integration must push what the site already pushes, not
	 * everything available.
	 */
	public function test_never_configured_integration_inherits_esp_selection() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', [ 'v1:account' ] );
		Integrations::register( new ESP() );

		$ids = $this->integration->get_enabled_outgoing_field_ids();

		$this->assertSame( [ 'v1:account' ], $ids, 'The inherited selection is the ESP\'s, not the defaults.' );
		$this->assertNull(
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', null ),
			'Inheritance must not persist, so it keeps tracking the ESP selection.'
		);
	}

	/**
	 * A corrupt (non-array) stored value is treated as never-configured and
	 * inherits, rather than failing closed to an empty selection that would
	 * silently stop syncing every field.
	 */
	public function test_corrupt_stored_selection_falls_back_to_inheritance() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', 'corrupt' );
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', [ 'v1:account' ] );
		Integrations::register( new ESP() );

		$this->assertSame( [ 'v1:account' ], $this->integration->get_enabled_outgoing_field_ids() );
	}

	/**
	 * With no ESP integration in the registry (pre-init, or a directly
	 * constructed integration — integrations register on init priority 5),
	 * inheritance mirrors the ESP's own fallback chain: the legacy global
	 * option first, then the full default set. Failing closed to an empty
	 * selection there would strip every field on a site whose pre-selection
	 * behavior was full passthrough.
	 */
	public function test_inheritance_without_registered_esp_uses_legacy_global_option() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );

		$this->assertNull( Integrations::get_integration( 'esp' ), 'This test covers the registry-miss path.' );
		// Not canonicalized (NPPD-2067): the v1 id is reported as-is.
		$this->assertSame( [ 'v1:account' ], $this->integration->get_enabled_outgoing_field_ids() );
	}

	/**
	 * Registry miss with no legacy global option either: the full default
	 * field set, keeping the pre-coexistence passthrough behavior. Before
	 * per-integration selection existed, only the ESP had a defaults fallback,
	 * so a third-party push integration silently synced no metadata at all.
	 */
	public function test_inheritance_without_esp_or_global_option_defaults_to_all_fields() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Metadata::FIELDS_OPTION );

		$ids = $this->integration->get_enabled_outgoing_field_ids();

		$this->assertNotEmpty( $ids, 'A never-configured integration must not fail closed to an empty selection.' );
		// Not canonicalized (NPPD-2067): v1-origin names resolve to their own
		// v1 ids here, un-upgraded.
		$this->assertContains( 'v1:account', $ids );
		$this->assertContains( 'v1:total_paid', $ids );
		$this->assertNull(
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', null ),
			'The defaults fallback must not persist, so it keeps tracking availability changes.'
		);
	}

	/**
	 * The ESP has nothing above it to inherit from, so it keeps the dynamic
	 * all-defaults fallback. Inheriting from itself would recurse.
	 */
	public function test_esp_does_not_inherit_from_itself() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );

		$esp = new ESP();
		Integrations::register( $esp );

		$ids = $esp->get_enabled_outgoing_field_ids();

		// Not canonicalized (NPPD-2067): see test_derived_id_sets_are_not_canonicalized.
		$this->assertContains( 'v1:account', $ids );
		$this->assertContains( 'v1:total_paid', $ids );
	}

	/**
	 * A stored empty selection is a deliberate "sync nothing" choice and must
	 * NOT be treated as never-configured.
	 */
	public function test_stored_empty_selection_means_no_fields() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', [] );

		$this->assertSame( [], $this->integration->get_enabled_outgoing_field_ids() );
	}

	/**
	 * Seeding from the legacy global option must not drop selections whose
	 * definitions are currently unavailable (e.g. payment fields while
	 * WooCommerce is inactive). The seeded option shadows the legacy option
	 * permanently, so a resolve-and-filter seed — which drops unavailable
	 * definitions — would lose those selections for good.
	 */
	public function test_esp_seeding_preserves_unavailable_field_selections() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		// 'Last Payment Amount' is declared by Legacy_Payment, which reports
		// available === false without WooCommerce in the test environment.
		\update_option( Metadata::FIELDS_OPTION, [ 'Account', 'Last Payment Amount' ] );

		$esp = new ESP();

		$this->assertContains(
			'Last Payment Amount',
			$esp->get_enabled_outgoing_fields(),
			'A currently-unavailable selection must survive seeding.'
		);
		$this->assertContains(
			'v1:last_payment_amount',
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' ),
			'The seeded selection resolves to its id and stays stored.'
		);
	}

	/**
	 * The fresh-install fallback is a guess — its discriminator (a set-up ESP)
	 * is transiently false whenever Newspack Newsletters is deactivated — so
	 * it must never be persisted. A legacy site touched during that window
	 * would otherwise be branded v2 forever and start writing v2 field names
	 * to the publisher's ESP.
	 */
	public function test_unconfident_fresh_install_guess_is_not_persisted() {
		Sample_Integration::$is_set_up_value = false;
		Integrations::register( new Sample_Integration( 'esp', 'ESP' ) );

		$this->assertSame( 'v2', Field_Registry::get_schema_origin() );
		$this->assertFalse(
			\get_option( Field_Registry::SCHEMA_ORIGIN_OPTION ),
			'An unconfigured ESP makes the v2 answer a guess; it must stay unpersisted.'
		);

		// Once the ESP reports itself set up, the answer is evidence — and it
		// resolves to v1, the value the guess would have frozen incorrectly.
		Sample_Integration::$is_set_up_value = true;
		Field_Registry::reset();

		$this->assertSame( 'v1', Field_Registry::get_schema_origin() );
		$this->assertSame( 'v1', \get_option( Field_Registry::SCHEMA_ORIGIN_OPTION ) );
	}

	/**
	 * A genuinely fresh install records v2 at activation, when "no prior
	 * usage" is unambiguous — lazy detection can't distinguish it later.
	 */
	public function test_activation_seeds_fresh_install_origin() {
		Field_Registry::seed_fresh_install_origin();

		$this->assertSame( 'v2', \get_option( Field_Registry::SCHEMA_ORIGIN_OPTION ) );
	}

	/**
	 * Activation seeding must leave a site with prior usage alone — its
	 * origin is evidence-based and belongs to lazy detection.
	 */
	public function test_activation_seeding_skips_site_with_prior_usage() {
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );

		Field_Registry::seed_fresh_install_origin();

		$this->assertFalse( \get_option( Field_Registry::SCHEMA_ORIGIN_OPTION ) );
	}

	/**
	 * Detection reads the version out of stored selections rather than
	 * treating the mere existence of the option as "legacy": a site that
	 * saved v2 ids before its origin settled is a v2 site.
	 */
	public function test_stored_v2_ids_detect_v2_origin() {
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', [ 'v2:Registration_Date' ] );

		$this->assertSame( 'v2', Field_Registry::get_schema_origin() );
	}

	/**
	 * The outgoing-fields settings entry carries the id-space payload the
	 * per-field UI consumes, alongside the legacy name-space keys external
	 * consumers still read.
	 */
	public function test_settings_config_carries_definitions_and_value_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', [ 'v1:account' ] );

		$outgoing = null;
		foreach ( $this->integration->get_settings_config() as $field ) {
			if ( 'outgoing_metadata_fields' === $field['key'] ) {
				$outgoing = $field;
			}
		}

		$this->assertNotNull( $outgoing );
		$this->assertSame( [ 'v1:account' ], $outgoing['value_ids'] );
		$this->assertSame( 'v1', $outgoing['schema_origin'] );
		$this->assertNotEmpty( $outgoing['definitions'] );
		$this->assertContains( 'v2:Registration_Strategy', array_column( $outgoing['definitions'], 'id' ), 'Definitions must include non-origin versions.' );
		// Legacy keys stay for external consumers.
		$this->assertArrayHasKey( 'options', $outgoing );
		$this->assertArrayHasKey( 'grouped_options', $outgoing );
	}

	/**
	 * The filter_enabled_outgoing_fields() method must operate in id-space: a non-origin
	 * enabled id matches its raw key even though the origin-scoped keys map
	 * does not contain it.
	 */
	public function test_filter_enabled_outgoing_fields_covers_non_origin_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'v1:account', 'v2:Registration_Strategy' ]
		);

		$filtered = $this->integration->filter_enabled_outgoing_fields( [ 'account', 'Registration_Strategy', 'registration_date' ] );

		$this->assertSame( 'Account', $filtered['account'] );
		$this->assertSame( 'Registration Strategy', $filtered['Registration_Strategy'], 'Enabled non-origin ids must match their raw keys.' );
		$this->assertArrayNotHasKey( 'registration_date', $filtered, 'Non-enabled keys must not match.' );
	}

	/**
	 * The filter_enabled_outgoing_fields() method must cover neutral ids (filter-added fields)
	 * end to end: resolving neutral ids to their raw keys and names regardless of schema origin.
	 */
	public function test_filter_enabled_outgoing_fields_covers_neutral_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );

		// Register a custom filter-added field as neutral.
		$callback = function ( $keys ) {
			$keys['custom_extra'] = 'Custom Extra';
			return $keys;
		};
		\add_filter( 'newspack_ras_metadata_keys', $callback );
		Field_Registry::reset();

		// Store v1 and neutral ids in the integration's option.
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'v1:account', 'neutral:custom_extra' ]
		);

		$filtered = $this->integration->filter_enabled_outgoing_fields( [ 'account', 'custom_extra', 'registration_date' ] );

		$this->assertSame( 'Account', $filtered['account'] );
		$this->assertSame( 'Custom Extra', $filtered['custom_extra'], 'Enabled neutral ids must match their raw keys.' );
		$this->assertArrayNotHasKey( 'registration_date', $filtered, 'Non-enabled keys must not match.' );

		// Clean up: remove filter and reset registry.
		\remove_filter( 'newspack_ras_metadata_keys', $callback );
		Field_Registry::reset();
	}

	/**
	 * The get_enabled_outgoing_fields_keys() method must cover enabled non-origin ids,
	 * raw and prefixed.
	 */
	public function test_enabled_outgoing_fields_keys_cover_non_origin_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'v1:account', 'v2:Registration_Strategy' ]
		);

		$raw = $this->integration->get_enabled_outgoing_fields_keys();
		$this->assertContains( 'account', $raw );
		$this->assertContains( 'Registration_Strategy', $raw );

		$prefixed = $this->integration->get_enabled_outgoing_fields_keys( true );
		$this->assertContains( $this->integration->get_metadata_prefix() . 'Registration Strategy', $prefixed );
	}

	/**
	 * Saving a value-equivalent v1 id stores the v2 twin. A v1 id whose v2
	 * counterpart is a separate ESP field (`registration_method` was renamed
	 * to "Registration Strategy") is stored as submitted — it has no twin to
	 * collapse onto. Reads of an already-stored v1 id do not rewrite the
	 * option; the upgrade is a write-path behavior.
	 */
	public function test_update_upgrades_equivalent_ids_to_v2() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );

		$this->integration->update_enabled_outgoing_fields( [ 'v1:account', 'v1:registration_method' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v1:registration_method' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}
}
