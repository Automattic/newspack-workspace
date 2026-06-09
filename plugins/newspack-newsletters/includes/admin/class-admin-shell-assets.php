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
 * Asset enqueue + wizard-header inline-script patch.
 */
class Admin_Shell_Assets {
	const SCRIPT_HANDLE = 'newspack-newsletters-admin-shell';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		// Priority 99 so we run after newspack-plugin's wizard header has registered its script — `wp_add_inline_script` needs the handle in place.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'patch_wizard_header_active_tab' ], 99 );
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
	 * Patch the wizard header's "selected" tab state for hidden React subpages.
	 *
	 * The wizard's strict `window.location.href === tab.href` check
	 * breaks for subpages (live URL carries an extra `&page=…`). Each
	 * page declares its canonical tab URL + breadcrumb label; this
	 * injects an observer that flips the matching `<a>` to `.selected`
	 * and rewrites the heading once the wizard mounts.
	 */
	public static function patch_wizard_header_active_tab() {
		$current_page = Admin_Shell::get_current_page();
		if ( ! $current_page ) {
			return;
		}
		if ( ! wp_script_is( 'newspack-wizards-admin-header', 'registered' ) ) {
			return;
		}

		$tab_url          = $current_page->get_wizard_tab_url();
		$breadcrumb_label = $current_page->get_wizard_header_label();

		if ( null === $tab_url && null === $breadcrumb_label ) {
			return;
		}

		$tab_url_json    = null === $tab_url ? 'null' : wp_json_encode( $tab_url );
		$breadcrumb_json = null === $breadcrumb_label ? 'null' : wp_json_encode( $breadcrumb_label );

		wp_add_inline_script(
			'newspack-wizards-admin-header',
			sprintf(
				'( function () {
					var tabUrl = %1$s;
					var breadcrumb = %2$s;
					var observer = null;
					function apply() {
						var tabDone = ! tabUrl;
						var breadcrumbDone = ! breadcrumb;
						if ( tabUrl ) {
							var links = document.querySelectorAll( ".newspack-tabbed-navigation a" );
							links.forEach( function ( link ) {
								if ( link.href === tabUrl ) {
									link.classList.add( "selected" );
								}
							} );
							tabDone = links.length > 0;
						}
						if ( breadcrumb ) {
							var heading = document.querySelector( ".newspack-wizard__title h2" );
							if ( heading ) {
								if ( heading.textContent !== breadcrumb ) {
									heading.textContent = breadcrumb;
								}
								breadcrumbDone = true;
							}
						}
						if ( tabDone && breadcrumbDone && observer ) {
							observer.disconnect();
							observer = null;
							setTimeout( function () {
								apply();
							}, 0 );
						}
					}
					apply();
					var root = document.querySelector( ".newspack-wizard" ) || document.body;
					observer = new MutationObserver( apply );
					observer.observe( root, { childList: true, subtree: true } );
				} )();',
				$tab_url_json,
				$breadcrumb_json
			)
		);
	}
}
