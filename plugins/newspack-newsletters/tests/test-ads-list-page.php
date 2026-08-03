<?php
/**
 * Class Test Ads List Page
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Pages\Ads_List_Page;
use Newspack_Newsletters\Ads;

/**
 * Page-class metadata for the React ads list view.
 */
class Ads_List_Page_Test extends WP_UnitTestCase {
	/**
	 * Slug matches the chassis screen registry and the URL used by the
	 * menu redirect.
	 */
	public function test_slug_is_newspack_newsletters_ads_list() {
		$page = new Ads_List_Page();
		$this->assertSame( 'newspack-newsletters-ads-list', $page->get_slug() );
	}

	/**
	 * Label matches the CPT's `menu_name` label (the short form),
	 * intentionally diverging from `Newsletters_List_Page`'s
	 * `all_items` convention. Keeps the React page title short
	 * ("Newsletter Ads", not "All Newsletter Ads") since the visible
	 * click target is the auto-generated CPT submenu and this label
	 * only surfaces as the React page's heading.
	 */
	public function test_label_matches_cpt_menu_name() {
		$page = new Ads_List_Page();
		$this->assertSame( 'Newsletter Ads', $page->get_label() );
	}

	/**
	 * The ads list page registers under whichever parent WP resolves
	 * at request time so the hookname matches across registration and
	 * lookup. In the default test context the user has full caps and
	 * `Ads::display_ads_menu_item_separately()` is false — submenu
	 * mode — which yields the newsletters CPT as the parent. The page
	 * is also marked hidden so the visible submenu entry is stripped.
	 */
	public function test_registers_hidden_under_the_resolved_parent_in_submenu_mode() {
		$page = new Ads_List_Page();

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
	 * Mount id is derived from the slug so the JS shell can resolve
	 * it from the localised global.
	 */
	public function test_mount_id_matches_slug() {
		$page = new Ads_List_Page();
		$this->assertSame( 'newspack-newsletters-ads-list-root', $page->get_mount_id() );
	}

	/**
	 * Submenu highlighting points to the ads CPT entry — the visible
	 * click target whether `Ads::add_ads_page` placed it as a top-level
	 * menu or as a submenu under the Newsletters CPT.
	 */
	public function test_submenu_file_targets_ads_cpt() {
		$page = new Ads_List_Page();
		$this->assertSame(
			'edit.php?post_type=' . Ads::CPT,
			$page->get_submenu_file()
		);
	}

	/**
	 * Legacy screen id matches the classic ads CPT list so
	 * `Admin_Shell_Legacy_Redirect::maybe_redirect_legacy_list` can route the GET
	 * request to the React page.
	 */
	public function test_legacy_screen_id_matches_ads_cpt() {
		$page = new Ads_List_Page();
		$this->assertSame( 'edit-' . Ads::CPT, $page->get_legacy_screen_id() );
	}

	/**
	 * The wizard-tab override returns the ads CPT URL — the canonical
	 * "Ads" tab href in `Newsletters_Wizard::get_tabs()`. Without this,
	 * the wizard header's strict URL equality check fails for our
	 * hidden React subpage (live URL has an extra `&page=…` query)
	 * and the tab renders without `.selected`. The default base
	 * implementation returns `null`; the override here is what makes
	 * `Admin_Shell_Assets::patch_wizard_header_active_tab` flip the tab.
	 */
	public function test_wizard_tab_url_targets_ads_cpt() {
		$page = new Ads_List_Page();
		$this->assertSame(
			admin_url( 'edit.php?post_type=' . Ads::CPT ),
			$page->get_wizard_tab_url()
		);
	}
}
