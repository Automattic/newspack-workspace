import { useEffect, useState } from '@wordpress/element';

// `wp_check_locked_posts()` keys its request and response by `post-<id>`.
const KEY_PREFIX = 'post-';

// One tick carries every visible row, so cap the payload for the
// "All" per-page view. Rows past the cap simply show no lock.
const MAX_CHECKED = 100;

const EVENTS = [ 'heartbeat-send', 'heartbeat-tick' ];
const NAMESPACE = 'newspack-newsletters-locks';

/**
 * Report which of the given posts another user is currently editing.
 *
 * WordPress exposes post locks over Heartbeat only — no REST field. The
 * `wp-check-locked-posts` exchange (`wp_check_locked_posts()`, hooked in
 * `wp-admin/includes/admin-filters.php`) answers with a ready-to-render
 * payload per locked post, already gated on `edit_post` and translated.
 * Requires the `heartbeat` script on the page; without it the hook is inert.
 *
 * @param {Array<number|string>} ids Post ids currently listed.
 * @return {Object} Map of post id to `{ name, text, avatar_src, avatar_src_2x }`.
 */
export default function useLockedPosts( ids = [] ) {
	const [ locks, setLocks ] = useState( {} );
	// Identity-stable dep: the list refetches into a new array on every mutation.
	const idsKey = ids.slice( 0, MAX_CHECKED ).join( ',' );

	useEffect( () => {
		const heartbeat = window.wp?.heartbeat;
		const jQuery = window.jQuery;
		const checked = idsKey ? idsKey.split( ',' ).map( id => `${ KEY_PREFIX }${ id }` ) : [];

		if ( ! heartbeat || ! jQuery || ! checked.length ) {
			setLocks( {} );
			return undefined;
		}

		const onSend = ( event, data ) => {
			data[ 'wp-check-locked-posts' ] = checked;
		};

		const onTick = ( event, data ) => {
			// The key is omitted entirely once nothing is locked, so treat
			// its absence as "all clear" rather than "no news".
			const locked = data?.[ 'wp-check-locked-posts' ] || {};
			setLocks(
				Object.keys( locked ).reduce( ( map, key ) => {
					map[ key.slice( KEY_PREFIX.length ) ] = locked[ key ];
					return map;
				}, {} )
			);
		};

		jQuery( document ).on( `${ EVENTS[ 0 ] }.${ NAMESPACE }`, onSend ).on( `${ EVENTS[ 1 ] }.${ NAMESPACE }`, onTick );
		// Don't make the first paint wait out the 60s interval.
		heartbeat.connectNow();

		return () => {
			EVENTS.forEach( event => jQuery( document ).off( `${ event }.${ NAMESPACE }` ) );
		};
	}, [ idsKey ] );

	return locks;
}
