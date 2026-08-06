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
 * Each segment reports once per GA4 session, the first time the reader
 * matches it. A segment that starts matching mid-session reports then; one
 * that stops matching is not reported again, since reach means the reader
 * matched it at some point during the session. The bookkeeping is
 * session-scoped state on the Reader Activation store (see
 * `../utils/session-store.js`), so the once-per-session rule tracks the same
 * session boundary GA4 reports group by.
 */

import { getCarriedSegmentIds, getMatchingSegmentIds, getPreviewedPromptId, sendEvent } from '../utils';
import { getCriteria } from '../../criteria/utils';
import { readSessionValue, writeSessionValue } from '../utils/session-store';

export const EVENT_NAME = 'np_segment_matched';
export const STORE_KEY = 'popups_reported_segments';
export const EMPTY_VALUE = 'none';
export { SESSION_TIMEOUT } from '../utils/session-store';

/**
 * Whether the request is a prompt or segment preview (`pid` or `view_as`),
 * mirroring the server's `Newspack_Popups::is_preview_request()`. Editors
 * iterating on prompts and segments must not count toward reach.
 *
 * @return {boolean} True if previewing.
 */
const isPreviewRequest = () => {
	if ( getPreviewedPromptId() ) {
		return true;
	}
	return !! new URLSearchParams( window.location.search ).get( 'view_as' );
};

/**
 * Whether every criterion the segment uses is registered client-side.
 *
 * `match()` treats an unregistered criterion as satisfied, so prompt display
 * degrades open — but reach must not: on a site where a criterion's
 * supporting plugin is inactive, its segments would read as matched by every
 * reader. Reach for those segments is unknowable, not universal, so they are
 * withheld from reporting.
 *
 * @param {Object} segment Segment config with a `criteria` array.
 *
 * @return {boolean} True if all of the segment's criteria are registered.
 */
const hasRegisteredCriteria = segment => ( segment?.criteria || [] ).every( item => getCriteria( item.criteria_id ) );

/**
 * Evaluate the reader's segments and report any that are new to this session.
 *
 * @param {Object} ras Reader Activation library object.
 */
const reportFreshMatches = ras => {
	const segments = window.newspack_popups_view?.segments;
	// Check gtag before writing state, so a pageview that could not report
	// does not silence the next one that can.
	if ( ! segments || 'function' !== typeof gtag || ! ras?.store ) {
		return;
	}
	// A site with no segments has no reach to measure: `none` is only
	// meaningful against segments that exist. Editors previewing prompts or
	// segments do not count toward reach either.
	const allIds = Object.keys( segments );
	if ( ! allIds.length || isPreviewRequest() ) {
		return;
	}
	// Withhold segments whose criteria are not all registered on this site —
	// they would read as matched by everyone (see hasRegisteredCriteria).
	const reportableSegments = {};
	allIds.forEach( id => {
		if ( hasRegisteredCriteria( segments[ id ] ) ) {
			reportableSegments[ id ] = segments[ id ];
		}
	} );
	// Segments carried in from a newsletter link count as matched for the
	// session, on top of anything matched locally. They assert the reader's
	// ESP-synced snapshot rather than local criteria evaluation, so they are
	// validated only for existence and bypass the withholding above.
	const carried = getCarriedSegmentIds( segments );
	if ( ! Object.keys( reportableSegments ).length && ! carried.length ) {
		return;
	}
	const localIds = getMatchingSegmentIds( reportableSegments );
	const ids = localIds.concat( carried.filter( id => ! localIds.includes( id ) ) );
	// The empty match is tracked as a pseudo-ID, so "matched nothing" is
	// measurable and follows the same once-per-session rule as a real segment.
	const matched = ids.length ? ids : [ EMPTY_VALUE ];
	const { sid, value } = readSessionValue( ras, STORE_KEY );
	const reported = Array.isArray( value ) ? value : [];
	const fresh = matched.filter( id => ! reported.includes( id ) );
	if ( ! fresh.length ) {
		// Nothing new to report; re-stamp the state so the fallback session
		// window keeps sliding with reader activity.
		writeSessionValue( ras, STORE_KEY, sid, reported );
		return;
	}
	const sent = [];
	try {
		fresh.forEach( id => {
			sendEvent( { segment_id: id }, EVENT_NAME );
			sent.push( id );
		} );
	} catch ( e ) {
		// A gtag shim that throws (e.g. a consent-platform wrapper) must not
		// unwind into the RAS queue drain, where it would abort prompt
		// display. IDs sent before the throw are recorded below, so only the
		// unsent remainder retries on the next pageview.
	}
	writeSessionValue( ras, STORE_KEY, sid, reported.concat( sent ) );
};

/**
 * Report the reader's matched segments, containing every failure: reporting
 * is best-effort, and the RAS queue drain that invokes this callback has no
 * error isolation of its own — an exception here would abort prompt display
 * and every callback queued behind it.
 *
 * Pushed onto `window.newspackRAS`, which calls it with the Reader Activation
 * library; the library's store keeps the once-per-session bookkeeping.
 *
 * @param {Object} ras Reader Activation library object.
 */
export const reportMatchedSegments = ras => {
	try {
		reportFreshMatches( ras );
	} catch ( e ) {
		// Never let segment reporting take the prompt pipeline down with it.
	}
};
