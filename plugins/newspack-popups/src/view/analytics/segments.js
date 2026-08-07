/* global gtag */

/**
 * Report the reader's matched segments to GA4, in two layers.
 *
 * `np_segment_matched` fires once per segment per browsing session, the first
 * time the reader satisfies its criteria — the segment's total reach. But
 * prompts follow only the reader's highest-priority match, so satisfying a
 * segment's criteria is not the same as seeing its prompts. `np_segment_won`
 * records that second layer: it fires once per segment per session, the first
 * time the segment is the reader's priority winner. Reach and prompt audience
 * are then separate one-filter reports on the same dimension, and a segment
 * whose matched count far exceeds its won count is shadowed by higher-priority
 * segments.
 *
 * The matched layer must report the full set, not just the winner: a probation
 * segment (active, assigned to no prompt) has to sit at the bottom of the
 * priority list — if it won the match it would suppress other segments'
 * prompts for every overlapping reader — so wherever it overlaps established
 * segments it rarely wins, and winner-only reporting would show ~zero for
 * exactly the segment being sized.
 *
 * Runs on every pageview where segmentation is active — including gated
 * articles and posts with prompts disabled, neither of which reaches the
 * prompt-display path. Session-scoping keeps the added event volume bounded by
 * segment count rather than traffic; GA4 user counts dedupe regardless.
 */

import { getMatchingSegmentIds, sendEvent } from '../utils';

export const EVENT_NAME = 'np_segment_matched';
export const WON_EVENT_NAME = 'np_segment_won';
export const SESSION_KEY = 'newspack-popups-reported-segments';
export const WON_SESSION_KEY = 'newspack-popups-reported-won-segments';
export const EMPTY_VALUE = 'none';

/**
 * Read the segment IDs already reported in this session under a given key.
 *
 * @param {string} key sessionStorage key.
 *
 * @return {string[]} Reported IDs, empty if none or unreadable.
 */
const readReported = key => {
	try {
		const stored = window.sessionStorage.getItem( key );
		return stored ? stored.split( ',' ) : [];
	} catch ( e ) {
		// sessionStorage unavailable (e.g. private mode). Treating this as
		// "nothing reported yet" degrades the reader to per-pageview dispatch,
		// which costs volume but loses no data.
		return [];
	}
};

/**
 * Remember the segment IDs reported so far under a given key, so the rest of
 * the session stays quiet about them.
 *
 * @param {string}   key sessionStorage key.
 * @param {string[]} ids Every ID reported in this session.
 */
const writeReported = ( key, ids ) => {
	try {
		window.sessionStorage.setItem( key, ids.join( ',' ) );
	} catch ( e ) {
		// See readReported: a write failure only costs the volume saving.
	}
};

/**
 * Evaluate the reader's segments and report what is new to this session: any
 * segment matched for the first time, and the priority winner if it has not
 * won before.
 *
 * Takes no arguments: it is pushed onto `window.newspackRAS`, which calls it
 * with the Reader Activation library, but it needs only that RAS is ready —
 * segment criteria read from the RAS store.
 */
export const reportMatchedSegments = () => {
	const segments = window.newspack_popups_view?.segments;
	// Check gtag before writing the session keys, so a pageview that could not
	// report does not silence the next one that can.
	if ( ! segments || 'function' !== typeof gtag ) {
		return;
	}
	// Segment previews (?view_as=…) are admin sessions impersonating a
	// segment, not reader behavior.
	if ( new URLSearchParams( window.location.search ).get( 'view_as' ) ) {
		return;
	}

	const ids = getMatchingSegmentIds( segments );

	// The empty match is tracked as a pseudo-ID, so "matched nothing" is
	// measurable and follows the same once-per-session rule as a real segment.
	const matched = ids.length ? ids : [ EMPTY_VALUE ];
	const reported = readReported( SESSION_KEY );
	const fresh = matched.filter( id => ! reported.includes( id ) );
	if ( fresh.length ) {
		fresh.forEach( id => sendEvent( { segment_id: id }, EVENT_NAME ) );
		writeReported( SESSION_KEY, reported.concat( fresh ) );
	}

	// The priority winner: lowest priority number among the matched set. Same
	// rule as getBestPrioritySegment, computed from the matched IDs directly so
	// the view_as preview override can never leak in. No winner when nothing
	// matches — the empty match is covered by the matched layer.
	if ( ! ids.length ) {
		return;
	}
	let winner = null;
	for ( const id of ids ) {
		if ( null === winner || segments[ id ].priority < segments[ winner ].priority ) {
			winner = id;
		}
	}
	const reportedWon = readReported( WON_SESSION_KEY );
	if ( ! reportedWon.includes( winner ) ) {
		sendEvent( { segment_id: winner }, WON_EVENT_NAME );
		writeReported( WON_SESSION_KEY, reportedWon.concat( [ winner ] ) );
	}
};
