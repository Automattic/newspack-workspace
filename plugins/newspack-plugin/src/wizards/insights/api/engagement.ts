/**
 * Engagement API client (NPPD-1649).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the Tab 2 endpoint:
 * `GET /newspack-insights/v1/engagement`. Same response shape and query
 * params as the Audience endpoint.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import type { InsightsWindow, InsightsQuery } from './audience';

export interface EngagementResponse {
	tab_error?: string;
	banner_text?: string;
	current?: InsightsWindow;
	previous?: InsightsWindow | null;
}

const ENDPOINT = '/newspack-insights/v1/engagement';

export const fetchEngagementData = async ( query: InsightsQuery ): Promise< EngagementResponse > => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return apiFetch< EngagementResponse >( {
		path: `${ ENDPOINT }?${ params.toString() }`,
		method: 'GET',
	} );
};
