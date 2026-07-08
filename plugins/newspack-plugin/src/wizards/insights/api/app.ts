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
