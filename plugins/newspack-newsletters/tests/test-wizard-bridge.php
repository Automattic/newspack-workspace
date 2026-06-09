<?php
/**
 * Tests for Wizard_Bridge.
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Wizard_Bridge;

/**
 * Tests for Wizard_Bridge.
 */
class Wizard_Bridge_Test extends WP_UnitTestCase {

	/**
	 * Reset $_GET between tests.
	 */
	public function tear_down() {
		unset( $_GET['page'] );
		parent::tear_down();
	}

	/**
	 * Without newspack-plugin loaded, never enqueue — even on the wizard URL.
	 *
	 * The other tests in this class `class_alias( '\stdClass', '\Newspack\Newspack' )`
	 * to fake bundled mode, and the alias persists for the lifetime of the
	 * PHP process. Run this case in its own process so test ordering /
	 * randomisation cannot leak the alias in.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_enqueue_returns_false_when_newspack_plugin_missing() {
		$this->assertFalse( class_exists( '\Newspack\Newspack' ), 'Process-isolation precondition: \Newspack\Newspack must not be aliased yet.' );
		// Use a generic admin screen ID; `edit-newspack_nl_cpt` triggers Admin_Shell's
		// legacy-redirect logic which `wp_safe_redirect`s and breaks PHPUnit's header
		// state. `should_enqueue` does not care about the screen ID, only `is_admin()`.
		set_current_screen( 'plugins' );
		$_GET['page'] = Wizard_Bridge::WIZARD_PAGE_SLUG;
		$this->assertFalse( Wizard_Bridge::should_enqueue() );
	}

	/**
	 * Off the admin screen, never enqueue.
	 */
	public function test_should_enqueue_returns_false_when_not_admin() {
		set_current_screen( 'front' );
		$_GET['page'] = Wizard_Bridge::WIZARD_PAGE_SLUG;
		$this->assertFalse( Wizard_Bridge::should_enqueue() );
	}

	/**
	 * On admin with newspack-plugin present but no `?page=` query — wrong screen.
	 */
	public function test_should_enqueue_returns_false_when_page_query_missing() {
		// Use a generic admin screen ID; `edit-newspack_nl_cpt` triggers Admin_Shell's
		// legacy-redirect logic which `wp_safe_redirect`s and breaks PHPUnit's header
		// state. `should_enqueue` does not care about the screen ID, only `is_admin()`.
		set_current_screen( 'plugins' );
		if ( ! class_exists( '\Newspack\Newspack' ) ) {
			class_alias( '\stdClass', '\Newspack\Newspack' );
		}
		$this->assertFalse( Wizard_Bridge::should_enqueue() );
	}

	/**
	 * Bundled-mode wizard page: enqueue.
	 */
	public function test_should_enqueue_returns_true_on_bundled_wizard_page() {
		// Use a generic admin screen ID; `edit-newspack_nl_cpt` triggers Admin_Shell's
		// legacy-redirect logic which `wp_safe_redirect`s and breaks PHPUnit's header
		// state. `should_enqueue` does not care about the screen ID, only `is_admin()`.
		set_current_screen( 'plugins' );
		if ( ! class_exists( '\Newspack\Newspack' ) ) {
			class_alias( '\stdClass', '\Newspack\Newspack' );
		}
		// The wizard page is capability-gated, so `should_enqueue` requires it too.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_GET['page'] = Wizard_Bridge::WIZARD_PAGE_SLUG;
		$this->assertTrue( Wizard_Bridge::should_enqueue() );
	}
}
