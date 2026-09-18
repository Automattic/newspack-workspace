<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing
/**
 * Tests era-scoped default outgoing-field selection derivation.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/../../mocks/newsletters-mocks.php';
require_once __DIR__ . '/../integrations/class-deletion-spy-integration.php';

/**
 * A site that never saved a selection must keep pushing what its era
 * always pushed: v1 names on legacy sites, v2 names on fresh installs.
 * The era is stamped once into SCHEMA_ORIGIN_OPTION — from evidence on
 * first read, or at activation for fresh installs — and the stored stamp
 * wins thereafter, so the payload shape can't follow later configuration
 * changes.
 */
class Test_Default_Enabled_Fields extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Metadata::FIELDS_OPTION );
		delete_option( Metadata::SCHEMA_ORIGIN_OPTION );
		delete_option( 'newspack_integration_outgoing_fields_esp' );
	}

	public function tear_down() {
		$this->reset_integrations();
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset the integrations registry via reflection, mirroring the sibling
	 * suites so tests are order-independent.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	private function assert_is_era( $fields, $era ) {
		$v1_only = 'Newsletter Selection'; // Legacy_Basic-only name.
		$v2_only = 'User Role';            // Identity-only name.
		if ( 'v1' === $era ) {
			$this->assertContains( $v1_only, $fields );
			$this->assertNotContains( $v2_only, $fields );
		} else {
			$this->assertContains( $v2_only, $fields );
			$this->assertNotContains( $v1_only, $fields );
		}
	}

	public function test_fresh_install_defaults_to_v2_and_stamps_the_origin() {
		$this->assert_is_era( Metadata::get_default_enabled_fields(), 'v2' );
		$this->assertSame( 'v2', get_option( Metadata::SCHEMA_ORIGIN_OPTION ), 'The derived era is stamped, so it is inspectable and stable.' );
	}

	public function test_legacy_fields_option_defaults_to_v1() {
		update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );
		$this->assert_is_era( Metadata::get_default_enabled_fields(), 'v1' );
	}

	/**
	 * A site with a working ESP but no stored selection was syncing on
	 * dynamic legacy defaults, not sitting on a fresh install.
	 */
	public function test_configured_esp_defaults_to_v1() {
		$esp = new Integrations\ESP();
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );
		$this->assertTrue( $esp->is_set_up(), 'Fixture must produce a set-up ESP.' );

		$this->assert_is_era( Metadata::get_default_enabled_fields(), 'v1' );
	}

	/**
	 * A live site can run only a non-ESP push integration (e.g. a
	 * manager-supplied CRM or newsletter integration) with the core ESP
	 * unconfigured; its field names must stay legacy on upgrade rather
	 * than flipping to the new schema.
	 */
	public function test_configured_non_esp_push_integration_defaults_to_v1() {
		$this->reset_integrations();
		$spy = new \Deletion_Spy_Integration( 'era-spy', 'Era Spy' );
		Integrations::register( $spy );
		Integrations::enable( 'era-spy' );

		$this->assert_is_era( Metadata::get_default_enabled_fields(), 'v1' );
	}

	/**
	 * Once stamped, the origin wins over any later evidence: configuring an
	 * ESP after the fact must not flip a site's payload shape.
	 */
	public function test_stored_origin_wins_over_later_evidence() {
		update_option( Metadata::SCHEMA_ORIGIN_OPTION, 'v2' );
		$esp = new Integrations\ESP();
		$esp->update_settings_field_value( 'mailchimp_audience_id', '123' );
		$this->assertTrue( $esp->is_set_up(), 'Fixture must produce a set-up ESP.' );

		$this->assert_is_era( Metadata::get_default_enabled_fields(), 'v2' );
	}

	public function test_pair_names_present_in_both_eras() {
		$v2 = Metadata::get_default_enabled_fields();
		delete_option( Metadata::SCHEMA_ORIGIN_OPTION );
		update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );
		$v1 = Metadata::get_default_enabled_fields();
		foreach ( [ 'Account', 'Connected Account', 'Registration Date', 'Registration Page' ] as $pair_name ) {
			$this->assertContains( $pair_name, $v1 );
			$this->assertContains( $pair_name, $v2 );
		}
	}

	public function test_derivation_stamps_the_origin_but_never_the_selection() {
		Metadata::get_default_enabled_fields();
		$this->assertNotFalse( get_option( Metadata::SCHEMA_ORIGIN_OPTION, false ), 'The era stamp is stored.' );
		$this->assertFalse( get_option( 'newspack_integration_outgoing_fields_esp', false ), 'The selection itself stays derived, never persisted.' );
	}

	public function test_full_catalog_unchanged_by_era() {
		// The setup wizard's available-fields list must span both schemas.
		$catalog = Metadata::get_default_fields();
		$this->assertContains( 'Newsletter Selection', $catalog );
		$this->assertContains( 'User Role', $catalog );
	}

	public function test_activation_stamps_v2_on_a_fresh_install() {
		Metadata::stamp_schema_origin_on_activation();
		$this->assertSame( 'v2', get_option( Metadata::SCHEMA_ORIGIN_OPTION ) );
	}

	/**
	 * Activation also fires on reactivation of existing sites, where sibling
	 * plugins may be mid-toggle and the era evidence incomplete — a
	 * completed setup defers the stamp to the first read instead, where the
	 * option-backed evidence is judged whole.
	 */
	public function test_activation_defers_the_stamp_when_setup_is_complete() {
		update_option( NEWSPACK_SETUP_COMPLETE, time() );
		Metadata::stamp_schema_origin_on_activation();
		$this->assertFalse( get_option( Metadata::SCHEMA_ORIGIN_OPTION, false ) );
		delete_option( NEWSPACK_SETUP_COMPLETE );
	}

	public function test_activation_never_overwrites_an_existing_stamp() {
		update_option( Metadata::SCHEMA_ORIGIN_OPTION, 'v1' );
		Metadata::stamp_schema_origin_on_activation();
		$this->assertSame( 'v1', get_option( Metadata::SCHEMA_ORIGIN_OPTION ) );
	}
}
