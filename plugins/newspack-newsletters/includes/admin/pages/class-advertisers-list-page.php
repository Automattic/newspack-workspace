<?php
/**
 * Newsletter Advertisers list admin page (React DataView).
 *
 * Replaces the classic taxonomy term-management screen for
 * `newspack_nl_advertiser`. Mirrors the ads list page — hidden
 * submenu under the ads CPT parent; the legacy `edit-tags.php`
 * URL 302s to the React page.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

use Newspack_Newsletters;
use Newspack_Newsletters\Ads;

defined( 'ABSPATH' ) || exit;

/**
 * "Advertisers" list page — registered in both modes.
 */
class Advertisers_List_Page extends Hidden_React_List_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-newsletters-advertisers-list';

	/**
	 * Get the page label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Advertisers', 'newspack-newsletters' );
	}

	/**
	 * Mirrors `Ads_List_Page::get_parent_slug` — mode-dependent.
	 *
	 * @return string
	 */
	public function get_parent_slug(): string {
		return Ads::get_top_level_url();
	}

	/**
	 * Match the auto-generated taxonomy submenu URL so the sidebar
	 * entry highlights. The advertiser tax is shared with the
	 * newsletters CPT; submenu mode highlights under the newsletters
	 * CPT URL.
	 *
	 * @return string
	 */
	public function get_submenu_file(): ?string {
		if ( Ads::display_ads_menu_item_separately() ) {
			return 'edit-tags.php?taxonomy=' . Ads::ADVERTISER_TAX . '&amp;post_type=' . Ads::CPT;
		}
		return 'edit-tags.php?taxonomy=' . Ads::ADVERTISER_TAX . '&amp;post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Classic taxonomy term-management screen the React page shadows
	 * (`edit-tags.php?taxonomy=X` resolves to `WP_Screen::id = 'edit-X'`).
	 *
	 * @return string
	 */
	public function get_legacy_screen_id(): ?string {
		return 'edit-' . Ads::ADVERTISER_TAX;
	}

	/**
	 * Post type the React page lives under — the ads CPT regardless of
	 * which taxonomy URL the user came from.
	 *
	 * @return string
	 */
	public function get_redirect_post_type(): string {
		return Ads::CPT;
	}

	/**
	 * Canonical Advertisers tab URL — wizard header's strict URL
	 * equality check would otherwise miss our `&page=…` subpage.
	 *
	 * @return string
	 */
	public function get_wizard_tab_url(): ?string {
		return admin_url( 'edit-tags.php?taxonomy=' . Ads::ADVERTISER_TAX . '&post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT );
	}
}
