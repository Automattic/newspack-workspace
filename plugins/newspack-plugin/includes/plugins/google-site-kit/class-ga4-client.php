<?php
/**
 * Shared access to authenticated GA4 API clients.
 *
 * Extracted from the custom-dimension provisioner so read-side consumers
 * (segment reach) reuse the same auth routing: prefer Newspack's own Google
 * OAuth, fall back to Site Kit's authenticated client.
 *
 * @package Newspack
 */

namespace Newspack;

use Google\Site_Kit\Context;

defined( 'ABSPATH' ) || exit;

/**
 * GA4 client routing.
 */
final class GA4_Client {

	const LOGGER_HEADER = 'NEWSPACK-GA4-CLIENT';

	/**
	 * Read the connected GA4 property ID from Site Kit's stored settings.
	 *
	 * @return string|false
	 */
	public static function get_property_id() {
		$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
		if ( empty( $settings['propertyID'] ) ) {
			return false;
		}
		return (string) $settings['propertyID'];
	}

	/**
	 * Run a callable with an authenticated GA4 API client.
	 *
	 * Tries Newspack's own Google OAuth first. Its tokens hit the proxy's GCP
	 * project, which has the Analytics APIs enabled. If Newspack OAuth is not
	 * configured or the callback throws/returns a WP_Error, falls back to Site
	 * Kit's client (which stores tokens keyed on user ID).
	 *
	 * Switches the current user to a capable one if none is set (e.g. in
	 * WP-Cron) so permission checks in `Google_OAuth::get_oauth2_credentials()`
	 * and Site Kit's `User_Options` can resolve credentials. Restores the
	 * previous user before returning so we don't leak an unexpected identity
	 * into subsequent operations in the same process.
	 *
	 * Writes require the `analytics.edit` scope on the Newspack OAuth token,
	 * which many publishers have not granted — callers doing writes keep the
	 * default `require_edit_scope => true` so a scope-less token is skipped
	 * rather than failing with a 403. Reads are covered by the base `analytics`
	 * scope the token always carries, so read callers pass `false`.
	 *
	 * The callback is invoked as `$callback( $client, $source )` where
	 * `$source` is either 'newspack' or 'sitekit'.
	 *
	 * If every route fails, the returned WP_Error names each route that was
	 * tried and why it failed, so a 403 on writes (the common "publisher never
	 * granted analytics.edit to Site Kit" case) is self-explanatory rather than
	 * buried in the log.
	 *
	 * @param callable $callback Called with `( $client, string $source )`.
	 * @param array    $args     Options. `require_edit_scope` (bool, default true).
	 * @return mixed|\WP_Error The callback's return value, or WP_Error.
	 */
	public static function with_admin_client( callable $callback, array $args = [] ) {
		$require_edit_scope = $args['require_edit_scope'] ?? true;
		$previous_user_id   = get_current_user_id();
		$switched_user      = false;

		if ( ! $previous_user_id ) {
			$settings = get_option( 'googlesitekit_analytics-4_settings', [] );
			$owner_id = isset( $settings['ownerID'] ) ? (int) $settings['ownerID'] : 0;
			if ( $owner_id <= 0 ) {
				return new \WP_Error( 'newspack_ga4_client', 'No user context available to authenticate GA4 API calls.' );
			}
			wp_set_current_user( $owner_id );
			$switched_user = true;
		}

		// Route name => why it was skipped or failed, used to compose the error
		// if nothing works.
		$attempts = [];

		try {
			// Prefer Newspack's own OAuth. Returns null if not configured or no
			// credentials are saved; for writes, skip it outright if its token
			// predates the analytics.edit scope, since writes would just 403.
			$np_client = Google_OAuth_GA4_Client::build();
			if ( ! $np_client ) {
				$attempts['Newspack OAuth'] = 'not configured';
				Logger::log( 'Newspack OAuth not available; trying Site Kit.', self::LOGGER_HEADER );
			} elseif ( $require_edit_scope && ! Google_OAuth_GA4_Client::has_edit_scope() ) {
				$attempts['Newspack OAuth'] = 'stored token lacks the analytics.edit scope (reconnect Google in the Newspack settings to grant it)';
				Logger::log( 'Newspack OAuth token lacks analytics.edit; trying Site Kit.', self::LOGGER_HEADER );
			} else {
				try {
					$result = $callback( $np_client, 'newspack' );
					if ( ! is_wp_error( $result ) ) {
						return $result;
					}
					$attempts['Newspack OAuth'] = $result->get_error_message();
				} catch ( \Throwable $e ) {
					$attempts['Newspack OAuth'] = $e->getMessage();
				}
				Logger::log( 'Newspack OAuth path failed (' . $attempts['Newspack OAuth'] . '); trying Site Kit.', self::LOGGER_HEADER );
			}

			// Fall back to Site Kit.
			if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) || ! class_exists( __NAMESPACE__ . '\\GoogleSiteKitAnalytics' ) ) {
				$attempts['Site Kit'] = 'not available';
				return new \WP_Error( 'newspack_ga4_client', self::describe_auth_failure( $attempts ) );
			}
			try {
				$module = new GoogleSiteKitAnalytics( new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE ) );
				$result = $callback( $module, 'sitekit' );
				if ( ! is_wp_error( $result ) ) {
					return $result;
				}
				$attempts['Site Kit'] = $result->get_error_message();
			} catch ( \Throwable $e ) {
				$attempts['Site Kit'] = $e->getMessage();
			}
			return new \WP_Error( 'newspack_ga4_client', self::describe_auth_failure( $attempts ) );
		} finally {
			if ( $switched_user ) {
				wp_set_current_user( $previous_user_id );
			}
		}
	}

	/**
	 * Compose a human-readable failure message from a map of auth route =>
	 * failure reason.
	 *
	 * @param array<string,string> $attempts Route name => reason.
	 * @return string
	 */
	private static function describe_auth_failure( array $attempts ) {
		$parts = [];
		foreach ( $attempts as $route => $reason ) {
			$parts[] = "$route – $reason";
		}
		return 'Could not reach the GA4 API. Tried: ' . implode( '; ', $parts ) . '.';
	}
}
