<?php
/**
 * Tests for the setup wizard's completion check.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack;
use Newspack\Setup_Wizard;

/**
 * Setup wizard completion test case.
 *
 * @group setup-wizard
 * @covers \Newspack\Newspack::is_setup_complete
 */
class Setup_Wizard_Complete_Test extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_option( NEWSPACK_SETUP_COMPLETE );
		parent::tear_down();
	}

	/**
	 * Setup is incomplete until the option is set.
	 */
	public function test_setup_is_incomplete_by_default() {
		$this->assertFalse( Newspack::is_setup_complete() );
	}

	/**
	 * Completing through the wizard stores a boolean, which a persistent object cache hands back as-is.
	 */
	public function test_setup_is_complete_after_the_wizard_completes_it() {
		( new Setup_Wizard() )->api_complete();
		$this->assertTrue( get_option( NEWSPACK_SETUP_COMPLETE ) );
		$this->assertTrue( Newspack::is_setup_complete() );
	}

	/**
	 * The database hands the option back as a string once the cache is cold.
	 */
	public function test_setup_is_complete_when_the_option_is_the_string_one() {
		update_option( NEWSPACK_SETUP_COMPLETE, '1' );
		$this->assertTrue( Newspack::is_setup_complete() );
	}

	/**
	 * An unset or false option still counts as incomplete.
	 */
	public function test_setup_is_incomplete_when_the_option_is_false() {
		update_option( NEWSPACK_SETUP_COMPLETE, false );
		$this->assertFalse( Newspack::is_setup_complete() );
	}
}
