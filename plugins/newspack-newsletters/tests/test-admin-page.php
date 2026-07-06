<?php
/**
 * Class Test Admin Page
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Pages\Settings_Page;

/**
 * Admin Page base class behaviour.
 */
class Admin_Page_Test extends WP_UnitTestCase {
	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_GET['page'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Returns true when both ?page= and the current screen match the page.
	 */
	public function test_is_admin_page_matches_slug_on_matching_screen() {
		$page         = new Settings_Page();
		$_GET['page'] = $page->get_slug();
		set_current_screen( 'admin_page_' . $page->get_slug() );
		$this->assertTrue( $page->is_admin_page() );
	}

	/**
	 * Returns false when ?page= is a different slug.
	 */
	public function test_is_admin_page_does_not_match_other_slug() {
		$page         = new Settings_Page();
		$_GET['page'] = 'something-else';
		set_current_screen( 'admin_page_' . $page->get_slug() );
		$this->assertFalse( $page->is_admin_page() );
	}

	/**
	 * Returns false when ?page= is absent.
	 */
	public function test_is_admin_page_handles_missing_param() {
		$page = new Settings_Page();
		unset( $_GET['page'] );
		$this->assertFalse( $page->is_admin_page() );
	}

	/**
	 * Returns false when ?page= matches but the current screen does not —
	 * defends against a foreign admin URL carrying the same query key.
	 */
	public function test_is_admin_page_rejects_foreign_screen() {
		$page         = new Settings_Page();
		$_GET['page'] = $page->get_slug();
		set_current_screen( 'tools' );
		$this->assertFalse( $page->is_admin_page() );
	}

	/**
	 * `tools.php?page=<slug>` produces a `tools_page_<slug>` screen id —
	 * the substring contains the slug but isn't a hookname we registered.
	 * Must still be rejected.
	 */
	public function test_is_admin_page_rejects_foreign_url_with_slug_in_screen_id() {
		$page         = new Settings_Page();
		$_GET['page'] = $page->get_slug();
		set_current_screen( 'tools_page_' . $page->get_slug() );
		$this->assertFalse( $page->is_admin_page() );
	}
}
