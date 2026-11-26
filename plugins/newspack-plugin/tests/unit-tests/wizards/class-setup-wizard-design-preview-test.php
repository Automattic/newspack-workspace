<?php
/**
 * Tests for the setup wizard's site preview flag.
 *
 * @package Newspack\Tests
 */

use Newspack\Complianz;
use Newspack\Setup_Wizard;

/**
 * Setup wizard design preview test case.
 *
 * @group setup-wizard
 * @covers \Newspack\Setup_Wizard
 */
class Setup_Wizard_Design_Preview_Test extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_GET[ Setup_Wizard::DESIGN_PREVIEW_QUERY_ARG ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Request the preview as a user with the given role, or logged out.
	 *
	 * @param string|null $role Role of the current user, null for a visitor.
	 */
	private function request_preview_as( $role ) {
		$_GET[ Setup_Wizard::DESIGN_PREVIEW_QUERY_ARG ] = '1';
		wp_set_current_user( $role ? self::factory()->user->create( [ 'role' => $role ] ) : 0 );
	}

	/**
	 * An administrator gets the preview: no admin bar, prompts or consent banner.
	 */
	public function test_preview_applies_for_an_administrator() {
		$this->request_preview_as( 'administrator' );
		$this->assertTrue( Setup_Wizard::is_design_preview() );
		$this->assertFalse( Setup_Wizard::show_admin_bar( true ) );
		$this->assertTrue( apply_filters( 'newspack_popups_assess_has_disabled_popups', false ) );
		$this->assertFalse( Complianz::hide_banner_in_setup_preview( true ) );
	}

	/**
	 * Someone who cannot run the setup sees the page as usual, even with the query arg.
	 *
	 * @dataProvider provide_roles_without_the_preview
	 * @param string|null $role Role of the current user, null for a visitor.
	 */
	public function test_preview_is_ignored_for_anyone_who_cannot_run_the_setup( $role ) {
		$this->request_preview_as( $role );
		$this->assertFalse( Setup_Wizard::is_design_preview() );
		$this->assertTrue( Setup_Wizard::show_admin_bar( true ) );
		$this->assertFalse( apply_filters( 'newspack_popups_assess_has_disabled_popups', false ) );
		$this->assertTrue( Complianz::hide_banner_in_setup_preview( true ) );
	}

	/**
	 * Roles that must not get the preview.
	 *
	 * @return array
	 */
	public function provide_roles_without_the_preview() {
		return [
			'visitor' => [ null ],
			'editor'  => [ 'editor' ],
		];
	}

	/**
	 * Without the query arg an administrator sees the page as usual.
	 */
	public function test_preview_needs_the_query_arg() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( Setup_Wizard::is_design_preview() );
		$this->assertTrue( Setup_Wizard::show_admin_bar( true ) );
		$this->assertFalse( apply_filters( 'newspack_popups_assess_has_disabled_popups', false ) );
	}
}
