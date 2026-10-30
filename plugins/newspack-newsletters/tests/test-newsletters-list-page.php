<?php
/**
 * Class Test Newsletters List Page
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Pages\Newsletters_List_Page;

/**
 * Page-class metadata for the React list view.
 */
class Newsletters_List_Page_Test extends WP_UnitTestCase {
	/**
	 * `WP_UnitTestCase` doesn't reset `wp_scripts` between tests, so the
	 * enqueue cases below would otherwise leave `heartbeat` queued, or
	 * deregistered, for everything that runs after them in this process.
	 */
	public function tear_down() {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		parent::tear_down();
	}

	/**
	 * Slug matches the chassis screen registry and the URL the menu link uses.
	 */
	public function test_slug_is_newspack_newsletters_list() {
		$page = new Newsletters_List_Page();
		$this->assertSame( 'newspack-newsletters-list', $page->get_slug() );
	}

	/**
	 * Label is the (translatable) "All Newsletters" string — the same label
	 * the auto-generated CPT submenu used, so the menu position is visually
	 * unchanged after the replacement.
	 */
	public function test_label_is_all_newsletters() {
		$page = new Newsletters_List_Page();
		$this->assertSame( 'All Newsletters', $page->get_label() );
	}

	/**
	 * Capability inherits the chassis default — anyone who could see the
	 * classic CPT list still sees the React replacement.
	 */
	public function test_capability_defaults_to_edit_posts() {
		$page = new Newsletters_List_Page();
		$this->assertSame( 'edit_posts', $page->get_capability() );
	}

	/**
	 * Mount id is derived from the slug so the JS shell can resolve it from
	 * the localised global without extra config.
	 */
	public function test_mount_id_matches_slug() {
		$page = new Newsletters_List_Page();
		$this->assertSame( 'newspack-newsletters-list-root', $page->get_mount_id() );
	}

	/**
	 * The list page registers under the newsletters CPT URL so its
	 * hookname matches what `admin.php` computes at request time, then
	 * is marked hidden so the visible submenu entry is stripped — its
	 * actual click target is the auto-generated CPT submenu, redirected
	 * by `Admin_Shell_Legacy_Redirect::maybe_redirect_legacy_list`.
	 */
	public function test_registers_hidden_under_the_newsletters_cpt() {
		$page = new Newsletters_List_Page();
		$this->assertSame(
			'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$page->get_parent_slug()
		);
		$this->assertTrue( $page->is_hidden_from_menu() );
	}

	/**
	 * Heartbeat carries the post-lock exchange the "currently editing" line
	 * reads.
	 */
	public function test_enqueues_heartbeat() {
		( new Newsletters_List_Page() )->enqueue_extras( 'newspack-newsletters-admin-shell' );

		$this->assertTrue( wp_script_is( 'heartbeat', 'enqueued' ) );
	}

	/**
	 * Heartbeat is a sibling enqueue, not a dependency of the admin-shell
	 * bundle, so a site that deregisters it loses the indicator rather than
	 * the screen: `WP_Dependencies::enqueue()` ignores an unregistered
	 * handle, where `all_deps()` would have dropped the bundle that named
	 * it as a dependency.
	 */
	public function test_enqueueing_heartbeat_is_inert_once_deregistered() {
		wp_deregister_script( 'heartbeat' );

		( new Newsletters_List_Page() )->enqueue_extras( 'newspack-newsletters-admin-shell' );

		$this->assertFalse( wp_script_is( 'heartbeat', 'enqueued' ) );
	}
}
