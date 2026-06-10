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
import { CooldownError, isCooldown, type CachedEnvelope } from '../state/insightsCache';
import type { InsightsWindow, InsightsQuery } from './audience';

export interface EngagementResponse {
	tab_error?: string;
	banner_text?: string;
	current?: InsightsWindow;
	previous?: InsightsWindow | null;
}

const ENDPOINT = '/newspack-insights/v1/engagement';

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

export const fetchEngagementData = async ( query: InsightsQuery ): Promise< CachedEnvelope< EngagementResponse > > =>
	apiFetch< CachedEnvelope< EngagementResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshEngagementData = async ( query: InsightsQuery ): Promise< CachedEnvelope< EngagementResponse > > => {
	try {
		return await apiFetch< CachedEnvelope< EngagementResponse > >( {
			path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
			method: 'POST',
		} );
	} catch ( e: unknown ) {
		if ( isCooldown( e ) ) {
			const data = ( e as { data: { cooldown_until: string } } ).data;
			throw new CooldownError( data.cooldown_until );
		}
		throw e;
	}
};
