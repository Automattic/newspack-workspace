<?php
/**
 * PWA management.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;
use WP_Service_Worker_Scripts;

defined( 'ABSPATH' ) || exit;

/**
 * Manages settings for PWA.
 */
class PWA {

	/**
	 * Add hooks.
	 */
	public static function init() {
		// For backwards compatibility and because there's no reason not to, always and automatically enable offline browsing.
		add_filter( 'pre_option_offline_browsing', '__return_true' );

		add_filter( 'wp_service_worker_navigation_caching', [ __CLASS__, 'increase_network_timeout' ] );
		add_filter( 'wp_service_worker_error_messages', [ __CLASS__, 'error_messages' ] );

		add_action( 'wp_front_service_worker', [ __CLASS__, 'bypass_service_worker' ], 100 );
		add_action( 'wp_admin_service_worker', [ __CLASS__, 'bypass_service_worker' ], 100 );

		// Replace the SW caching header ('no-cache' by default), so it can be cached (for a day).
		add_action(
			'wp_front_service_worker',
			function () {
				header( 'Cache-Control: max-age=86400, must-revalidate' );
			}
		);

		// Add web app manifest filter.
		add_filter( 'web_app_manifest', [ __CLASS__, 'filter_web_app_manifest' ] );

		// Runs once all plugins are loaded, so the PWA plugin's presence is known, and before WordPress parses the request.
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_serve_orphaned_worker_cleanup' ], 0 );
		// The PWA plugin's wp-admin worker is served through admin-ajax.php.
		add_action( 'wp_ajax_wp_service_worker', [ __CLASS__, 'maybe_serve_orphaned_worker_cleanup' ] );
		add_action( 'wp_ajax_nopriv_wp_service_worker', [ __CLASS__, 'maybe_serve_orphaned_worker_cleanup' ] );
	}

	/**
	 * Script that removes a service worker registration.
	 *
	 * Browsers keep a registered service worker after the plugin that registered it is gone, and re-fetch its
	 * script on navigations. A response that is not a valid script (404, 429, redirect) leaves the registration
	 * in place, so every such browser keeps requesting the URL. A changed script installs, activates and
	 * unregisters itself, after which the browser stops asking. It does not claim or reload clients.
	 */
	const ORPHANED_WORKER_SCRIPT = "self.addEventListener( 'install', () => self.skipWaiting() );\nself.addEventListener( 'activate', ( event ) => event.waitUntil( self.registration.unregister() ) );\n";

	/**
	 * Whether the PWA plugin is loaded.
	 *
	 * @return bool
	 */
	public static function is_pwa_plugin_active() {
		return defined( 'PWA_VERSION' ) || function_exists( 'wp_service_workers' );
	}

	/**
	 * Whether a request is for one of the worker script URLs the PWA plugin serves.
	 *
	 * Front worker: /wp.serviceworker (also without rewrite rules via /index.php/wp.serviceworker),
	 * or /?wp_service_worker=1 on sites without pretty permalinks. Admin worker: admin-ajax.php?action=wp_service_worker.
	 *
	 * @param string $request_uri The request URI, with query string.
	 * @param string $home_path   Path component of the home URL, e.g. '' or '/blog'.
	 *
	 * @return bool
	 */
	public static function is_worker_request( $request_uri, $home_path = '' ) {
		$parts = wp_parse_url( $request_uri );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		wp_parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );

		$home_path = untrailingslashit( $home_path );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = trim( $path, '/' );

		if ( 'wp.serviceworker' === $path || 'index.php/wp.serviceworker' === $path ) {
			return true;
		}
		if ( ( '' === $path || 'index.php' === $path ) && ! empty( $query['wp_service_worker'] ) ) {
			return true;
		}
		return 'wp-admin/admin-ajax.php' === $path && isset( $query['action'] ) && 'wp_service_worker' === $query['action'];
	}

	/**
	 * Response that clears the registration of an orphaned PWA service worker.
	 *
	 * @param string $request_uri The request URI, with query string.
	 * @param bool   $pwa_active  Whether the PWA plugin is loaded.
	 * @param string $home_path   Path component of the home URL.
	 *
	 * @return array|null Headers and body, or null when the request must be left alone.
	 */
	public static function get_orphaned_worker_response( $request_uri, $pwa_active, $home_path = '' ) {
		if ( $pwa_active || ! self::is_worker_request( $request_uri, $home_path ) ) {
			return null;
		}
		return [
			'headers' => [
				'Content-Type'  => 'application/javascript; charset=utf-8',
				// Short enough that the edge picks up a later change, long enough to absorb the repeat checks.
				'Cache-Control' => 'public, max-age=3600',
				'X-Robots-Tag'  => 'noindex, follow',
			],
			'body'    => self::ORPHANED_WORKER_SCRIPT,
		];
	}

	/**
	 * Answer requests for the PWA plugin's worker script with a script that unregisters it, when the plugin is off.
	 *
	 * Without this, WordPress renders a full 404 page for every navigation of every browser that registered the
	 * worker while the plugin was on. Left in place indefinitely because a browser can return at any time.
	 * Return false from `newspack_pwa_serve_orphaned_worker_cleanup` to opt out.
	 */
	public static function maybe_serve_orphaned_worker_cleanup() {
		if ( self::is_pwa_plugin_active() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		/**
		 * Filters whether the self-unregistering worker script is served when the PWA plugin is not active.
		 *
		 * @param bool $serve Whether to serve it. Default true.
		 */
		if ( ! apply_filters( 'newspack_pwa_serve_orphaned_worker_cleanup', true ) ) {
			return;
		}
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$response  = self::get_orphaned_worker_response( wp_unslash( $_SERVER['REQUEST_URI'] ), false, $home_path ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed and compared.
		if ( ! $response ) {
			return;
		}
		status_header( 200 );
		foreach ( $response['headers'] as $name => $value ) {
			header( "$name: $value" );
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script.
		}
		exit;
	}

	/**
	 * Check whether everything is configured correctly.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function check_configured() {
		if ( ! is_ssl() ) {
			return new WP_Error( 'newspack_pwa_error', __( 'Site is not served over https. Progressive web app features will not work.', 'newspack' ) );
		}

		if ( ! self::get_site_icon() ) {
			return new WP_Error( 'newspack_pwa_error', __( 'No site icon specified. Visitors will not be able to install the site as an app.', 'newspack' ) );
		}

		return true;
	}

	/**
	 * Retrieve Site Icon.
	 *
	 * @return null|array
	 */
	public static function get_site_icon() {
		$site_icon = get_option( 'site_icon' );
		if ( ! $site_icon ) {
			return null;
		}
		$url = wp_get_attachment_image_src( $site_icon );
		return [
			'id'  => $site_icon,
			'url' => $url[0],
		];
	}

	/**
	 * Update Site Icon
	 *
	 * @param string $site_icon The site icon.
	 */
	public function update_site_icon( $site_icon ) {
		if ( ! empty( $site_icon['id'] ) ) {
			update_option( 'site_icon', intval( $site_icon['id'] ) );
		} else {
			delete_option( 'site_icon' );
		}
	}

	/**
	 * By default, PWA serves the offline-cached page if a request takes longer than 2 seconds.
	 * This is too short, especially for homepages with a lot of posts blocks.
	 * 44 seconds is right before the 45-second PHP process timeout.
	 *
	 * @param array $config PWA config.
	 * @return array Modified $config.
	 */
	public static function increase_network_timeout( $config ) {
		$config['network_timeout_seconds'] = 44;
		return $config;
	}

	/**
	 * Modify error messages.
	 *
	 * @param array $messages Error messages.
	 *
	 * @return array Modified error messages.
	 */
	public static function error_messages( $messages ) {
		if ( ! empty( $messages['serverOffline'] ) ) {
			$messages['serverOffline'] = __( 'There has been a problem connecting with the server. Please check your internet connection or try again later.', 'newspack' );
		}
		return $messages;
	}

	/**
	 * Temporary workaround to disable the offline post request handling script.
	 *
	 * @param WP_Service_Worker_Scripts $scripts The service worker scripts.
	 *
	 * @see - https://github.com/GoogleChromeLabs/pwa-wp/issues/1106
	 */
	public static function bypass_service_worker( WP_Service_Worker_Scripts $scripts ) {
		unset( $scripts->registered['wp-offline-post-request-handling'] );
	}

	/**
	 * Filter the web app manifest to include custom display mode.
	 *
	 * @param array $manifest The web app manifest array.
	 * @return array The modified manifest.
	 */
	public static function filter_web_app_manifest( $manifest ) {
		$display_mode = get_option( 'newspack_pwa_display_mode', '' );
		if ( ! empty( $display_mode ) ) {
			$manifest['display'] = $display_mode;
		}
		return $manifest;
	}
}
PWA::init();
