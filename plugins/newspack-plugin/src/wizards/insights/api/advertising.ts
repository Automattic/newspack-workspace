/**
 * Advertising API client (Tab 8, NPPD-1618).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the Tab 8 endpoint:
 * `GET /newspack-insights/v1/advertising`. Mirrors {@see api/audience}, but the
 * Advertising orchestrator (NPPD-1663) returns a richer envelope per window:
 * each of `current` / `previous` carries the visibility / readiness / data-lag
 * signals alongside the keyed `metrics` map (GA4's tabs put the metrics map at
 * the window root; GAM nests it under `metrics`).
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import type { MetricPayload } from '../tabs/components/metrics';
import { type CachedEnvelope } from '../state/insightsCache';

/** A window of metrics keyed by metric name. */
export type InsightsWindow = Record< string, MetricPayload >;

/** A single "finish connecting" reason from the orchestrator. */
export interface ReadinessIssue {
	code: string;
	message: string;
	remediation_url: string;
}

/**
 * One window's full Advertising envelope (the orchestrator's `get_all()` shape).
 */
export interface AdvertisingWindow {
	window?: { start: string; end: string };
	is_tab_visible: boolean;
	is_report_ready: boolean;
	/** Newspack Network member (NPPD-1671): gates the per-site breakdown (`metrics.top_sites`). */
	is_network_member?: boolean;
	readiness_issues: ReadinessIssue[];
	data_as_of?: string;
	has_estimated_data?: boolean;
	estimated_window_start_date?: string | null;
	metrics: InsightsWindow;
	/** No cached payload yet — a background GAM refresh was scheduled. */
	is_loading?: boolean;
	/** Serving a stale payload while a background refresh runs. */
	is_stale?: boolean;
	/**
	 * Derived empty-state signal (NPPD-1697): true when the resolved report saw
	 * any ad activity (impressions or revenue). Present (boolean) only once the
	 * report resolves and both volume metrics are computable — ABSENT while
	 * `is_loading`, or when a metric errored. The ReachRevenueSection reads
	 * `=== false` to fire its `no_opportunity` collapse, so `undefined` (loading /
	 * error) falls through and never collapses mid-load. Matches the optional
	 * `is_loading?` / `is_stale?` convention.
	 */
	has_window_activity?: boolean;
}

export interface AdvertisingResponse {
	current?: AdvertisingWindow;
	previous?: AdvertisingWindow | null;
}

export interface InsightsQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/advertising';

const queryString = ( query: InsightsQuery ): string => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	// Forward the `_fixture_state` URL param so fixture mode's render variants
	// (populated / not_ready / zero / no_revenue / loading / no_viewability) are
	// reachable from the UI for smoke testing — matching the gates / prompts tabs.
	// A no-op in production: the server ignores it unless
	// NEWSPACK_INSIGHTS_FIXTURE_MODE is enabled.
	if ( typeof window !== 'undefined' ) {
		const fixtureState = new URLSearchParams( window.location.search ).get( '_fixture_state' );
		if ( fixtureState ) {
			params.set( '_fixture_state', fixtureState );
		}
	}
	return params.toString();
};

export const fetchAdvertisingData = async ( query: InsightsQuery ): Promise< CachedEnvelope< AdvertisingResponse > > =>
	apiFetch< CachedEnvelope< AdvertisingResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshAdvertisingData = async ( query: InsightsQuery ): Promise< CachedEnvelope< AdvertisingResponse > > =>
	apiFetch< CachedEnvelope< AdvertisingResponse > >( {
		path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
		method: 'POST',
	} );
