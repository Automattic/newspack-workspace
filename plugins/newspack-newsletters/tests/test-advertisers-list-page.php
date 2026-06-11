<?php
/**
 * Class Test Advertisers List Page
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Pages\Advertisers_List_Page;
use Newspack_Newsletters\Ads;

/**
 * Page-class metadata for the React advertisers list view.
 */
class Advertisers_List_Page_Test extends WP_UnitTestCase {
	/**
	 * Slug matches the chassis screen registry and the URL used by the
	 * menu redirect.
	 */
	public function test_slug_is_newspack_newsletters_advertisers_list() {
		$page = new Advertisers_List_Page();
		$this->assertSame( 'newspack-newsletters-advertisers-list', $page->get_slug() );
	}

	/**
	 * Label is "Advertisers" — the short form. The visible click target
	 * is either the auto-generated taxonomy submenu (standalone) or the
	 * wizard's Advertisers tab (bundled), so this label only surfaces as
	 * the React page's heading and the browser tab.
	 */
	public function test_label_is_advertisers() {
		$page = new Advertisers_List_Page();
		$this->assertSame( 'Advertisers', $page->get_label() );
	}

	/**
	 * The advertisers list page registers under whichever parent WP
	 * resolves at request time so the hookname matches across
	 * registration and lookup — same dynamic parent as the ads list. In
	 * the default test context the user has full caps and
	 * `Ads::display_ads_menu_item_separately()` is false (submenu mode),
	 * which yields the newsletters CPT as the parent. The page is also
	 * marked hidden so the visible submenu entry is stripped — the
	 * actual click target is the auto-generated taxonomy submenu (or
	 * the wizard tab in bundled mode).
	 */
	public function test_registers_hidden_under_the_resolved_parent_in_submenu_mode() {
		$page = new Advertisers_List_Page();

		$this->assertFalse(
			\Newspack_Newsletters\Ads::display_ads_menu_item_separately(),
			'Test environment should be in submenu mode (full caps); the assertion below assumes it.'
		);
		$this->assertSame(
			'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$page->get_parent_slug()
		);
		$this->assertTrue( $page->is_hidden_from_menu() );
	}

	/**
	 * Mount id is derived from the slug so the JS shell can resolve it
	 * from the localised global.
	 */
	public function test_mount_id_matches_slug() {
		$page = new Advertisers_List_Page();
		$this->assertSame( 'newspack-newsletters-advertisers-list-root', $page->get_mount_id() );
	}

	/**
	 * Submenu highlighting points at the auto-generated taxonomy submenu
	 * the standalone-mode sidebar exposes. The advertiser tax is shared
	 * with the newsletters CPT (the only one with `show_in_menu`), so
	 * submenu mode highlights the entry under the newsletters CPT URL.
	 * WP core stores the auto-tax submenu slug with an `&amp;`-encoded
	 * ampersand; the filter return must match verbatim.
	 */
	public function test_submenu_file_targets_advertiser_taxonomy() {
		$page = new Advertisers_List_Page();
		$this->assertSame(
			'edit-tags.php?taxonomy=' . Ads::ADVERTISER_TAX . '&amp;post_type=' . \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			$page->get_submenu_file()
		);
	}

	/**
	 * Legacy screen id matches the classic taxonomy term-management
	 * screen so `Admin_Shell_Legacy_Redirect::maybe_redirect_legacy_list` can route the
	 * GET request to the React page. `WP_Screen::id` for
	 * `edit-tags.php?taxonomy=X` is `edit-X`.
	 */
	public function test_legacy_screen_id_matches_advertiser_taxonomy() {
		$page = new Advertisers_List_Page();
		$this->assertSame( 'edit-' . Ads::ADVERTISER_TAX, $page->get_legacy_screen_id() );
	}

	/**
	 * The legacy redirect target lands on the React page URL. The page
	 * shadows the ads CPT (parent) so the URL keeps the ads CPT
	 * `post_type` query — that's what newspack-plugin's
	 * `Newsletters_Wizard` recognises to render the dark Newspack
	 * admin-header chrome on top.
	 */
	public function test_legacy_redirect_target_lands_on_ads_cpt_url() {
		$page   = new Advertisers_List_Page();
		$target = $page->get_legacy_redirect_target();
		$this->assertStringContainsString( 'edit.php', $target );
		$this->assertStringContainsString( 'post_type=' . Ads::CPT, $target );
		$this->assertStringContainsString( 'page=newspack-newsletters-advertisers-list', $target );
	}

	/**
	 * The wizard-tab override returns the Advertisers tab URL —
	 * `edit-tags.php?taxonomy=newspack_nl_advertiser&post_type=newspack_nl_cpt`,
	 * matching the href `Newsletters_Wizard::get_tabs()` renders.
	 * Without this override the wizard's strict URL equality check would
	 * fail (live URL has the extra `&page=…` query) and the tab would
	 * render without `.selected`.
	 */
	public function test_wizard_tab_url_targets_advertisers_tab() {
		$page = new Advertisers_List_Page();
		$this->assertSame(
			admin_url( 'edit-tags.php?taxonomy=' . Ads::ADVERTISER_TAX . '&post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT ),
			$page->get_wizard_tab_url()
		);
	}
}
