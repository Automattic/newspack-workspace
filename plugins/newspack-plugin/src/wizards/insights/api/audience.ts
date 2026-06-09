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
import type { MetricPayload } from '../tabs/components/metrics';

/** A window of metrics keyed by metric name, plus a `window` meta entry. */
export type InsightsWindow = Record< string, MetricPayload >;

export interface AudienceResponse {
	tab_error?: string;
	banner_text?: string;
	current?: InsightsWindow;
	previous?: InsightsWindow | null;
}

export interface InsightsQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/audience';

export const fetchAudienceData = async ( query: InsightsQuery ): Promise< AudienceResponse > => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return apiFetch< AudienceResponse >( {
		path: `${ ENDPOINT }?${ params.toString() }`,
		method: 'GET',
	} );
};
