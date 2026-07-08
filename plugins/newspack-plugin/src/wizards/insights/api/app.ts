/**
 * App API client (NPPD-1882).
 *
 * Wraps `@wordpress/api-fetch` for the App tab's config surface:
 *   GET  /newspack-insights/v1/app/config  → connect → select → render state
 *   POST /newspack-insights/v1/app/config  → persist the chosen app property id
 *
 * The windowed metrics endpoint lands with the metric orchestration in a later PR.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import type { MetricPayload } from '../tabs/components/metrics';

/** One selectable GA4 property (spans accounts — app data often lives in a separate account). */
export interface AppProperty {
	account_id: string;
	account_name: string;
	property_id: string;
	property_name: string;
}

/** App tab config/state, driving the connect → select → render flow. */
export interface AppConfig {
	/** Whether this is a Pugpig app publisher (the tab is gated on this server-side too). */
	is_app_publisher: boolean;
	/** Whether a usable Newspack Google OAuth credential exists. */
	connected: boolean;
	/** The persisted app property id, or null when none is chosen. */
	selected_property: string | null;
	/** Whether the persisted property is present in the enumerated list. */
	selected_is_visible: boolean;
	/** Properties the connected identity can see, across accounts (empty until connected). */
	properties: AppProperty[];
	/** Enumeration error message, if `accountSummaries.list` failed. */
	properties_error: string | null;
	/** Absolute URL to Newspack → Settings → Connections (where Google is connected). */
	settings_url: string;
}

const CONFIG_ENDPOINT = '/newspack-insights/v1/app/config';

export const fetchAppConfig = async (): Promise< AppConfig > => apiFetch< AppConfig >( { path: CONFIG_ENDPOINT, method: 'GET' } );

/**
 * Persist (or clear, with an empty string) the selected app property. Returns the
 * refreshed config so the caller re-renders in one round-trip.
 *
 * @param propertyId Numeric GA4 property id, or '' to clear.
 */
export const saveAppProperty = async ( propertyId: string ): Promise< AppConfig > =>
	apiFetch< AppConfig >( { path: CONFIG_ENDPOINT, method: 'POST', data: { property_id: propertyId } } );

/** Windowed app metric payloads, keyed by metric name (`tab_error` when unavailable). */
export interface AppMetrics {
	tab_error?: string;
	// Reach.
	active_users?: MetricPayload;
	new_users?: MetricPayload;
	sessions?: MetricPayload;
	platform?: MetricPayload;
	app_version?: MetricPayload;
	// Engagement.
	avg_engagement_time?: MetricPayload;
	engagement_rate?: MetricPayload;
	engaged_sessions?: MetricPayload;
	screens_per_session?: MetricPayload;
	screen_views?: MetricPayload;
	retention?: MetricPayload;
	// Notifications.
	notification_open_rate?: MetricPayload;
	notifications_received?: MetricPayload;
	notification_opt_changes?: MetricPayload;
	// Editions.
	downloads_started?: MetricPayload;
	downloads_completed?: MetricPayload;
	download_completion_rate?: MetricPayload;
	edition_opens?: MetricPayload;
	// Tier-2: KG custom-dimension breakdowns. Carry `not_configured` until the
	// dimensions are registered on the property (auto-registration is Tier-2b).
	top_sections?: MetricPayload;
	top_authors?: MetricPayload;
	subscriber_mix?: MetricPayload;
	content_cost?: MetricPayload;
}

export interface AppMetricsResponse {
	current: AppMetrics;
}

const METRICS_ENDPOINT = '/newspack-insights/v1/app';

export const fetchAppMetrics = async ( start: string, end: string ): Promise< AppMetricsResponse > =>
	apiFetch< AppMetricsResponse >( {
		path: `${ METRICS_ENDPOINT }?start=${ encodeURIComponent( start ) }&end=${ encodeURIComponent( end ) }`,
		method: 'GET',
	} );
