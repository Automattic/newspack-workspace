<?php
/**
 * Tests for admin list-table auto-layout.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use Newspack\Admin_List_Table_Layout;

/**
 * @group admin-list-table-layout
 */
class Test_Admin_List_Table_Layout extends \WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once NEWSPACK_ABSPATH . 'includes/class-admin-list-table-layout.php';
	}

	/** Pin the screen set so tests are independent of register_screen() bleed. */
	private function pin_screens( array $screens ): void {
		add_filter( 'newspack_admin_autolayout_screens', fn() => $screens );
	}

	public function test_defaults_include_post_and_page(): void {
		$screens = Admin_List_Table_Layout::get_screens();
		$this->assertContains( 'post', $screens );
		$this->assertContains( 'page', $screens );
	}

	public function test_register_screen_adds_key(): void {
		Admin_List_Table_Layout::register_screen( 'my_cpt' );
		$this->assertContains( 'my_cpt', Admin_List_Table_Layout::get_screens() );
	}

	public function test_matches_post_list_screen(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertTrue( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-post' ) ) );
	}

	public function test_leak_guard_category_term_screen_does_not_match(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		// edit-category carries post_type=post but base=edit-tags — must NOT match.
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-category' ) ) );
	}

	public function test_leak_guard_tag_term_screen_does_not_match(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-post_tag' ) ) );
	}

	public function test_unregistered_post_type_does_not_match(): void {
		register_post_type( 'np_test_cpt', [ 'public' => true ] );
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-np_test_cpt' ) ) );
		unregister_post_type( 'np_test_cpt' );
	}

	public function test_filter_can_remove_a_default(): void {
		$this->pin_screens( [ 'page' ] ); // 'post' removed
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-post' ) ) );
	}
}
