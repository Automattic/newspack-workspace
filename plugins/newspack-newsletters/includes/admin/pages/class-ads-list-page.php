<?php
/**
 * Newsletter Ads list admin page (React DataView).
 *
 * Replaces the classic ads CPT WP_List_Table in both standalone and
 * bundled modes. Registers as a hidden submenu under the parent WP
 * resolves to at access-check time; the visible click target is the
 * auto-generated `edit.php?post_type=newspack_nl_ads_cpt` submenu
 * which `Admin_Shell_Legacy_Redirect::maybe_redirect_legacy_list`
 * 302s to the React page.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

use Newspack_Newsletters\Ads;

defined( 'ABSPATH' ) || exit;

/**
 * "Newsletter Ads" list page — registered in both modes.
 */
class Ads_List_Page extends Hidden_React_List_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-newsletters-ads-list';

	/**
	 * Get the page label.
	 *
	 * Matches the CPT's `menu_name` (not `all_items`) — keeps the
	 * React `<h1>` short.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Newsletter Ads', 'newspack-newsletters' );
	}

	/**
	 * Register under the parent WP resolves to at access-check time —
	 * mode-dependent (top-level when the ads CPT has its own menu,
	 * under the newsletters CPT otherwise).
	 *
	 * @return string
	 */
	public function get_parent_slug(): string {
		return Ads::get_top_level_url();
	}

	/**
	 * Submenu entry to highlight — always the ads CPT URL, regardless
	 * of where `Ads::add_ads_page` placed it.
	 *
	 * @return string
	 */
	public function get_submenu_file(): ?string {
		return 'edit.php?post_type=' . Ads::CPT;
	}

	/**
	 * Classic CPT list screen the React page shadows.
	 *
	 * @return string
	 */
	public function get_legacy_screen_id(): ?string {
		return 'edit-' . Ads::CPT;
	}

	/**
	 * Post type the React page lives under in the admin URL.
	 *
	 * @return string
	 */
	public function get_redirect_post_type(): string {
		return Ads::CPT;
	}

	/**
	 * Canonical Ads tab URL — the wizard header's strict URL equality
	 * check would otherwise miss our `&page=…` subpage.
	 *
	 * @return string
	 */
	public function get_wizard_tab_url(): ?string {
		return admin_url( 'edit.php?post_type=' . Ads::CPT );
	}
}
