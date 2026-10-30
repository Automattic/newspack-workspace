/**
 * Single-subscriber read, backing the person profile (L1).
 *
 * The collection hook (use-subscribers) returns just enough per reader to draw a
 * table row. This one reads a single reader in full: every subscription they
 * hold, individual and group alike, each with the billing detail its card
 * renders. Groups arrive as whole objects, not IDs — see the response-shape note
 * on Subscribers_Wizard::api_get_subscriber() — so the screen never has to fan
 * out a second request to draw a card.
 */

/**
 * WordPress dependencies.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const PATH = '/newspack/v1/wizard/newspack-subscribers/subscribers';

// A request that can never succeed on retry: the user is gone, or the id is not
// a number the `(?P<id>\d+)` route will ever match.
const NOT_FOUND_CODES = [ 'newspack_subscriber_not_found', 'rest_no_route' ];

/**
 * Fetch one subscriber's full profile.
 *
 * A failed read is reported as `error` rather than collapsing into an empty
 * profile, so the screen can tell "this person has no subscriptions" apart from
 * "we could not read them" and offer `reload` as a retry. A subscriber who does
 * not exist — a deleted user (`newspack_subscriber_not_found`), or a non-numeric
 * id the route regex never matches (`rest_no_route`) — surfaces with `notFound`
 * instead, so the screen states the dead end rather than offering a Retry that
 * can never succeed.
 *
 * @param {number|string} id The subscriber (user) ID.
 * @return {{ subscriber: ?Object, loading: boolean, error: string, notFound: boolean, reload: Function }} The profile.
 */
export function useSubscriber( id ) {
	const [ subscriber, setSubscriber ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notFound, setNotFound ] = useState( false );
	const [ attempt, setAttempt ] = useState( 0 );

	const reload = useCallback( () => setAttempt( n => n + 1 ), [] );

	useEffect( () => {
		let cancelled = false;
		// Drop the previous person immediately on an id change, so a slow fetch
		// never leaves the last profile on screen under the new id's header.
		setSubscriber( null );
		setError( '' );
		setNotFound( false );
		setLoading( true );
		apiFetch( { path: `${ PATH }/${ encodeURIComponent( id ) }` } )
			.then( response => {
				if ( cancelled ) {
					return;
				}
				setSubscriber( response || null );
				setError( '' );
				setNotFound( false );
			} )
			.catch( e => {
				if ( cancelled ) {
					return;
				}
				setSubscriber( null );
				setNotFound( NOT_FOUND_CODES.includes( e?.code ) );
				setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ id, attempt ] );

	return { subscriber, loading, error, notFound, reload };
}
