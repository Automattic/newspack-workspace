<?php
/**
 * Newspack Insights — App (mobile app) Metric orchestrator (Tab 10, NPPD-1882).
 *
 * The App tab reports mobile-app analytics for Pugpig ("Bolt") app publishers,
 * live from the GA4 Data API against a **publisher-selected** app property (not
 * Site Kit's web property). App data never lands in BigQuery, so this tab is
 * GA4-only.
 *
 * PR0 scope (this file): the connection + property-selection layer — detect the
 * Newspack Google connection, enumerate the GA4 properties the connected identity
 * can see (across account boundaries, via `accountSummaries.list`), and persist
 * the publisher's chosen app property. The metric orchestration (Reach,
 * Engagement, Retention, …) lands in later PRs.
 *
 * Auth reuses Newspack's own Google OAuth via {@see \Newspack\Insights\GA4\Client}
 * — proven live for both same-account and separate-account (Firebase) app
 * properties.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use Newspack\Insights\GA4\Client;

defined( 'ABSPATH' ) || exit;

/**
 * App (Tab 10) metric orchestrator.
 */
final class App_Metric {

	/**
	 * Option storing the publisher-selected GA4 app property ID. Absent/empty
	 * means "not yet chosen" — the tab shows the property picker. An empty POST
	 * deletes the option (revert to picker) rather than storing an empty string,
	 * so a cleared selection re-enters the default path.
	 */
	const OPTION_PROPERTY_ID = 'newspack_insights_app_property_id';

	/**
	 * Whether this site is a Pugpig app publisher, read at runtime from the
	 * Newspack Manager companion plugin (same PHP process on managed sites).
	 * Guarded with class_exists so non-managed sites degrade cleanly.
	 *
	 * @return bool
	 */
	public static function is_app_publisher(): bool {
		return class_exists( '\Newspack_Manager\Pugpig\Pugpig' )
			&& \Newspack_Manager\Pugpig\Pugpig::is_enabled();
	}

	/**
	 * Whether a usable Newspack Google OAuth credential exists. Authoritative
	 * (resolves/refreshes the token) — not the misleading is_oauth_configured().
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return Client::has_valid_credentials();
	}

	/**
	 * The saved app property ID, or '' when none is chosen.
	 *
	 * @return string
	 */
	public static function get_selected_property_id(): string {
		$value = get_option( self::OPTION_PROPERTY_ID, '' );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Persist (or clear) the selected app property ID. An empty value deletes the
	 * option so the tab reverts to the picker rather than storing a poisoned ''.
	 *
	 * @param string $property_id Numeric GA4 property ID, or '' to clear.
	 * @return void
	 */
	public static function set_selected_property_id( string $property_id ): void {
		$property_id = trim( $property_id );
		if ( '' === $property_id ) {
			delete_option( self::OPTION_PROPERTY_ID );
			return;
		}
		update_option( self::OPTION_PROPERTY_ID, $property_id );
	}

	/**
	 * Flattened list of GA4 properties the connected identity can see, across all
	 * accounts, for the picker. Each row: account/property IDs + display names.
	 * The account name matters because the app property often lives in a separate
	 * (e.g. Firebase-default) account from the web property.
	 *
	 * @return array<int,array{account_id:string,account_name:string,property_id:string,property_name:string}>|\WP_Error
	 */
	public static function list_available_properties() {
		$summaries = Client::account_summaries();
		if ( is_wp_error( $summaries ) ) {
			return $summaries;
		}
		return self::flatten_property_summaries( $summaries );
	}

	/**
	 * Flatten GA Admin API `accountSummary` objects into pickable property rows.
	 * Pure (no network) so it's unit-testable against captured summary shapes.
	 *
	 * @param array $summaries List of GA Admin API `accountSummary` objects.
	 * @return array<int,array{account_id:string,account_name:string,property_id:string,property_name:string}>
	 */
	public static function flatten_property_summaries( array $summaries ): array {
		$properties = [];
		foreach ( $summaries as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$account_name = isset( $account['displayName'] ) ? (string) $account['displayName'] : '';
			// Resource names look like "accounts/123"; keep the bare numeric ID.
			$account_id = isset( $account['account'] ) ? self::strip_resource_prefix( (string) $account['account'] ) : '';
			foreach ( $account['propertySummaries'] ?? [] as $property ) {
				$property_id = isset( $property['property'] ) ? self::strip_resource_prefix( (string) $property['property'] ) : '';
				if ( '' === $property_id ) {
					continue;
				}
				$properties[] = [
					'account_id'    => $account_id,
					'account_name'  => $account_name,
					'property_id'   => $property_id,
					'property_name' => isset( $property['displayName'] ) ? (string) $property['displayName'] : $property_id,
				];
			}
		}
		return $properties;
	}

	/**
	 * The App tab's config payload — drives the frontend's connect → select →
	 * render state machine. `properties` is only populated when connected (the
	 * enumeration call needs a token). On an enumeration failure `properties` is
	 * an empty list and `properties_error` carries the reason, so the picker can
	 * show an error state without blanking the tab.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		$connected           = self::is_connected();
		$properties          = [];
		$properties_error    = null;
		$selected_is_visible = false;
		$selected            = self::get_selected_property_id();

		if ( $connected ) {
			$listed = self::list_available_properties();
			if ( is_wp_error( $listed ) ) {
				$properties_error = $listed->get_error_message();
			} else {
				$properties = $listed;
				foreach ( $properties as $property ) {
					if ( $property['property_id'] === $selected ) {
						$selected_is_visible = true;
						break;
					}
				}
			}
		}

		return [
			'is_app_publisher'    => self::is_app_publisher(),
			'connected'           => $connected,
			'selected_property'   => '' !== $selected ? $selected : null,
			'selected_is_visible' => $selected_is_visible,
			'properties'          => $properties,
			'properties_error'    => $properties_error,
			'settings_url'        => admin_url( 'admin.php?page=newspack-settings' ),
		];
	}

	/**
	 * Strip a GA Admin API resource prefix ("accounts/", "properties/") to the
	 * bare trailing ID.
	 *
	 * @param string $resource Resource name, e.g. "properties/459878437".
	 * @return string
	 */
	private static function strip_resource_prefix( string $resource ): string {
		$pos = strrpos( $resource, '/' );
		return false === $pos ? $resource : substr( $resource, $pos + 1 );
	}
}
