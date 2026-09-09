/* global gtag */

/**
 * Report the reader's matched segments to GA4, in two layers.
 *
 * `np_segment_matched` fires once per segment per GA4 session, the first time
 * the reader satisfies its criteria — the segment's total reach. But prompts
 * follow only the reader's highest-priority match, so satisfying a segment's
 * criteria is not the same as seeing its prompts. `np_segment_won` records
 * that second layer: fired once per segment per session, the first time the
 * segment is the reader's priority winner. Reach and prompt audience are then
 * separate one-filter reports on the same dimension, and a segment whose
 * matched count far exceeds its won count is shadowed by higher-priority
 * segments.
 *
 * The matched layer must report the full set, not just the winner: a
 * probation segment (active, assigned to no prompt) has to sit at the bottom
 * of the priority list — if it won the match it would suppress other
 * segments' prompts for every overlapping reader — so wherever it overlaps
 * established segments it rarely wins, and winner-only reporting would show
 * ~zero for exactly the segment being sized.
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
 * A segment that starts matching (or winning) mid-session reports then; one
 * that stops matching is not reported again, since reach means the reader
 * matched it at some point during the session. The bookkeeping lives in
 * Campaigns-owned, site-namespaced localStorage — shared across a session's
 * tabs, and deliberately not a reader data item: it is dispatch bookkeeping
 * for this device, not audience data.
 *
 * localStorage outlives any single session on purpose — no browser storage
 * has the GA4 session's lifetime (sessionStorage is per-tab: it splits one
 * session across tabs and spans many sessions in a pinned tab) — so the
 * boundary is enforced on read instead: the stored sets are discarded when
 * the GA4 session ID from the `_ga_*` cookie no longer matches, or, when no
 * GA cookie is readable, after a 30-minute sliding window matching GA4's
 * inactivity timeout. The once-per-session rule therefore tracks the same
 * session boundary GA4 reports group by, whatever the storage's own lifetime.
 */

import { getMatchingSegmentIds, getPreviewedPromptId, sendEvent } from '../utils';
import { getCriteria } from '../../criteria/utils';

export const EVENT_NAME = 'np_segment_matched';
export const WON_EVENT_NAME = 'np_segment_won';
export const STORAGE_KEY = 'newspack-popups-reported-segments';
export const EMPTY_VALUE = 'none';
// GA4's session inactivity timeout, for the fallback session boundary.
export const SESSION_TIMEOUT = 30 * 60 * 1000;

/**
 * The storage key, namespaced per site so sites sharing an origin
 * (subdirectory multisite) cannot suppress each other's segment IDs.
 *
 * @return {string} Site-scoped storage key.
 */
const storageKey = () => `${ STORAGE_KEY }-${ window.newspack_popups_view?.site_id || 0 }`;

/**
 * Read the GA4 session ID from the `_ga_<container>` cookie.
 *
 * Mirrors the server-side parse in newspack-plugin's
 * `GoogleSiteKit::extract_sid_from_cookies()` — the third dot-piece of the
 * cookie value — reduced to its leading digits so the GS1 and GS2 cookie
 * formats both yield the bare session ID (in GS2 the piece carries
 * `$`-delimited counters that change within a session).
 *
 * @return {string|null} Session ID, or null when no GA cookie is readable.
 */
const getGa4SessionId = () => {
	try {
		const cookies = document.cookie ? document.cookie.split( '; ' ) : [];
		for ( const cookie of cookies ) {
			const separator = cookie.indexOf( '=' );
			if ( 0 !== cookie.indexOf( '_ga_' ) || -1 === separator ) {
				continue;
			}
			const pieces = cookie.slice( separator + 1 ).split( '.' );
			const sid = pieces[ 2 ] ? ( pieces[ 2 ].match( /\d+/ ) || [] )[ 0 ] : null;
			if ( sid ) {
				return sid;
			}
		}
	} catch ( e ) {
		// document.cookie unreadable; use the time-based boundary instead.
	}
	return null;
};

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
 * Read the reporting state, resetting the reported sets when the session
 * boundary has moved on: a new GA4 session ID, or — when no GA cookie is
 * readable — more than the session timeout since the last evaluation.
 *
 * @return {Object} `{ sid, ids, won }` — boundary token, reported IDs, and
 *                  the subset reported as priority winner.
 */
const readState = () => {
	const sid = getGa4SessionId();
	try {
		const state = JSON.parse( window.localStorage.getItem( storageKey() ) ) || {};
		const ids = Array.isArray( state.ids ) ? state.ids : [];
		const won = Array.isArray( state.won ) ? state.won : [];
		if ( sid ) {
			const same = state.sid === sid;
			return { sid, ids: same ? ids : [], won: same ? won : [] };
		}
		const expired = ! state.ts || Date.now() - state.ts > SESSION_TIMEOUT;
		return { sid, ids: expired ? [] : ids, won: expired ? [] : won };
	} catch ( e ) {
		// Unreadable state (storage unavailable or corrupted) degrades the
		// reader to per-pageview dispatch, which costs volume but loses no data.
		return { sid, ids: [], won: [] };
	}
};

/**
 * Remember the segment IDs reported so far, so the rest of the session stays
 * quiet about them. Also stamps the boundary token: the GA4 session ID and
 * the time of the last evaluation, which keeps the fallback window sliding.
 *
 * @param {string|null} sid GA4 session ID, if readable.
 * @param {string[]}    ids Every ID reported in this session.
 * @param {string[]}    won Every ID reported as priority winner.
 */
const writeState = ( sid, ids, won ) => {
	try {
		window.localStorage.setItem( storageKey(), JSON.stringify( { sid, ts: Date.now(), ids, won } ) );
	} catch ( e ) {
		// See readState: an unwritable state only costs the volume saving —
		// and must never unwind into the RAS queue drain.
	}
};

/**
 * Evaluate the reader's segments and report what is new to this session: any
 * segment matched for the first time, and the priority winner if it has not
 * won before.
 */
const reportFreshMatches = () => {
	const segments = window.newspack_popups_view?.segments;
	// Check gtag before writing state, so a pageview that could not report
	// does not silence the next one that can.
	if ( ! segments || 'function' !== typeof gtag ) {
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
	if ( ! Object.keys( reportableSegments ).length ) {
		return;
	}
	const ids = getMatchingSegmentIds( reportableSegments );
	// The empty match is tracked as a pseudo-ID, so "matched nothing" is
	// measurable and follows the same once-per-session rule as a real segment.
	const matched = ids.length ? ids : [ EMPTY_VALUE ];
	// The priority winner: lowest priority number among the matched set — the
	// same rule as getBestPrioritySegment, computed from the matched IDs
	// directly so that function's view_as preview override can never leak in.
	// No winner when nothing matches: the empty match lives on the matched
	// layer only.
	let winner = null;
	for ( const id of ids ) {
		if ( null === winner || reportableSegments[ id ].priority < reportableSegments[ winner ].priority ) {
			winner = id;
		}
	}
	const { sid, ids: reported, won: reportedWon } = readState();
	const fresh = matched.filter( id => ! reported.includes( id ) );
	const freshWinner = winner && ! reportedWon.includes( winner ) ? winner : null;
	if ( ! fresh.length && ! freshWinner ) {
		// Nothing new to report; re-stamp the state so the fallback session
		// window keeps sliding with reader activity.
		writeState( sid, reported, reportedWon );
		return;
	}
	const sent = [];
	const sentWon = [];
	try {
		fresh.forEach( id => {
			sendEvent( { segment_id: id }, EVENT_NAME );
			sent.push( id );
		} );
		if ( freshWinner ) {
			sendEvent( { segment_id: freshWinner }, WON_EVENT_NAME );
			sentWon.push( freshWinner );
		}
	} catch ( e ) {
		// A gtag shim that throws (e.g. a consent-platform wrapper) must not
		// unwind into the RAS queue drain, where it would abort prompt
		// display. IDs sent before the throw are recorded below, so only the
		// unsent remainder retries on the next pageview.
	}
	writeState( sid, reported.concat( sent ), reportedWon.concat( sentWon ) );
};

/**
 * Report the reader's matched segments, containing every failure: reporting
 * is best-effort, and the RAS queue drain that invokes this callback has no
 * error isolation of its own — an exception here would abort prompt display
 * and every callback queued behind it.
 *
 * Takes no arguments: it is pushed onto `window.newspackRAS` only for timing
 * — segment criteria read from the RAS store, so evaluation needs RAS ready —
 * while its own bookkeeping stays out of the reader data store.
 */
export const reportMatchedSegments = () => {
	try {
		reportFreshMatches();
	} catch ( e ) {
		// Never let segment reporting take the prompt pipeline down with it.
	}
};
