/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
// `AdProvider`/`AdBidder` mirror the same `/newspack-ads/v1/providers` and
// `/newspack-ads/v1/bidders` REST responses that `PlacementControl` (this
// block's own placement-picking UI) consumes -- reuse its types rather than
// declaring a second, incompatible set for the same shapes.
import type { AdProvider, AdBidder } from '../../placements/types';

export type Bidders = Record< string, AdBidder >;

/**
 * Module-level cache for provider and bidder data.
 *
 * Shared across all ad unit block instances so only one API request
 * per endpoint is made, regardless of how many blocks are on the page.
 */

let providersPromise: Promise< AdProvider[] > | null = null;
let biddersPromise: Promise< Bidders > | null = null;

export function fetchProviders(): Promise< AdProvider[] > {
	if ( ! providersPromise ) {
		providersPromise = apiFetch< AdProvider[] >( { path: '/newspack-ads/v1/providers' } ).catch( error => {
			providersPromise = null;
			throw error;
		} );
	}
	return providersPromise;
}

export function fetchBidders(): Promise< Bidders > {
	if ( ! biddersPromise ) {
		biddersPromise = apiFetch< Bidders >( { path: '/newspack-ads/v1/bidders' } ).catch( error => {
			biddersPromise = null;
			throw error;
		} );
	}
	return biddersPromise;
}
