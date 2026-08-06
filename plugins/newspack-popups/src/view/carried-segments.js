/**
 * Ingest segment IDs carried in from newsletter links (`np_segments`).
 *
 * The reader's ESP-synced segment snapshot arrives as a comma-separated ID
 * list, substituted into first-party links by the ESP at send time. Those
 * segments count as matched for the rest of the browsing session, on top of
 * anything matched locally — a click from a newsletter gets the reader the
 * right segment treatment without signing in.
 *
 * Guardrails: an ID is accepted only when it is numeric and among the
 * segments shipped to the page, so raw merge-tag leftovers (an ESP that never
 * substituted the tag) and unknown or malformed values are ignored. The
 * carried set lives in session-scoped state (see utils/session-store.js) — it
 * is never written to the reader profile and never grants content access.
 */

import { CARRIED_SEGMENTS_KEY } from './utils/segments';
import { readSessionValue, writeSessionValue } from './utils/session-store';

export const CARRIED_PARAM = 'np_segments';

/**
 * Read `np_segments` from the landing URL and remember the valid IDs for the
 * rest of the session, merged with any carried earlier.
 *
 * Pushed onto `window.newspackRAS`; receives the Reader Activation library,
 * whose store keeps the session-scoped state. Contained like every other
 * callback in the queue: the drain has no error isolation of its own.
 *
 * @param {Object} ras Reader Activation library object.
 */
export const ingestCarriedSegments = ras => {
	try {
		const raw = new URLSearchParams( window.location.search ).get( CARRIED_PARAM );
		if ( ! raw || ! ras?.store ) {
			return;
		}
		const segments = window.newspack_popups_view?.segments || {};
		const ids = raw
			.split( ',' )
			.map( id => id.trim() )
			.filter( ( id, index, list ) => /^\d+$/.test( id ) && segments[ id ] && list.indexOf( id ) === index );
		if ( ! ids.length ) {
			return;
		}
		const { sid, value } = readSessionValue( ras, CARRIED_SEGMENTS_KEY );
		const existing = Array.isArray( value ) ? value : [];
		const merged = existing.concat( ids.filter( id => ! existing.includes( id ) ) );
		writeSessionValue( ras, CARRIED_SEGMENTS_KEY, sid, merged );
	} catch ( e ) {
		// Ingestion is best-effort; never unwind into the RAS queue drain.
	}
};
