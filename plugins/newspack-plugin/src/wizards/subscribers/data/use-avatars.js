/**
 * Shared avatar resolution for the Subscribers wizard.
 *
 * SHOW_AVATARS mirrors the publisher's "Show avatars" setting (Settings →
 * Discussion), localized onto window by the wizard PHP so the column layout can
 * be decided synchronously (no flash). useAvatars fetches the /avatars REST
 * endpoint once for a set of emails and returns them keyed by email; callers
 * map that onto their own keys (subscriber id, group id, single profile).
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const config = ( typeof window !== 'undefined' && window.newspackSubscribers ) || {};

export const SHOW_AVATARS = config.showAvatars !== false;

// The endpoint caps each request at this many emails and silently truncates the
// overflow, so the batch size has to match it exactly — the wizard localizes its
// own AVATAR_BATCH_CAP so there is one authority rather than two constants that
// can drift apart. Read at call time, not at import, so the value doesn't depend
// on this module loading after the inline config script. The fallback only
// applies if the config never loaded at all.
const avatarBatchSize = () => Number( window?.newspackSubscribers?.avatarBatchCap ) || 200;

/**
 * Resolve avatar URLs for a list of emails from the wizard's REST endpoint.
 *
 * @param {string[]} emails         Emails to resolve (falsy entries are ignored).
 * @param {Object}   [options]
 * @param {number}   [options.size] Source size in px (defaults to the endpoint default).
 * @return {{ avatars: Object, loading: boolean }} Map of email → URL, plus loading state.
 */
export function useAvatars( emails, { size } = {} ) {
	const [ avatars, setAvatars ] = useState( {} );
	const [ loading, setLoading ] = useState( SHOW_AVATARS );
	// Join into a stable key so the effect re-runs only when the set of emails
	// actually changes (callers pass a freshly built array each render).
	const key = ( emails || [] ).filter( Boolean ).join( ',' );
	useEffect( () => {
		if ( ! SHOW_AVATARS ) {
			return undefined;
		}
		const list = key ? key.split( ',' ) : [];
		if ( ! list.length ) {
			setAvatars( {} );
			setLoading( false );
			return undefined;
		}
		let cancelled = false;
		// Reset so a previous result doesn't linger across hops, and keep the
		// spinner up until this set resolves (no flash-in).
		setLoading( true );
		setAvatars( {} );
		// Batch to the endpoint's per-request cap and merge, so a set larger than
		// one batch still resolves fully instead of dropping the overflow.
		const batchSize = avatarBatchSize();
		const batches = [];
		for ( let i = 0; i < list.length; i += batchSize ) {
			batches.push( list.slice( i, i + batchSize ) );
		}
		Promise.all(
			batches.map( batch =>
				apiFetch( {
					path: '/newspack/v1/wizard/newspack-subscribers/avatars',
					method: 'POST',
					data: size ? { emails: batch, size } : { emails: batch },
				} ).catch( () => null )
			)
		)
			.then( responses => {
				if ( cancelled ) {
					return;
				}
				// Honor the endpoint's own avatars-off signal rather than relying
				// solely on the client SHOW_AVATARS flag (they can disagree if the
				// Discussion setting is toggled between page load and this fetch).
				if ( responses.some( response => response?.show === false ) ) {
					setAvatars( {} );
					return;
				}
				const merged = {};
				responses.forEach( response => {
					if ( response?.avatars ) {
						Object.assign( merged, response.avatars );
					}
				} );
				setAvatars( merged );
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ key, size ] );
	return { avatars, loading };
}
