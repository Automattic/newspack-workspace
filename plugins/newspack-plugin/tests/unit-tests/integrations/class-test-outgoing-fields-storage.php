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
	 * Legacy's two raw keys for this name (registration_page,
	 * current_page_url) resolve and collapse onto the single v2 field they
	 * share via the equivalence upgrade.
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
	 * A v2-origin site with legacy display-name storage migrates to v2 ids on
	 * read — a name claimed by both schemas resolves to the v2 definition.
	 *
	 * 'Total Paid' is deliberately not used here: it no longer resolves onto
	 * a v2 id at all — see Field_Registry's supersedes-without-equivalent
	 * doctrine — so it would defeat the point this test is making.
	 */
	public function test_legacy_format_migrates_to_v2_ids_when_origin_is_v2() {
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'Connected Account', 'Registration UTM Source' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v2:Connected_Account', 'v2:Registration_UTM_Source' ],
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
	 * The tests below use always-available fields, since
	 * update_enabled_outgoing_fields() drops unavailable ones — unlike the
	 * id-based migration read, which deliberately does not gate on
	 * availability.
	 */
	public function test_update_accepts_names_and_ids_and_stores_ids() {

		$this->integration->update_enabled_outgoing_fields( [ 'Account', 'v2:Registration_UTM_Source' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v2:Registration_UTM_Source' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * Both versions of a changed-meaning field (legacy counts all payments;
	 * v2 only the current subscription) can be enabled at once, since they no
	 * longer share an ESP name — nothing is dropped or arbitrated.
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
	 * validation remains, and explicit ids are stored verbatim, including
	 * both members of a value-equivalent pair.
	 */
	public function test_update_stores_both_versions_without_validation() {

		$this->integration->update_enabled_outgoing_fields(
			[ 'v1:registration_method', 'v2:Registration_Strategy', 'v1:registration_date', 'v2:Registration_Date' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v1:registration_method', 'v2:Registration_Strategy', 'v1:registration_date', 'v2:Registration_Date' ],
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
	 * With no option ever stored and an ESP that is not set up, the read
	 * falls back to all available fields resolved to ids — without
	 * persisting, since an unconfigured ESP can't be confidently told apart
	 * from a momentarily-unavailable legacy one.
	 */
	public function test_esp_defaults_resolve_to_ids_without_persisting() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );

		$esp = new ESP();
		$this->assertFalse( $esp->is_set_up(), 'This test covers the unconfident-detection path.' );

		$ids = $esp->get_enabled_outgoing_field_ids();

		$this->assertNotEmpty( $ids );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^(v2|neutral):/', $id );
		}
		$this->assertContains( 'v2:Account', $ids );
		$this->assertNull(
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', null ),
			'An unconfident detection must not be frozen into a stored selection.'
		);
	}

	/**
	 * A derived selection is scoped to ONE schema version, never the merged
	 * registry, since it can reach real providers: a site with no evidence
	 * derives the new schema, and one carrying legacy evidence derives the
	 * legacy schema.
	 */
	public function test_derived_defaults_are_scoped_to_one_version() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );
		\delete_option( Metadata::FIELDS_OPTION );
		Integrations::register( new ESP() );

		$inherited = $this->integration->get_enabled_outgoing_field_ids();

		$this->assertContains( 'v2:Account', $inherited );
		$this->assertNotContains( 'v1:account', $inherited, 'A derived set must not span both schemas.' );

		// Legacy evidence — another integration's pre-coexistence, bare-name
		// selection — makes the same derivation answer in the legacy schema.
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'evidence', [ 'Account' ] );
		Field_Registry::reset();

		$derived = $this->integration->get_enabled_outgoing_field_ids();

		$this->assertContains( 'v1:account', $derived );
		$this->assertNotContains( 'v2:Account', $derived, 'A derived set must not span both schemas.' );

		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'evidence' );
	}

	/**
	 * Name resolution is single-valued for a shared field on every path,
	 * derived reads included: enabling both members of a pair would put two
	 * producers on one ESP key. The derived read still must not persist.
	 */
	public function test_derived_id_sets_resolve_shared_names_to_one_id() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp' );

		// The legacy-global inheritance fallback, with no ESP registered: this
		// branch resolves the publisher's own stored names.
		\update_option( Metadata::FIELDS_OPTION, [ 'Account', 'Registration Method' ] );
		$this->assertNull( Integrations::get_integration( 'esp' ), 'This covers the registry-miss path.' );
		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v1:registration_method' ],
			$this->integration->get_enabled_outgoing_field_ids(),
			'A shared name resolves to the surviving v2 member alone.'
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
	 * With no ESP registered, inheritance mirrors the ESP's own fallback
	 * chain (legacy global option, then the full default set) rather than
	 * failing closed to an empty selection.
	 */
	public function test_inheritance_without_registered_esp_uses_legacy_global_option() {
		\delete_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' );
		\update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );

		$this->assertNull( Integrations::get_integration( 'esp' ), 'This test covers the registry-miss path.' );
		$this->assertSame(
			[ 'v2:Account' ],
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
		// Scoped to the derived version (see test_derived_defaults_are_scoped_to_one_version).
		$this->assertContains( 'v2:Account', $ids );
		$this->assertContains( 'v2:Total_Paid', $ids );
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

		$this->assertNotEmpty( $ids );
		$this->assertContains( 'v2:Account', $ids );
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
	 * Seeding must not drop selections that are currently unavailable (e.g.
	 * payment fields without WooCommerce): the seeded option shadows the
	 * legacy one permanently, so filtering them out now would lose them for
	 * good.
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
	 * Ids are stored exactly as submitted, on every path: a stored id is the
	 * publisher's field, and both members of a value-equivalent pair produce
	 * the same payload, so there is nothing to gain by rewriting one.
	 *
	 * A bare NAME still resolves canonically, which is the only way a legacy
	 * site's selection moves onto the v2 member.
	 */
	public function test_update_stores_submitted_ids_verbatim() {

		$this->integration->update_enabled_outgoing_fields( [ 'v1:account', 'v1:registration_method' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v1:account', 'v1:registration_method' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);

		$this->integration->update_enabled_outgoing_fields( [ 'Account', 'Registration Method' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v2:Account', 'v1:registration_method' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * A stored legacy id keeps producing through its own class, and its
	 * new-schema twin's raw key still lands on it — the aliasing that lets a
	 * site stay on either member of a pair while the metadata classes emit
	 * whichever spelling they were written in.
	 */
	public function test_stored_legacy_id_accepts_the_v2_raw_key() {
		$this->integration->update_enabled_outgoing_fields( [ 'v1:account' ] );

		$prepared = $this->integration->prepare_contact(
			[
				'email'    => 'reader@example.com',
				'metadata' => [ 'Account' => 42 ],
			]
		);

		$this->assertSame( [ 'NP_Account' => 42 ], $prepared['metadata'] );
	}
}
