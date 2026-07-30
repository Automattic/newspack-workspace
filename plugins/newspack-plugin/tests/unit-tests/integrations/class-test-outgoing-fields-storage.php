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

		$this->assertEqualsCanonicalizing(
			[ 'v1:account', 'v1:last_payment_amount', 'v1:signup_page_utm' ],
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

		$this->assertEqualsCanonicalizing( [ 'v1:account' ], $ids );
		// Not written back: the option must still hold the original names so
		// migration can retry once the missing field becomes resolvable.
		$this->assertEqualsCanonicalizing(
			[ 'Account', 'Field That Does Not Exist' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);

		// Once every stored entry resolves, the write-back proceeds normally.
		\update_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test', [ 'Account' ] );

		$this->assertEqualsCanonicalizing(
			[ 'v1:account' ],
			$this->integration->get_enabled_outgoing_field_ids()
		);
		$this->assertEqualsCanonicalizing(
			[ 'v1:account' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * Legacy maps two raw keys (registration_page, current_page_url) to this
	 * one name; migration must resolve the stored name to both ids, since
	 * dropping either would lose payload fields.
	 */
	public function test_migration_resolves_multi_raw_key_names_to_all_ids() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'Registration Page' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v1:registration_page', 'v1:current_page_url' ],
			$this->integration->get_enabled_outgoing_field_ids()
		);
	}

	/**
	 * A v2-origin site with legacy display-name storage migrates to v2 ids
	 * on read.
	 */
	public function test_legacy_format_migrates_to_v2_ids_when_origin_is_v2() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v2' );
		\update_option(
			Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test',
			[ 'Last Payment Amount', 'Registration UTM Source' ]
		);

		$this->assertEqualsCanonicalizing(
			[ 'v2:Last_Payment_Amount', 'v2:Registration_UTM_Source' ],
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
			[ 'v1:account', 'v2:Registration_UTM_Source' ],
			\get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'storage-test' )
		);
	}

	/**
	 * Keep-first-version: when both versions of a conflict-group name are
	 * submitted, the version enabled first wins and the other is dropped;
	 * an unrelated field not in any conflict group always survives.
	 */
	public function test_update_enforces_one_version_per_conflict_group() {
		\update_option( Field_Registry::SCHEMA_ORIGIN_OPTION, 'v1' );

		$this->integration->update_enabled_outgoing_fields(
			[ 'v1:registration_date', 'v2:Registration_Date', 'v2:Registration_UTM_Source' ]
		);

		// Keep-first-version: the v2 member of the claimed conflict group is
		// dropped; the unrelated v2 field survives.
		$this->assertEqualsCanonicalizing(
			[ 'v1:registration_date', 'v2:Registration_UTM_Source' ],
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
			[ 'v1:account' ],
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
		$this->assertContains( 'v1:account', $ids );
		$this->assertNull( \get_option( Integration::OUTGOING_FIELDS_OPTION_PREFIX . 'esp', null ) );
	}
}
