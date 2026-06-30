<?php
/**
 * Hidden React list-page base — registered but invisible submenu.
 *
 * Routable via its slug; the visible submenu entry is stripped by
 * `Admin_Shell_Menu::register_menu`.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Base for hidden-submenu React list pages.
 */
abstract class Hidden_React_List_Page extends React_List_Page {
	/**
	 * Hidden from the sidebar — accessed by 302ing the legacy URL.
	 *
	 * @return bool
	 */
	public function is_hidden_from_menu(): bool {
		return true;
	}

	/**
	 * Highlight the top-level entry the page is registered under.
	 *
	 * @return string
	 */
	public function get_parent_file(): ?string {
		return $this->get_parent_slug();
	}
}
