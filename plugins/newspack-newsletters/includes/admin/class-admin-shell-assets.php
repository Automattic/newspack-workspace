<?php
/**
 * Admin shell — asset enqueue.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

use Newspack_Newsletters;

defined( 'ABSPATH' ) || exit;

/**
 * Asset enqueue + wizard-header breadcrumb filter.
 */
class Admin_Shell_Assets {
	const SCRIPT_HANDLE = 'newspack-newsletters-admin-shell';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'newspack_wizards_admin_header_breadcrumbs', [ __CLASS__, 'filter_breadcrumbs' ] );
	}

	/**
	 * Enqueue the shared admin-shell bundle on registered admin pages.
	 *
	 * Pages contribute style deps via `get_admin_shell_style_deps()`
	 * and sibling enqueues via `enqueue_extras()`.
	 */
	public static function enqueue() {
		$current_page = Admin_Shell::get_current_page();
		if ( ! $current_page ) {
			return;
		}

		$asset = Asset_Loader::enqueue_bundle(
			self::SCRIPT_HANDLE,
			'admin-shell',
			NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist',
			plugins_url( '../../dist', __FILE__ ),
			[],
			$current_page->get_admin_shell_style_deps()
		);
		if ( ! $asset ) {
			return;
		}

		$current_page->enqueue_extras( self::SCRIPT_HANDLE );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'newspackNewslettersAdmin',
			[
				'currentPage'     => $current_page->get_slug(),
				'mountId'         => $current_page->get_mount_id(),
				'label'           => $current_page->get_label(),
				'bundledMode'     => Admin_Shell::is_bundled_mode(),
				'classicSettings' => \Newspack_Newsletters_Settings::get_settings_url(),
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'restUrl'         => esc_url_raw( rest_url() ),
				'adminUrl'        => esc_url_raw( admin_url() ),
				'cptSlug'         => Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			]
		);
	}

	/**
	 * Supply the current admin-shell page's explicit breadcrumb trail to the
	 * newspack-plugin admin header, overriding the wizard's default trail.
	 *
	 * @param array $breadcrumbs Breadcrumb trail from the wizard.
	 * @return array
	 */
	public static function filter_breadcrumbs( $breadcrumbs ) {
		$current_page = Admin_Shell::get_current_page();
		if ( ! $current_page ) {
			return $breadcrumbs;
		}
		$crumbs = $current_page->get_wizard_breadcrumbs();
		return null === $crumbs ? $breadcrumbs : $crumbs;
	}
}
