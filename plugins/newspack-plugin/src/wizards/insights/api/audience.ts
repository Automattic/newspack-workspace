/**
 * Audience API client (NPPD-1649).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the Tab 1 endpoint:
 * `GET /newspack-insights/v1/audience`. The response is either a tab-level
 * connection error (`{ tab_error, banner_text }`) or `{ current, previous }`
 * windows of keyed metric payloads. Backend dispatch (GA4 v1 / BQ v1.1) is
 * invisible here — the payload shape is identical either way.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { type CachedEnvelope } from '../state/insightsCache';
import type { MetricPayload } from '../tabs/components/metrics';

/** A window of metrics keyed by metric name, plus a `window` meta entry. */
export type InsightsWindow = Record< string, MetricPayload >;

/**
 * Registered readers (NPPD-1733). Sourced from the local `wp_users` table, not
 * GA4/BQ, so it sits at the top level of the response — present even when the
 * rest of the tab is a connect banner (`tab_error`). `total` is a window-
 * independent snapshot; `new` pairs the current window with its prior window so
 * the card can render a period delta.
 */
export interface RegisteredReadersNew {
	current: MetricPayload;
	previous: MetricPayload | null;
}

export interface RegisteredReaders {
	total: MetricPayload;
	new: RegisteredReadersNew;
}

export interface AudienceResponse {
	tab_error?: string;
	banner_text?: string;
	current?: InsightsWindow;
	previous?: InsightsWindow | null;
	registered_readers?: RegisteredReaders;
	/** Modeled 3-year value of a newsletter subscriber (NEWS-2603): expected reader revenue per newsletter signup. */
	newsletter_subscriber_value?: MetricPayload;
}

export interface InsightsQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/audience';

const queryString = ( query: InsightsQuery ): string => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return params.toString();
};

export const fetchAudienceData = async ( query: InsightsQuery ): Promise< CachedEnvelope< AudienceResponse > > =>
	apiFetch< CachedEnvelope< AudienceResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshAudienceData = async ( query: InsightsQuery ): Promise< CachedEnvelope< AudienceResponse > > =>
	apiFetch< CachedEnvelope< AudienceResponse > >( {
		path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
		method: 'POST',
	} );
