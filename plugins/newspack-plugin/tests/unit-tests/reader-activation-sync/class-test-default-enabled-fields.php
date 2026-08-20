<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing
/**
 * Tests era-scoped default outgoing-field selection derivation.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Sync\Metadata;

require_once __DIR__ . '/../../mocks/newsletters-mocks.php';

/**
 * A site that never saved a selection must keep pushing what its era
 * always pushed: v1 names on legacy sites, v2 names on fresh installs.
 * Derived per read, never persisted.
 */
class Test_Default_Enabled_Fields extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Metadata::FIELDS_OPTION );
		delete_option( 'newspack_integration_outgoing_fields_esp' );
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

	public function test_fresh_install_defaults_to_v2() {
		$this->assert_is_era( Metadata::get_default_enabled_fields(), 'v2' );
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

	public function test_pair_names_present_in_both_eras() {
		$v2 = Metadata::get_default_enabled_fields();
		update_option( Metadata::FIELDS_OPTION, [ 'Account' ] );
		$v1 = Metadata::get_default_enabled_fields();
		foreach ( [ 'Account', 'Connected Account', 'Registration Date', 'Registration Page' ] as $pair_name ) {
			$this->assertContains( $pair_name, $v1 );
			$this->assertContains( $pair_name, $v2 );
		}
	}

	public function test_derivation_is_not_persisted() {
		Metadata::get_default_enabled_fields();
		$this->assertFalse( get_option( 'newspack_integration_outgoing_fields_esp', false ) );
	}

	public function test_full_catalog_unchanged_by_era() {
		// The setup wizard's available-fields list must span both schemas.
		$catalog = Metadata::get_default_fields();
		$this->assertContains( 'Newsletter Selection', $catalog );
		$this->assertContains( 'User Role', $catalog );
	}
}
