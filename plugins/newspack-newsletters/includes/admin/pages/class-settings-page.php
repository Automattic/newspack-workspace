<?php
/**
 * Settings admin page (standalone mode only).
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

use Newspack\Newsletters\Admin\Admin_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page — only registered in standalone mode.
 */
class Settings_Page extends Admin_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-newsletters-settings';

	/**
	 * ESP credentials and global settings stay admin-only.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Get the page label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Settings', 'newspack-newsletters' );
	}

	/**
	 * Visible submenu under the Newsletters CPT — only the entry
	 * point standalone mode has. (Bundled mode excludes Settings via
	 * `Admin_Shell::get_pages()`.)
	 *
	 * @return string
	 */
	public function get_parent_slug(): string {
		return 'edit.php?post_type=' . \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}
}
