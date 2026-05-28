/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Module-level cache for provider and bidder data.
 *
 * Shared across all ad unit block instances so only one API request
 * per endpoint is made, regardless of how many blocks are on the page.
 */

let providersPromise = null;
let biddersPromise = null;

export function fetchProviders() {
	if ( ! providersPromise ) {
		providersPromise = apiFetch( { path: '/newspack-ads/v1/providers' } ).catch( error => {
			providersPromise = null;
			throw error;
		} );
	}
	return providersPromise;
}

export function fetchBidders() {
	if ( ! biddersPromise ) {
		biddersPromise = apiFetch( { path: '/newspack-ads/v1/bidders' } ).catch( error => {
			biddersPromise = null;
			throw error;
		} );
	}
	return biddersPromise;
}
