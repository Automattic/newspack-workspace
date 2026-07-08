<?php
/**
 * Tests for admin list-table auto-layout.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

use Newspack\Admin_List_Table_Layout;

/**
 * Tests for the Admin_List_Table_Layout helper.
 *
 * @group admin-list-table-layout
 */
class Test_Admin_List_Table_Layout extends \WP_UnitTestCase {

	/**
	 * Load the class under test once for the whole test case.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once NEWSPACK_ABSPATH . 'includes/class-admin-list-table-layout.php';
	}

	/**
	 * Reset the static registry so register_screen() calls can't bleed across tests.
	 */
	public function tear_down(): void {
		$registered = new \ReflectionProperty( Admin_List_Table_Layout::class, 'registered' );
		$registered->setAccessible( true );
		$registered->setValue( null, [] );
		parent::tear_down();
	}

	/**
	 * Pin the screen set so tests are independent of register_screen() bleed.
	 *
	 * @param string[] $screens Screen keys to force as the treated set.
	 */
	private function pin_screens( array $screens ): void {
		add_filter( 'newspack_admin_autolayout_screens', fn() => $screens );
	}

	/**
	 * The default treated set includes the post and page list screens.
	 */
	public function test_defaults_include_post_and_page(): void {
		$screens = Admin_List_Table_Layout::get_screens();
		$this->assertContains( 'post', $screens );
		$this->assertContains( 'page', $screens );
	}

	/**
	 * Registering a screen adds its key to the treated set.
	 */
	public function test_register_screen_adds_key(): void {
		Admin_List_Table_Layout::register_screen( 'my_cpt' );
		$this->assertContains( 'my_cpt', Admin_List_Table_Layout::get_screens() );
	}

	/**
	 * The Posts list screen (edit.php, base edit) matches the 'post' key.
	 */
	public function test_matches_post_list_screen(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertTrue( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-post' ) ) );
	}

	/**
	 * The Categories term screen must not match despite carrying post_type=post.
	 */
	public function test_leak_guard_category_term_screen_does_not_match(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		// edit-category carries post_type=post but base=edit-tags — must NOT match.
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-category' ) ) );
	}

	/**
	 * The Tags term screen must not match despite carrying post_type=post.
	 */
	public function test_leak_guard_tag_term_screen_does_not_match(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-post_tag' ) ) );
	}

	/**
	 * A post type that is not in the treated set does not match.
	 */
	public function test_unregistered_post_type_does_not_match(): void {
		register_post_type( 'np_test_cpt', [ 'public' => true ] );
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-np_test_cpt' ) ) );
		unregister_post_type( 'np_test_cpt' );
	}

	/**
	 * The filter can remove a default screen from the treated set.
	 */
	public function test_filter_can_remove_a_default(): void {
		$this->pin_screens( [ 'page' ] ); // 'post' removed.
		$this->assertFalse( Admin_List_Table_Layout::screen_matches( \WP_Screen::get( 'edit-post' ) ) );
	}

	/**
	 * Untreated screens emit no CSS.
	 */
	public function test_no_emission_for_untreated_screen(): void {
		register_post_type( 'np_test_cpt', [ 'public' => true ] );
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertSame( '', Admin_List_Table_Layout::get_styles_for_screen( \WP_Screen::get( 'edit-np_test_cpt' ) ) );
		unregister_post_type( 'np_test_cpt' );
	}

	/**
	 * Term screens emit no CSS (leak guard at the emission layer).
	 */
	public function test_leak_guard_emits_nothing_on_term_screen(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		$this->assertSame( '', Admin_List_Table_Layout::get_styles_for_screen( \WP_Screen::get( 'edit-category' ) ) );
	}

	/**
	 * A treated screen emits the desktop-scoped auto-layout CSS with the floor.
	 */
	public function test_emits_auto_layout_for_post_list(): void {
		$this->pin_screens( [ 'post', 'page' ] );
		$css = Admin_List_Table_Layout::get_styles_for_screen( \WP_Screen::get( 'edit-post' ) );
		$this->assertStringContainsString( '@media screen and (min-width: 783px)', $css );
		$this->assertStringContainsString( '.wp-list-table.fixed { table-layout: auto; }', $css );
		$this->assertStringContainsString( 'column-primary', $css );
		$this->assertStringContainsString( 'min-width: 35ch;', $css );
	}

	/**
	 * The min-width floor defaults to 35ch.
	 */
	public function test_min_width_default(): void {
		$this->assertSame( '35ch', Admin_List_Table_Layout::get_min_width() );
	}

	/**
	 * The min-width filter accepts px, ch, and rem length values.
	 */
	public function test_min_width_accepts_px_and_ch_and_rem(): void {
		foreach ( [ '280px', '40ch', '20rem' ] as $value ) {
			add_filter( 'newspack_admin_primary_column_min_width', fn() => $value );
			$this->assertSame( $value, Admin_List_Table_Layout::get_min_width() );
			remove_all_filters( 'newspack_admin_primary_column_min_width' );
		}
	}

	/**
	 * The min-width filter rejects junk, percentages, and trailing newlines.
	 */
	public function test_min_width_rejects_junk_and_percentage(): void {
		foreach ( [ '30%', '100', 'red;}', 'expression(alert(1))', '35 ch', "35ch\n" ] as $junk ) {
			add_filter( 'newspack_admin_primary_column_min_width', fn() => $junk );
			$this->assertSame( '35ch', Admin_List_Table_Layout::get_min_width(), "rejected: $junk" );
			remove_all_filters( 'newspack_admin_primary_column_min_width' );
		}
	}

	/**
	 * The init() self-call registers the admin_head hooks on require.
	 */
	public function test_init_registers_admin_head_hooks(): void {
		$this->assertNotFalse(
			has_action( 'admin_head-edit.php', [ Admin_List_Table_Layout::class, 'render_styles' ] )
		);
		$this->assertNotFalse(
			has_action( 'admin_head-edit-tags.php', [ Admin_List_Table_Layout::class, 'render_styles' ] )
		);
	}
}
