/* global gtag */

/**
 * Report the reader's matched segments to GA4, one event per segment.
 *
 * Runs on every pageview where segmentation is active — including gated
 * articles and posts with prompts disabled, neither of which reaches the
 * prompt-display path. That coverage is the point: reach numbers that omit
 * gated pageviews understate exactly the audience a publisher is trying to
 * size.
 *
 * One event per segment rather than one event listing them all, so reach is a
 * plain GA4 report: dimension `segment_id`, metric Total users, every segment
 * ranked. A combined list would need a regex filter per segment to avoid one
 * ID matching inside another (12 inside 120), and its distinct values would be
 * segment *combinations* — which passes GA4's 500-value high-cardinality
 * threshold on a site with many segments and starts collapsing rows into
 * `(other)`, silently under-counting.
 *
 * Each segment reports once per browsing session, the first time the reader
 * matches it. A segment that starts matching mid-session reports then; one that
 * stops matching is not reported again, since reach means the reader matched it
 * at some point during the session.
 */

import { getMatchingSegmentIds, sendEvent } from '../utils';

export const EVENT_NAME = 'np_segment_matched';
export const SESSION_KEY = 'newspack-popups-reported-segments';
export const EMPTY_VALUE = 'none';

/**
 * Read the segment IDs already reported in this session.
 *
 * @return {string[]} Reported IDs, empty if none or unreadable.
 */
const readReported = () => {
	try {
		const stored = window.sessionStorage.getItem( SESSION_KEY );
		return stored ? stored.split( ',' ) : [];
	} catch ( e ) {
		// sessionStorage unavailable (e.g. private mode). Treating this as
		// "nothing reported yet" degrades the reader to per-pageview dispatch,
		// which costs volume but loses no data.
		return [];
	}
};

/**
 * Remember the segment IDs reported so far, so the rest of the session stays
 * quiet about them.
 *
 * @param {string[]} ids Every ID reported in this session.
 */
const writeReported = ids => {
	try {
		window.sessionStorage.setItem( SESSION_KEY, ids.join( ',' ) );
	} catch ( e ) {
		// See readReported: a write failure only costs the volume saving.
	}
};

/**
 * Evaluate the reader's segments and report any that are new to this session.
 *
 * Takes no arguments: it is pushed onto `window.newspackRAS`, which calls it
 * with the Reader Activation library, but it needs only that RAS is ready —
 * segment criteria read from the RAS store.
 */
export const reportMatchedSegments = () => {
	const segments = window.newspack_popups_view?.segments;
	// Check gtag before writing the session key, so a pageview that could not
	// report does not silence the next one that can.
	if ( ! segments || 'function' !== typeof gtag ) {
		return;
	}
	const ids = getMatchingSegmentIds( segments );
	// The empty match is tracked as a pseudo-ID, so "matched nothing" is
	// measurable and follows the same once-per-session rule as a real segment.
	const matched = ids.length ? ids : [ EMPTY_VALUE ];
	const reported = readReported();
	const fresh = matched.filter( id => ! reported.includes( id ) );
	if ( ! fresh.length ) {
		return;
	}
	fresh.forEach( id => sendEvent( { segment_id: id }, EVENT_NAME ) );
	writeReported( reported.concat( fresh ) );
};
