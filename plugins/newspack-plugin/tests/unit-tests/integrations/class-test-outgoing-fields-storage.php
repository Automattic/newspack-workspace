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
		Field_Registry::reset();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );
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

		$this->integration->update_enabled_outgoing_fields(
			[ 'v1:registration_method', 'v2:Registration_Strategy', 'v1:registration_date', 'v2:Registration_Date' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v1:registration_method', 'v2:Registration_Strategy', 'v2:Registration_Date' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * The concrete ESP integration overrides get_enabled_outgoing_fields()
	 * directly (rather than inheriting the base Integration behavior), so it
	 * needs its own coverage: stored ids resolve back to display names.
	 */
	public function test_esp_stored_ids_return_names() {
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
	 * ever stored, and an ESP that is not set up, the read falls back to all
	 * available fields resolved to ids against the merged registry — without
	 * persisting anything.
	 *
	 * Lazy seeding deliberately declines here: with the ESP unconfigured,
	 * detection cannot tell this site from a legacy one whose provider is
	 * momentarily unavailable, and freezing the wrong answer would change the
	 * field names a real legacy site's ESP automations key on. Nothing syncs
	 * from an unconfigured ESP, so the derived set never reaches a provider.
	 */
	public function test_esp_defaults_resolve_to_ids_without_persisting() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );

		$esp = new ESP();
		$this->assertFalse( $esp->is_set_up(), 'This test covers the unconfident-detection path.' );

		$ids = $esp->get_enabled_outgoing_field_ids();

		$this->assertNotEmpty( $ids );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^(v1|v2|neutral):/', $id );
		}
		$this->assertContains( 'v1:account', $ids );
		$this->assertContains( 'v2:Account', $ids );
		$this->assertNull(
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', null ),
			'An unconfident detection must not be frozen into a stored selection.'
		);
	}

	/**
	 * Derived id sets are deliberately NOT canonicalized: the equivalence
	 * upgrade is a write-path behavior (see update_enabled_outgoing_fields()),
	 * and a derived set may not write — persisting it would freeze the
	 * fallback and stop it tracking availability and the ESP's own selection.
	 * So both ids of a value-equivalent pair survive a derived read, rather
	 * than collapsing onto the v2 twin.
	 *
	 * The payload is unaffected: prepare_contact() resolves an id to its ESP
	 * name/raw key regardless of version, so the pair emits once, under the
	 * shared canonical name (see
	 * Test_Prepare_Contact::test_unupgraded_default_id_still_emits_canonical_name).
	 */
	public function test_derived_id_sets_are_not_canonicalized() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );

		// Defaults (the ESP's own fallback): the v1 id is reported as-is
		// alongside its v2 twin, not replaced by it.
		$defaults = ( new ESP() )->get_enabled_outgoing_field_ids();
		$this->assertContains( 'v1:account', $defaults );

		// The legacy-global inheritance fallback, with no ESP registered.
		\update_option( Metadata::FIELDS_OPTION, [ 'Account', 'Registration Method' ] );
		$this->assertNull( Integrations::get_integration( 'esp' ), 'This covers the registry-miss path.' );
		$this->assertEqualsCanonicalizing(
			[ 'v1:account', 'v2:Account', 'v1:registration_method' ],
			$this->integration->get_enabled_outgoing_field_ids(),
			'A shared name resolves to both its ids, and neither is upgraded on this read path.'
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
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );

		$this->assertNull( Integrations::get_integration( 'esp' ), 'This test covers the registry-miss path.' );
		// Not canonicalized: the stored name resolves to both ids of the pair
		// and neither is upgraded on this read path.
		$this->assertEqualsCanonicalizing(
			[ 'v1:account', 'v2:Account' ],
			$this->integration->get_enabled_outgoing_field_ids()
		);
	}

	/**
	 * Registry miss with no legacy global option either: the full default
	 * field set, keeping the pre-coexistence passthrough behavior. Before
	 * per-integration selection existed, only the ESP had a defaults fallback,
	 * so a third-party push integration silently synced no metadata at all.
	 */
	public function test_inheritance_without_esp_or_global_option_defaults_to_all_fields() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Metadata::FIELDS_OPTION );

		$ids = $this->integration->get_enabled_outgoing_field_ids();

		$this->assertNotEmpty( $ids, 'A never-configured integration must not fail closed to an empty selection.' );
		$this->assertContains( 'v1:account', $ids );
		$this->assertContains( 'v1:total_paid', $ids );
		$this->assertNull(
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', null ),
			'The defaults fallback must not persist, so it keeps tracking availability changes.'
		);
	}

	/**
	 * The ESP has nothing above it to inherit from, so an option-less read
	 * resolves through seeding and then the defaults, never through
	 * inheritance. Inheriting from itself — or seeding re-entering its own
	 * lazy trigger — would recurse; this asserts an answer comes back at all.
	 */
	public function test_esp_does_not_inherit_from_itself() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );

		$esp = new ESP();
		Integrations::register( $esp );

		$ids = $esp->get_enabled_outgoing_field_ids();

		// Not canonicalized: see test_derived_id_sets_are_not_canonicalized.
		$this->assertContains( 'v1:account', $ids );
		$this->assertContains( 'v1:total_paid', $ids );
	}

	/**
	 * A stored empty selection is a deliberate "sync nothing" choice and must
	 * NOT be treated as never-configured.
	 */
	public function test_stored_empty_selection_means_no_fields() {
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
	 * Saving a value-equivalent v1 id stores the v2 twin. A v1 id whose v2
	 * counterpart is a separate ESP field (`registration_method` was renamed
	 * to "Registration Strategy") is stored as submitted — it has no twin to
	 * collapse onto. Reads of an already-stored v1 id do not rewrite the
	 * option; the upgrade is a write-path behavior.
	 */
	public function test_update_upgrades_equivalent_ids_to_v2() {

		$this->integration->update_enabled_outgoing_fields( [ 'v1:account', 'v1:registration_method' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v1:registration_method' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}
}
