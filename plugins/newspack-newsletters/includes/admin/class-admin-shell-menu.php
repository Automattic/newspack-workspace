<?php
/**
 * Admin shell — menu registration.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu + submenu registration.
 */
class Admin_Shell_Menu {
	/**
	 * Slug => hookname returned by `add_submenu_page`. Captured at
	 * registration so `Admin_Page::is_admin_page()` can narrow its
	 * match to the actual screen.
	 *
	 * @var array<string,string>
	 */
	private static $hook_suffixes = [];

	/**
	 * Lookup the registered hookname for a given page slug.
	 *
	 * @param string $slug Page slug.
	 * @return string Hookname, or `''` if the slug was not registered.
	 */
	public static function get_hook_suffix_for_slug( $slug ) {
		return isset( self::$hook_suffixes[ $slug ] ) ? self::$hook_suffixes[ $slug ] : '';
	}

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		// Priority 999 so we run after every other contributor — auto-generated CPT submenus, ads, third-party plugins.
		add_action( 'admin_menu', [ __CLASS__, 'reorder_submenus' ], 999 );
	}

	/**
	 * Reposition chassis submenu entries that declare a fixed index.
	 *
	 * Runs late so it sees the final submenu array; re-keys with
	 * `array_values()` so WP's downstream sort doesn't reshuffle us.
	 */
	public static function reorder_submenus() {
		global $submenu;
		foreach ( Admin_Shell::get_pages() as $page ) {
			$desired_index = $page->get_submenu_index();
			if ( null === $desired_index ) {
				continue;
			}
			$parent_slug = $page->get_parent_slug();
			if ( empty( $submenu[ $parent_slug ] ) ) {
				continue;
			}

			$entries = array_values( $submenu[ $parent_slug ] );

			$found_at = null;
			foreach ( $entries as $idx => $entry ) {
				if ( ( $entry[2] ?? '' ) === $page->get_slug() ) {
					$found_at = $idx;
					break;
				}
			}
			if ( null === $found_at ) {
				continue;
			}

			$entry = $entries[ $found_at ];
			array_splice( $entries, $found_at, 1 );
			$insert = max( 0, min( $desired_index, count( $entries ) ) );
			array_splice( $entries, $insert, 0, [ $entry ] );

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering an admin-menu structure that WP itself populates this global with.
			$submenu[ $parent_slug ] = $entries;
		}
	}

	/**
	 * Register chassis pages as submenus.
	 *
	 * Hidden pages stay URL-routable but the visible submenu entry is
	 * stripped after registration.
	 */
	public static function register_menu() {
		global $_registered_pages;
		self::$hook_suffixes = [];
		foreach ( Admin_Shell::get_pages() as $page ) {
			$parent_slug = $page->get_parent_slug();
			$hook_suffix = add_submenu_page(
				$parent_slug,
				$page->get_label(),
				$page->get_label(),
				$page->get_capability(),
				$page->get_slug(),
				[ $page, 'render' ]
			);
			if ( is_string( $hook_suffix ) && '' !== $hook_suffix ) {
				$page->set_hook_suffix( $hook_suffix );
				self::$hook_suffixes[ $page->get_slug() ] = $hook_suffix;
			}
			if ( $page->is_hidden_from_menu() ) {
				// WP's admin.php uses a URL-derived hookname for the page render lookup; when the parent CPT isn't a top-level menu it falls back to `admin_page_*`. Mirror the action there so the page still dispatches.
				$shadow_hookname = 'admin_page_' . $page->get_slug();
				if ( ! has_action( $shadow_hookname ) ) {
					add_action( $shadow_hookname, [ $page, 'render' ] );
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Adding a single plugin-unique entry, not clobbering.
					$_registered_pages[ $shadow_hookname ] = true;
				}
				remove_submenu_page( $parent_slug, $page->get_slug() );
			}
		}
		// `remove_submenu_page` strips the entry WP reads for the `<title>` tag. Reinstate it once the screen is known — fires before `admin-header.php`.
		add_action( 'current_screen', [ __CLASS__, 'set_title_for_hidden_pages' ] );
	}

	/**
	 * Set `$title` on hidden chassis pages so the browser tab matches the page label.
	 */
	public static function set_title_for_hidden_pages() {
		$page = Admin_Shell::get_current_page();
		if ( ! $page || ! $page->is_hidden_from_menu() ) {
			return;
		}
		$GLOBALS['title'] = $page->get_label(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
