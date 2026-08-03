<?php
/**
 * Class Test Admin Shell
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Admin_Shell;
use Newspack\Newsletters\Admin\Admin_Shell_Assets;
use Newspack\Newsletters\Admin\Admin_Shell_Legacy_Redirect;
use Newspack\Newsletters\Admin\Admin_Shell_Menu;
use Newspack\Newsletters\Admin\Pages\Newsletters_List_Page;

/**
 * Admin Shell Test.
 */
class Admin_Shell_Test extends WP_UnitTestCase {
	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_newsletters_admin_bundled_mode' );
		parent::tear_down();
	}

	/**
	 * Default detection: when the Newspack core class exists, bundled mode is true;
	 * the filter can still override either way.
	 *
	 * Runs in a separate PHPUnit process so the `class_alias` to `\Newspack\Newspack`
	 * does not leak into other tests' default detection.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_is_bundled_mode_default_detection() {
		if ( ! class_exists( '\Newspack\Newspack' ) ) {
			class_alias( '\stdClass', '\Newspack\Newspack' );
		}

		// With the class present and no filter, default detection is true.
		$this->assertTrue( Admin_Shell::is_bundled_mode() );

		// Filter overrides the true default.
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_false' );
		$this->assertFalse( Admin_Shell::is_bundled_mode() );
	}

	/**
	 * The filter contract: forcing true returns true regardless of class state.
	 */
	public function test_is_bundled_mode_filter_can_force_true() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$this->assertTrue( Admin_Shell::is_bundled_mode() );
	}

	/**
	 * The filter contract: forcing false returns false regardless of class state.
	 */
	public function test_is_bundled_mode_filter_can_force_false() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_false' );
		$this->assertFalse( Admin_Shell::is_bundled_mode() );
	}

	/**
	 * Standalone mode exposes the list views alongside Settings. The
	 * ads list, advertisers list, and layouts list all register in
	 * both modes — Settings remains the only mode-gated entry.
	 */
	public function test_get_pages_in_standalone_mode_includes_list_ads_advertisers_layouts_and_settings() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_false' );
		$slugs = array_map(
			function ( $page ) {
				return $page->get_slug();
			},
			Admin_Shell::get_pages()
		);
		$this->assertSame(
			[
				'newspack-newsletters-list',
				'newspack-newsletters-ads-list',
				'newspack-newsletters-advertisers-list',
				'newspack-newsletters-layouts-list',
				'newspack-newsletters-settings',
			],
			$slugs
		);
	}

	/**
	 * Bundled mode defers Settings to newspack-plugin's Engagement >
	 * Newsletters surface — the newsletters list, ads list, advertisers
	 * list, and layouts list still register in this mode.
	 */
	public function test_get_pages_in_bundled_mode_includes_list_ads_advertisers_and_layouts_only() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$slugs = array_map(
			function ( $page ) {
				return $page->get_slug();
			},
			Admin_Shell::get_pages()
		);
		$this->assertSame(
			[
				'newspack-newsletters-list',
				'newspack-newsletters-ads-list',
				'newspack-newsletters-advertisers-list',
				'newspack-newsletters-layouts-list',
			],
			$slugs
		);
	}

	/**
	 * The redirect target points at our React page slug under the CPT's parent.
	 * Returning the URL (rather than performing the redirect) keeps the test
	 * isolated from `wp_safe_redirect`'s exit behaviour.
	 */
	public function test_legacy_list_url_redirects_to_react_page() {
		$target = ( new Newsletters_List_Page() )->get_legacy_redirect_target();
		$this->assertStringContainsString( 'edit.php?', $target );
		$this->assertStringContainsString( 'post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT, $target );
		$this->assertStringContainsString( 'page=newspack-newsletters-list', $target );
		$this->assertStringNotContainsString( 'post_status', $target );
	}

	/**
	 * Deep links like `?post_type=newspack_nl_cpt&post_status=trash` forward
	 * the `post_status` value onto the React page so the JS side can
	 * pre-fill its filter (see `getInitialView`). String form retained for
	 * back-compat with the original signature.
	 */
	public function test_legacy_redirect_forwards_post_status() {
		$target = ( new Newsletters_List_Page() )->get_legacy_redirect_target( 'trash' );
		$this->assertStringContainsString( 'post_status=trash', $target );
		$this->assertStringContainsString( 'page=newspack-newsletters-list', $target );
	}

	/**
	 * Search and sort are forwarded too so deep links to filtered/sorted
	 * legacy URLs (`?s=…&orderby=title&order=asc`) land on the React page
	 * with equivalent view state — Copilot review #2095.
	 */
	public function test_legacy_redirect_forwards_search_and_sort() {
		$target = ( new Newsletters_List_Page() )->get_legacy_redirect_target(
			[
				's'       => 'weeklydigest',
				'orderby' => 'title',
				'order'   => 'asc',
			]
		);
		$this->assertStringContainsString( 's=weeklydigest', $target );
		$this->assertStringContainsString( 'orderby=title', $target );
		$this->assertStringContainsString( 'order=asc', $target );
	}

	/**
	 * `paged` is deliberately NOT forwarded — legacy WP_List_Table uses
	 * 20 items per page while the DataView uses 25, so the page number
	 * doesn't translate cleanly. The redirect drops it on the floor.
	 */
	public function test_legacy_redirect_drops_paged() {
		$target = ( new Newsletters_List_Page() )->get_legacy_redirect_target( [ 'paged' => '3' ] );
		$this->assertStringNotContainsString( 'paged=3', $target );
	}

	/**
	 * Author / categories / tags / send_list filter params survive the
	 * legacy → React redirect so bookmarked filtered URLs round-trip.
	 */
	public function test_legacy_redirect_forwards_new_filter_params() {
		$target  = ( new Newsletters_List_Page() )->get_legacy_redirect_target(
			[
				'author'                            => '42,7',
				'categories'                        => '12',
				'tags'                              => '5,11',
				'newspack_newsletters_send_list_id' => 'list-a,list-b',
			]
		);
		$decoded = urldecode( $target );
		$this->assertStringContainsString( 'author=42,7', $decoded );
		$this->assertStringContainsString( 'categories=12', $decoded );
		$this->assertStringContainsString( 'tags=5,11', $decoded );
		$this->assertStringContainsString( 'newspack_newsletters_send_list_id=list-a,list-b', $decoded );
	}

	/**
	 * Helper: route requests through the redirect handler under fake screen
	 * conditions so we can probe the action-detection logic without
	 * actually redirecting.
	 *
	 * @param array $get GET superglobal contents.
	 * @return bool True when the redirect would have run (i.e. exited).
	 */
	private function would_redirect_with_get( $get ) {
		$_GET                       = $get;
		$_SERVER['REQUEST_METHOD']  = 'GET';

		$screen = WP_Screen::get( 'edit-' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );

		$reflection = new ReflectionMethod( Admin_Shell_Legacy_Redirect::class, 'has_real_get_action' );
		$reflection->setAccessible( true );

		// `has_real_get_action` is the gate the live redirect uses; if it
		// returns false, the redirect would proceed.
		$would_run = ! $reflection->invoke( null );

		$_GET = [];
		unset( $_SERVER['REQUEST_METHOD'] );
		unset( $screen );

		return $would_run;
	}

	/**
	 * `action=-1` is WP's "no bulk action selected" sentinel — submitting
	 * the bulk-actions form without picking one leaves it in the URL.
	 * The redirect should still run for those stale URLs.
	 */
	public function test_legacy_redirect_runs_when_action_is_minus_one() {
		$this->assertTrue( $this->would_redirect_with_get( [ 'action' => '-1' ] ) );
	}

	/**
	 * Same sentinel can appear on the bottom-of-table dropdown as `action2`.
	 */
	public function test_legacy_redirect_runs_when_action2_is_minus_one() {
		$this->assertTrue( $this->would_redirect_with_get( [ 'action2' => '-1' ] ) );
		$this->assertTrue(
			$this->would_redirect_with_get(
				[
					'action'  => '-1',
					'action2' => '-1',
				]
			)
		);
	}

	/**
	 * Real bulk-action values (anything other than the `-1` sentinel)
	 * still bypass the redirect so any classic form-submission flow has
	 * a chance to run.
	 */
	public function test_legacy_redirect_skips_for_real_actions() {
		$this->assertFalse( $this->would_redirect_with_get( [ 'action' => 'trash' ] ) );
		$this->assertFalse( $this->would_redirect_with_get( [ 'action2' => 'edit' ] ) );
	}

	/**
	 * Settings is registered with the CPT as parent so it appears as a
	 * visible submenu in standalone mode. Regression: the previous shape
	 * passed `null` for every page, which silently hid Settings.
	 */
	public function test_settings_page_parent_is_the_cpt_so_it_is_visible_in_the_menu() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_false' );
		$pages = Admin_Shell::get_pages();
		$settings = null;
		foreach ( $pages as $page ) {
			if ( 'newspack-newsletters-settings' === $page->get_slug() ) {
				$settings = $page;
			}
		}
		$this->assertNotNull( $settings );
		$this->assertSame(
			'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$settings->get_parent_slug()
		);
	}

	/**
	 * On a chassis-managed page, the parent_file filter forces the Newsletters
	 * CPT to be the active top-level menu so the sidebar highlights correctly.
	 */
	public function test_highlight_parent_menu_returns_cpt_url_when_on_a_managed_page() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$_GET['page'] = 'newspack-newsletters-list';
		set_current_screen( 'admin_page_newspack-newsletters-list' );

		$expected = 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$this->assertSame( $expected, Admin_Shell::highlight_parent_menu( 'unrelated.php' ) );

		unset( $_GET['page'] );
	}

	/**
	 * Off-page calls pass through unchanged.
	 */
	public function test_highlight_parent_menu_passes_through_off_page() {
		unset( $_GET['page'] );
		$this->assertSame( 'unrelated.php', Admin_Shell::highlight_parent_menu( 'unrelated.php' ) );
	}

	/**
	 * The list page maps onto the auto-generated "All Newsletters" submenu so
	 * WP's sidebar highlights it instead of leaving every entry inactive.
	 */
	public function test_highlight_submenu_targets_all_newsletters_for_list_page() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$_GET['page'] = 'newspack-newsletters-list';
		set_current_screen( 'admin_page_newspack-newsletters-list' );

		$expected = 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$this->assertSame( $expected, Admin_Shell::highlight_submenu( 'unrelated' ) );

		unset( $_GET['page'] );
	}

	/**
	 * The ads list page registers in both modes and highlights the
	 * Newsletter Ads CPT submenu — its visible click target is the
	 * auto-generated `edit.php?post_type=newspack_nl_ads_cpt` entry,
	 * the same way the newsletters list page maps onto its CPT submenu.
	 */
	public function test_highlight_submenu_targets_ads_cpt_for_ads_list_page() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$_GET['page'] = 'newspack-newsletters-ads-list';
		set_current_screen( 'admin_page_newspack-newsletters-ads-list' );

		$expected = 'edit.php?post_type=' . \Newspack_Newsletters\Ads::CPT;
		$this->assertSame( $expected, Admin_Shell::highlight_submenu( 'unrelated' ) );

		unset( $_GET['page'] );
	}

	/**
	 * The legacy ads CPT URL (`edit.php?post_type=newspack_nl_ads_cpt`)
	 * redirects to the React ads page slug under the ads CPT parent.
	 * Forwarded args (`post_status` etc.) ride through.
	 */
	public function test_ads_legacy_redirect_target_points_to_react_ads_page() {
		$page   = new \Newspack\Newsletters\Admin\Pages\Ads_List_Page();
		$target = $page->get_legacy_redirect_target();
		$this->assertStringContainsString( 'edit.php?', $target );
		$this->assertStringContainsString( 'post_type=' . \Newspack_Newsletters\Ads::CPT, $target );
		$this->assertStringContainsString( 'page=newspack-newsletters-ads-list', $target );
	}

	/**
	 * Forwarded query args (`post_status`, etc.) are appended to the
	 * ads redirect target so the React page can seed initial filter
	 * state from a deep-linked legacy URL.
	 */
	public function test_ads_legacy_redirect_forwards_post_status() {
		$page   = new \Newspack\Newsletters\Admin\Pages\Ads_List_Page();
		$target = $page->get_legacy_redirect_target( [ 'post_status' => 'trash' ] );
		$this->assertStringContainsString( 'post_status=trash', $target );
		$this->assertStringContainsString( 'page=newspack-newsletters-ads-list', $target );
	}

	/**
	 * `Admin_Shell_Menu::register_menu` registers each hidden page's
	 * callback under both the parent-derived hookname (what
	 * `add_submenu_page` returns) and the URL-derived `admin_page_*`
	 * hookname `admin.php` line ~182 looks up at request time. Without
	 * the mirror, hidden React pages 500 with "Cannot load X" when
	 * the URL's typenow CPT isn't itself a top-level menu (the ads
	 * case in submenu mode).
	 */
	public function test_register_menu_mirrors_hidden_pages_under_the_admin_page_hookname() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );

		// Run the same hook the admin chrome would fire.
		Admin_Shell_Menu::register_menu();

		global $_registered_pages;

		foreach ( Admin_Shell::get_pages() as $page ) {
			if ( ! $page->is_hidden_from_menu() ) {
				continue;
			}
			$shadow_hookname = 'admin_page_' . $page->get_slug();
			$this->assertTrue(
				isset( $_registered_pages[ $shadow_hookname ] ),
				sprintf( 'Expected %s to be registered for %s', $shadow_hookname, $page->get_slug() )
			);
			$this->assertNotFalse(
				has_action( $shadow_hookname ),
				sprintf( 'Expected an action under %s', $shadow_hookname )
			);
		}
	}

	/**
	 * In submenu mode (the common case where the user can edit
	 * newsletters), the ads page lives under the Newsletters CPT — so
	 * `parent_file` should resolve to the newsletters CPT URL. The
	 * default test user is an admin with all caps, which exercises
	 * this branch of `Ads::display_ads_menu_item_separately()`.
	 */
	public function test_highlight_parent_menu_for_ads_page_in_submenu_mode() {
		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$_GET['page'] = 'newspack-newsletters-ads-list';
		set_current_screen( 'admin_page_newspack-newsletters-ads-list' );

		$expected = 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		$this->assertSame( $expected, Admin_Shell::highlight_parent_menu( 'unrelated.php' ) );

		unset( $_GET['page'] );
	}

	/**
	 * `patch_wizard_header_active_tab` attaches an inline script to
	 * the wizard header bundle that flips the matching `<a>` to
	 * `.selected` once the React component mounts. Verifies the
	 * inline script is registered against the correct handle and
	 * carries the page's `get_wizard_tab_url()` as the target URL.
	 *
	 * The wizard header script lives in newspack-plugin and isn't
	 * registered in the test bootstrap, so we register a stub under
	 * the same handle to give `wp_add_inline_script` a target.
	 */
	public function test_patch_wizard_header_attaches_inline_selected_script_for_ads_page() {
		wp_register_script( 'newspack-wizards-admin-header', 'http://example.com/admin-header.js', [], '1.0.0', true );

		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$_GET['page'] = 'newspack-newsletters-ads-list';
		set_current_screen( 'admin_page_newspack-newsletters-ads-list' );

		Admin_Shell_Assets::patch_wizard_header_active_tab();

		$inline = wp_scripts()->get_data( 'newspack-wizards-admin-header', 'after' );
		$this->assertIsArray( $inline );
		$joined = implode( "\n", array_filter( $inline ) );

		$this->assertStringContainsString( '.newspack-tabbed-navigation a', $joined );
		$this->assertStringContainsString( 'classList.add', $joined );
		$this->assertStringContainsString(
			wp_json_encode( admin_url( 'edit.php?post_type=' . Newspack_Newsletters\Ads::CPT ) ),
			$joined
		);

		unset( $_GET['page'] );
		wp_deregister_script( 'newspack-wizards-admin-header' );
	}

	/**
	 * Pages with no `get_wizard_tab_url()` override (the default base
	 * implementation returns `null`) get no inline script — the
	 * wizard header doesn't render tabs on those screens, so there's
	 * nothing to patch.
	 */
	public function test_patch_wizard_header_skips_pages_without_a_tab_override() {
		wp_register_script( 'newspack-wizards-admin-header', 'http://example.com/admin-header.js', [], '1.0.0', true );

		add_filter( 'newspack_newsletters_admin_bundled_mode', '__return_true' );
		$_GET['page'] = 'newspack-newsletters-list';

		Admin_Shell_Assets::patch_wizard_header_active_tab();

		$inline = wp_scripts()->get_data( 'newspack-wizards-admin-header', 'after' );
		$this->assertEmpty( $inline, 'No inline script should be attached when the current page has no wizard-tab override.' );

		unset( $_GET['page'] );
		wp_deregister_script( 'newspack-wizards-admin-header' );
	}
}
