/**
 * Newsletter Ads API client (NPPD-1861).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the Newsletter Ads endpoint:
 * `GET /newspack-insights/v1/newsletter-ads`. Mirrors {@see api/advertising}:
 * the orchestrator returns the same rich per-window envelope — each of
 * `current` / `previous` carries the visibility / readiness / data-lag signals
 * alongside the keyed `metrics` map.
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

/** A single readiness reason from the orchestrator. */
export interface ReadinessIssue {
	code: string;
	message: string;
	remediation_url: string;
}

/** Row shape of the `top_ads` table payload. */
export interface TopAdRow {
	ad_id: string | number;
	title: string;
	advertiser: string;
	impressions: number;
	clicks: number;
	ctr: number | null;
	revenue: number | null;
}

/** Row shape of the `top_advertisers` table payload. */
export interface TopAdvertiserRow {
	advertiser: string;
	ads: number;
	impressions: number;
	clicks: number;
	ctr: number | null;
	revenue: number | null;
}

/** Row shape of the `by_newsletter` table payload. */
export interface ByNewsletterRow {
	newsletter_id: string | number;
	title: string;
	sent_date: string;
	ads: number;
	impressions: number;
	clicks: number;
	ctr: number | null;
}

/**
 * One window's full Newsletter Ads envelope (the orchestrator's `get_all()`
 * shape). Same wrapper as Advertising: visibility and readiness are distinct
 * signals; unlike Advertising, the lifetime counters (`lifetime_impressions` /
 * `lifetime_clicks` / `lifetime_ctr`) resolve even when `is_report_ready` is
 * false, so a not-ready tab still has an all-time story to tell.
 */
export interface NewsletterAdsWindow {
	window?: { start: string; end: string };
	is_tab_visible: boolean;
	is_report_ready: boolean;
	readiness_issues: ReadinessIssue[];
	data_as_of?: string;
	metrics: InsightsWindow;
	/** Always false for newsletter ads (no async report); kept for envelope parity. */
	is_loading?: boolean;
	/**
	 * Derived empty-state signal: `false` only on a resolved window with zero
	 * timeframe ad activity. The OverviewSection reads `=== false` so an absent
	 * value (loading / errored metric) never collapses the section.
	 */
	has_window_activity?: boolean;
}

export interface NewsletterAdsResponse {
	current?: NewsletterAdsWindow;
	previous?: NewsletterAdsWindow | null;
}

export interface InsightsQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/newsletter-ads';

const queryString = ( query: InsightsQuery ): string => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	// Forward the `_fixture_state` URL param so fixture mode's render variants are
	// reachable from the UI for smoke testing — matching the advertising tab. A
	// no-op in production: the server ignores it unless
	// NEWSPACK_INSIGHTS_FIXTURE_MODE is enabled.
	if ( typeof window !== 'undefined' ) {
		const fixtureState = new URLSearchParams( window.location.search ).get( '_fixture_state' );
		if ( fixtureState ) {
			params.set( '_fixture_state', fixtureState );
		}
	}
	return params.toString();
};

export const fetchNewsletterAdsData = async ( query: InsightsQuery ): Promise< CachedEnvelope< NewsletterAdsResponse > > =>
	apiFetch< CachedEnvelope< NewsletterAdsResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshNewsletterAdsData = async ( query: InsightsQuery ): Promise< CachedEnvelope< NewsletterAdsResponse > > =>
	apiFetch< CachedEnvelope< NewsletterAdsResponse > >( {
		path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
		method: 'POST',
	} );
