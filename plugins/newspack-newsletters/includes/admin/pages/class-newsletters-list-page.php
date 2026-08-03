<?php
/**
 * Newsletters list admin page (React DataView).
 *
 * Replaces the classic WP_List_Table for the newsletters CPT. Lives
 * under the existing CPT menu so the menu structure is preserved.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

use Newspack_Newsletters;

defined( 'ABSPATH' ) || exit;

/**
 * "All Newsletters" page — registered in both modes.
 */
class Newsletters_List_Page extends Hidden_React_List_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-newsletters-list';

	/**
	 * Get the page label. Matches the auto-generated CPT submenu
	 * label so the menu reads identically before and after the swap.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'All Newsletters', 'newspack-newsletters' );
	}

	/**
	 * Register under the newsletters CPT parent. `Admin_Shell_Menu::register_menu`
	 * removes the visible submenu because `is_hidden_from_menu()`.
	 *
	 * @return string
	 */
	public function get_parent_slug(): string {
		return 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Visible click target — the auto-generated "All Newsletters" submenu.
	 *
	 * @return string
	 */
	public function get_submenu_file(): ?string {
		return 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Classic CPT list screen the React page shadows.
	 *
	 * @return string
	 */
	public function get_legacy_screen_id(): ?string {
		return 'edit-' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Post type the React page lives under in the admin URL.
	 *
	 * @return string
	 */
	public function get_redirect_post_type(): string {
		return Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}
}
