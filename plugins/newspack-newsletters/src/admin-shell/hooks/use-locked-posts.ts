import { createContext, useContext, useEffect, useRef, useState } from '@wordpress/element';

/** A post-lock payload as returned by `wp_check_locked_posts()`. */
interface PostLock {
	name?: string;
	text?: string;
	avatar_src?: string;
	avatar_src_2x?: string;
}

/** Map of post id to its lock payload. */
type LockMap = Record< string, PostLock >;

// `wp_check_locked_posts()` keys its request and response by `post-<id>`.
const KEY_PREFIX = 'post-';

// One tick carries every row the caller passes, so cap the payload for the
// "All" per-page view. Every other per-page size is already below this, so
// the request tracks the rendered rows. Rows past the cap show no lock.
export const MAX_CHECKED = 100;

const SEND_EVENT = 'heartbeat-send';
const TICK_EVENT = 'heartbeat-tick';
const NAMESPACE = 'newspack-newsletters-locks';

// Collapses a burst of list mutations (a settled search, rapid filter
// changes) into one forced tick. A change landing inside the window is
// deferred to its end rather than dropped.
const CONNECT_THROTTLE_MS = 2000;

// The fields the list renders. A tick repeating them is not a change, and
// treating it as one would replace the lock map on every beat.
const LOCK_FIELDS = [ 'text', 'avatar_src', 'avatar_src_2x' ] as const;

function isSameLocks( current: LockMap, next: LockMap ): boolean {
	const keys = Object.keys( current );
	if ( keys.length !== Object.keys( next ).length ) {
		return false;
	}
	return keys.every( key => next[ key ] && LOCK_FIELDS.every( field => current[ key ][ field ] === next[ key ][ field ] ) );
}

/**
 * Report which of the given posts another user is currently editing.
 *
 * WordPress exposes post locks over Heartbeat only — no REST field. The
 * `wp-check-locked-posts` exchange (`wp_check_locked_posts()`, hooked in
 * `wp-admin/includes/admin-filters.php`) answers with a ready-to-render
 * payload per locked post, already gated on `edit_post` and translated.
 * Requires the `heartbeat` script on the page; without it the hook is inert.
 *
 * Cadence is core's default (60s, 120s once the tab loses focus) rather than
 * the 10s core's own list tables opt into, and core suspends the exchange
 * after ten minutes without mouse or keyboard activity. A lock can therefore
 * linger on screen after it is released, until the reader next moves the
 * mouse. That is deliberate: the indicator informs a decision the reader is
 * about to make by hand, and the activity that precedes that decision is what
 * resumes the exchange, so paying six times the request rate to keep an idle
 * list warm is not worth it.
 *
 * @param ids Post ids currently listed.
 * @return Map of post id to its lock payload.
 */
export default function useLockedPosts( ids: Array< number | string > = [] ): LockMap {
	const [ locks, setLocks ] = useState< LockMap >( {} );
	const lastConnect = useRef( 0 );
	// Identity-stable dep: the list refetches into a new array on every mutation.
	const idsKey = ids.slice( 0, MAX_CHECKED ).join( ',' );

	useEffect( () => {
		const heartbeat = window.wp?.heartbeat;
		const jQuery = window.jQuery;
		const checked = idsKey ? idsKey.split( ',' ).map( id => `${ KEY_PREFIX }${ id }` ) : [];

		if ( ! heartbeat || ! jQuery || ! checked.length ) {
			setLocks( current => ( Object.keys( current ).length ? {} : current ) );
			return undefined;
		}

		const onSend = ( event: unknown, data: Record< string, unknown > ) => {
			data[ 'wp-check-locked-posts' ] = checked;
		};

		const onTick = ( event: unknown, data: Record< string, unknown > ) => {
			// The key is omitted entirely once nothing is locked, so treat
			// its absence as "all clear" rather than "no news".
			const locked = ( data?.[ 'wp-check-locked-posts' ] || {} ) as LockMap;
			const next = Object.keys( locked ).reduce< LockMap >( ( map, key ) => {
				map[ key.slice( KEY_PREFIX.length ) ] = locked[ key ];
				return map;
			}, {} );
			// A new object every beat would re-render the whole list, so only
			// replace the map when a lock actually appeared, changed or cleared.
			setLocks( current => ( isSameLocks( current, next ) ? current : next ) );
		};

		jQuery( document ).on( `${ SEND_EVENT }.${ NAMESPACE }`, onSend ).on( `${ TICK_EVENT }.${ NAMESPACE }`, onTick );

		// Don't make the first paint wait out the 60s interval. A burst of list
		// mutations collapses into one tick, but the set left on screen still
		// gets its own once the window closes: dropping the trailing connect
		// would leave those rows unchecked until the next ordinary beat.
		const connect = () => {
			lastConnect.current = Date.now();
			heartbeat.connectNow();
		};
		const wait = CONNECT_THROTTLE_MS - ( Date.now() - lastConnect.current );
		let deferred: ReturnType< typeof setTimeout > | undefined;
		if ( wait <= 0 ) {
			connect();
		} else {
			deferred = setTimeout( connect, wait );
		}

		return () => {
			clearTimeout( deferred );
			jQuery( document ).off( `${ SEND_EVENT }.${ NAMESPACE }`, onSend ).off( `${ TICK_EVENT }.${ NAMESPACE }`, onTick );
		};
	}, [ idsKey ] );

	return locks;
}

/**
 * Locks for the current list, so a row can read its own without the field
 * definitions closing over the map. Field definitions are the element type
 * DataViews renders each cell with, so rebuilding them on a lock change
 * would remount every cell in the table.
 */
export const LockedPostsContext = createContext< LockMap >( {} );

/**
 * Lock held on one post, if any.
 *
 * @param id Post id.
 * @return Lock payload, or undefined when unlocked.
 */
export function useLockedPost( id: number | string ): PostLock | undefined {
	return useContext( LockedPostsContext )[ id ];
}
