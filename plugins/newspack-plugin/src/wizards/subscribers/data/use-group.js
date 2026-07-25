/**
 * One group subscription, plus the writes the group detail screen performs.
 *
 * WRITE CONVENTIONS FOR THE WIZARD (NPPD-1753 §3.1) — this is the wizard's first
 * write path, so the three choices it makes are the ones everything after it
 * follows:
 *
 * 1. NONCE. Nothing here handles one. `@wordpress/api-fetch` is served by WP's
 *    `wp-api-fetch` script, which registers a nonce middleware from
 *    `wpApiSettings.nonce` at load and attaches `X-WP-Nonce` to every request it
 *    makes. That is a header, not a path rule, so it covers the
 *    `newspack-group-subscription/v1` routes below exactly as it covers the
 *    wizard's own `newspack/v1` ones. Never hand-roll a nonce into a body.
 *
 * 2. ERRORS. A `WP_Error` returned from a REST callback is serialized by WP into
 *    `{ code, message, data: { status } }`, and apiFetch rejects with that object.
 *    So `Group_Subscription::add_manager()` refusing to demote an owner, or the
 *    seat-limit route refusing a limit below the committed seats, arrives here as
 *    a rejection whose `.message` is already a sentence written for a publisher.
 *    Surface it verbatim rather than substituting a generic failure string — the
 *    server knows why it said no and the client does not.
 *
 * 3. AWAITED, NOT OPTIMISTIC. Every mutation resolves and then refetches the
 *    group. The server is not a passive store here: it clamps the seat limit to a
 *    floor of 2, recomputes roles and capacity, and can partially apply a
 *    multi-member add. Rendering the request rather than the response would show
 *    the publisher a group that does not exist. The cost is one round-trip on an
 *    admin screen with a handful of rows, which is the right trade.
 */

/**
 * WordPress dependencies.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const WIZARD_PATH = '/newspack/v1/wizard/newspack-subscribers/groups';
const GROUP_API = '/newspack-group-subscription/v1';

/**
 * Fetch one group subscription, hydrated for the detail screen.
 *
 * A failed read is reported as `error` rather than collapsing into "no such
 * group", so the screen can tell a missing group apart from an unreadable one.
 *
 * @param {number|string} id The group subscription ID.
 * @return {{ group: Object|null, loading: boolean, error: string, reload: Function }} The group plus loading/error state.
 */
export function useGroup( id ) {
	const [ group, setGroup ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ attempt, setAttempt ] = useState( 0 );

	const reload = useCallback( () => setAttempt( n => n + 1 ), [] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		apiFetch( { path: `${ WIZARD_PATH }/${ id }` } )
			.then( response => {
				if ( ! cancelled ) {
					setGroup( response || null );
					setError( '' );
				}
			} )
			.catch( e => {
				if ( ! cancelled ) {
					setGroup( null );
					setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
				}
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

	return { group, loading, error, reload };
}

/**
 * The group-detail write calls.
 *
 * Every one of these targets the `newspack-group-subscription/v1` API that already
 * backs the reader-facing My Account group page, so the member, invite and
 * invite-link rules live in exactly one place and cannot drift between the two
 * surfaces. `/manager` and `/seat-limit` are the two additions: they have no My
 * Account equivalent, and they sit on the same API for the same reason.
 *
 * @param {number|string} id The group subscription ID.
 * @return {Object} The write calls, each returning a promise.
 */
export function useGroupActions( id ) {
	const subscriptionId = Number( id );

	const post = useCallback(
		( route, data, method = 'POST' ) =>
			apiFetch( { path: `${ GROUP_API }${ route }`, method, data: { subscription_id: subscriptionId, ...data } } ),
		[ subscriptionId ]
	);

	return {
		addMembers: useCallback( userIds => post( '/members', { members_to_add: userIds } ), [ post ] ),
		removeMembers: useCallback( userIds => post( '/members', { members_to_remove: userIds } ), [ post ] ),
		invite: useCallback( email => post( '/invite', { email } ), [ post ] ),
		cancelInvite: useCallback( email => post( '/invite', { email }, 'DELETE' ), [ post ] ),
		generateInviteLink: useCallback( () => post( '/invite-link', {} ), [ post ] ),
		disableInviteLink: useCallback( () => post( '/invite-link', {}, 'DELETE' ), [ post ] ),
		setManagerRole: useCallback( ( userId, role ) => post( '/manager', { user_id: userId, role } ), [ post ] ),
		setSeatLimit: useCallback( limit => post( '/seat-limit', { limit } ), [ post ] ),
		/**
		 * Resolve an email address to an existing reader account.
		 *
		 * `/search-users` is the same lookup the My Account member picker uses. It
		 * returns `{ id, text }` where text is `email (#id)`, and it excludes people
		 * already in the group — so a null result means "not a reader we can add
		 * directly", which is precisely when the caller should invite instead.
		 *
		 * @param {string} email The address to resolve.
		 * @return {Promise<number|null>} The reader's user ID, or null.
		 */
		resolveReaderId: useCallback(
			async email => {
				const matches = await post( '/search-users', { search: email } );
				const needle = `${ email.toLowerCase() } (#`;
				const match = ( matches || [] ).find( entry =>
					String( entry.text || '' )
						.toLowerCase()
						.startsWith( needle )
				);
				return match ? Number( match.id ) : null;
			},
			[ post ]
		),
	};
}
