/**
 * Shared avatar resolution for the subscribers demo.
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

const demoConfig = ( typeof window !== 'undefined' && window.newspackSubscribersDemo ) || {};

export const SHOW_AVATARS = demoConfig.showAvatars !== false;

// Photo avatars pinned to specific demo readers, overriding the gravatar the
// endpoint returns for their @example.com address. Keyed by email.
const AVATAR_OVERRIDES = {
	'sophia.park75@example.com': 'https://images.pexels.com/photos/28686637/pexels-photo-28686637.jpeg?auto=compress&cs=tinysrgb&w=300',
	'olivia.bennett18@example.com': 'https://images.pexels.com/photos/6094038/pexels-photo-6094038.jpeg?auto=compress&cs=tinysrgb&w=300',
	'nathan.brooks36@example.com': 'https://images.pexels.com/photos/30482803/pexels-photo-30482803.jpeg?auto=compress&cs=tinysrgb&w=300',
	'luis.gutierrez19@example.com': 'https://images.pexels.com/photos/1247591/pexels-photo-1247591.jpeg?auto=compress&cs=tinysrgb&w=300',
	'priya.nair28@example.com': 'https://images.pexels.com/photos/18443114/pexels-photo-18443114.jpeg?auto=compress&cs=tinysrgb&w=300',
	'grace.kim9@example.com': 'https://images.pexels.com/photos/33710714/pexels-photo-33710714.jpeg?auto=compress&cs=tinysrgb&w=300',
	'priya.patel@example.com': 'https://images.pexels.com/photos/16251528/pexels-photo-16251528.jpeg?auto=compress&cs=tinysrgb&w=300',
	'matthew.moore@example.com': 'https://images.pexels.com/photos/37163037/pexels-photo-37163037.jpeg?auto=compress&cs=tinysrgb&w=300',
	'jane.chen@example.com': 'https://images.pexels.com/photos/245584/pexels-photo-245584.jpeg?auto=compress&cs=tinysrgb&w=300',
	'aisha.khan@example.com': 'https://images.pexels.com/photos/34658892/pexels-photo-34658892.jpeg?auto=compress&cs=tinysrgb&w=300',
	'liam.brooks@example.com': 'https://images.pexels.com/photos/8861204/pexels-photo-8861204.png?auto=compress&cs=tinysrgb&w=300',
	'oscar@example.com': 'https://images.pexels.com/photos/29184089/pexels-photo-29184089.jpeg?auto=compress&cs=tinysrgb&w=300',
	'tariq.mansour41@example.com': 'https://images.pexels.com/photos/8834489/pexels-photo-8834489.jpeg?auto=compress&cs=tinysrgb&w=300',
};

/**
 * Resolve avatar URLs for a list of emails from the demo's REST endpoint.
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
		apiFetch( {
			path: '/newspack/v1/wizard/newspack-subscribers-demo/avatars',
			method: 'POST',
			data: size ? { emails: list, size } : { emails: list },
		} )
			.then( response => {
				if ( cancelled ) {
					return;
				}
				// Honor the endpoint's own avatars-off signal rather than relying
				// solely on the client SHOW_AVATARS flag (they can disagree if the
				// Discussion setting is toggled between page load and this fetch).
				if ( response?.show === false ) {
					setAvatars( {} );
					return;
				}
				if ( response?.avatars ) {
					setAvatars( { ...response.avatars, ...AVATAR_OVERRIDES } );
				}
			} )
			.catch( () => {} )
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
