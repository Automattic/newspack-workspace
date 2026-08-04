/* global gtag */

/**
 * Report the reader's matched segment set to GA4.
 *
 * Runs on every pageview where segmentation is active — including gated
 * articles and posts with prompts disabled, neither of which reaches the
 * prompt-display path. That coverage is the point: reach numbers that omit
 * gated pageviews understate exactly the audience a publisher is trying to
 * size.
 *
 * Scoped to the browsing session rather than the pageview. A session's first
 * evaluation reports; identical sets stay silent; a changed set reports again,
 * which catches the reader who crosses a pageview threshold mid-session. User
 * counts in GA4 dedupe regardless of firing frequency, so this costs nothing
 * for reach while keeping the added event volume off publishers' BigQuery
 * export quota.
 */

import { getMatchingSegmentIds, sendEvent } from '../utils';

export const EVENT_NAME = 'np_segments_matched';
export const SESSION_KEY = 'newspack-popups-reported-segments';
export const EMPTY_VALUE = 'none';

// GA4 silently truncates event parameter values at 100 characters.
const MAX_PARAM_LENGTH = 100;

/**
 * Join segment IDs into a comma-separated list that fits GA4's parameter
 * length cap.
 *
 * Drops whole IDs from the end rather than letting GA4 cut the value
 * arbitrarily: a value truncated mid-number leaves a fragment that reads as a
 * different, real segment, turning a rare overflow into silently wrong data.
 *
 * @param {string[]} ids Sorted segment IDs.
 *
 * @return {string} Comma-separated IDs, at most MAX_PARAM_LENGTH characters.
 */
export const joinWithinLimit = ids => {
	let value = '';
	for ( const id of ids ) {
		const next = value ? `${ value },${ id }` : `${ id }`;
		if ( next.length > MAX_PARAM_LENGTH ) {
			break;
		}
		value = next;
	}
	return value;
};

/**
 * Read the set reported earlier in this session.
 *
 * @return {string|null} The last reported value, or null if none or unreadable.
 */
const readReported = () => {
	try {
		return window.sessionStorage.getItem( SESSION_KEY );
	} catch ( e ) {
		// sessionStorage unavailable (e.g. private mode). Treating this as
		// "nothing reported yet" degrades the reader to per-pageview dispatch,
		// which costs volume but loses no data.
		return null;
	}
};

/**
 * Remember the set just reported, so the rest of the session stays quiet.
 *
 * @param {string} value The value that was reported.
 */
const writeReported = value => {
	try {
		window.sessionStorage.setItem( SESSION_KEY, value );
	} catch ( e ) {
		// See readReported: a write failure only costs the volume saving.
	}
};

/**
 * Evaluate the reader's segments and report them to GA4 if the set is new to
 * this session.
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
	const value = ids.length ? joinWithinLimit( ids ) : EMPTY_VALUE;
	if ( value === readReported() ) {
		return;
	}
	writeReported( value );
	sendEvent( { segments: value }, EVENT_NAME );
};
