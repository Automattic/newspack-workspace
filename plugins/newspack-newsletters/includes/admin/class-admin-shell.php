<?php
/**
 * Admin shell bootstrap.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletters admin shell — orchestration.
 */
class Admin_Shell {
	/**
	 * Boot hooks.
	 */
	public static function init() {
		Admin_Shell_Menu::init();
		Admin_Shell_Legacy_Redirect::init();
		Admin_Shell_Assets::init();
		add_filter( 'admin_body_class', [ __CLASS__, 'add_body_class' ] );
		add_filter( 'parent_file', [ __CLASS__, 'highlight_parent_menu' ] );
		add_filter( 'submenu_file', [ __CLASS__, 'highlight_submenu' ] );
	}

	/**
	 * `parent_file` filter — delegates to the current page.
	 *
	 * @param string $parent_file The current parent file value.
	 * @return string
	 */
	public static function highlight_parent_menu( $parent_file ) {
		$page = self::get_current_page();
		if ( $page ) {
			$override = $page->get_parent_file();
			if ( null !== $override ) {
				return $override;
			}
		}
		return $parent_file;
	}

	/**
	 * `submenu_file` filter — delegates to the current page.
	 *
	 * @param string $submenu_file The current submenu file value.
	 * @return string
	 */
	public static function highlight_submenu( $submenu_file ) {
		$page = self::get_current_page();
		if ( $page ) {
			$override = $page->get_submenu_file();
			if ( null !== $override ) {
				return $override;
			}
		}
		return $submenu_file;
	}

	/**
	 * Add a body class on chassis-managed admin pages so SCSS can scope styling.
	 *
	 * @param string $classes Existing body classes (space-separated).
	 * @return string
	 */
	public static function add_body_class( $classes ) {
		if ( ! self::get_current_page() ) {
			return $classes;
		}
		$classes .= ' newspack-newsletters-admin-screen';
		if ( self::is_bundled_mode() ) {
			$classes .= ' newspack-newsletters-admin-screen--bundled';
		}
		return $classes;
	}

	/**
	 * Resolve the Admin_Page matching the current admin request, if any.
	 *
	 * @return Admin_Page|null
	 */
	public static function get_current_page() {
		foreach ( self::get_pages() as $page ) {
			if ( $page->is_admin_page() ) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * Whether the plugin is running alongside newspack-plugin.
	 *
	 * @return bool
	 */
	public static function is_bundled_mode() {
		/**
		 * Filters whether the admin shell should run in bundled mode.
		 *
		 * Bundled mode means newspack-plugin is the canonical surface for
		 * shared settings (Engagement > Newsletters); standalone mode
		 * means this plugin owns its own settings page.
		 *
		 * @param bool $is_bundled Default detection: whether the Newspack core class is loaded.
		 */
		return (bool) apply_filters( 'newspack_newsletters_admin_bundled_mode', class_exists( '\Newspack\Newspack' ) );
	}

	/**
	 * Registered admin pages, filtered by mode.
	 *
	 * Settings is omitted in bundled mode — newspack-plugin's Engagement >
	 * Newsletters is the canonical settings surface.
	 *
	 * @return Admin_Page[]
	 */
	public static function get_pages() {
		$pages = [
			new Pages\Newsletters_List_Page(),
			new Pages\Ads_List_Page(),
			new Pages\Advertisers_List_Page(),
			new Pages\Layouts_List_Page(),
		];

		if ( ! self::is_bundled_mode() ) {
			$pages[] = new Pages\Settings_Page();
		}

		return $pages;
	}
}
