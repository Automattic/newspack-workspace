/* globals newspack_popups_view */

import { parseViewAs } from './segments';

const DEFAULT_CID_COOKIE = 'newspack-cid';
const DEFAULT_CONTROL_SHARE = 50;

/**
 * Get the localized view data, guarded for test environments.
 *
 * @return {Object} View data.
 */
const getViewData = () => ( typeof newspack_popups_view !== 'undefined' ? newspack_popups_view : {} );

/**
 * djb2 (xor variant) string hash. Matches the POC's client-side hash so
 * anonymous assignments made by the POC carry over.
 *
 * @param {string} str String to hash.
 * @return {number} Unsigned 32-bit hash.
 */
export const hashString = str => {
	let hash = 5381;
	for ( let i = 0; i < str.length; i++ ) {
		hash = ( ( hash << 5 ) + hash ) ^ str.charCodeAt( i ); // eslint-disable-line no-bitwise
	}
	return hash >>> 0; // eslint-disable-line no-bitwise
};

/**
 * Get the reader's client ID from the Reader Activation cookie.
 *
 * @param {string|null} cookieString Optional, for testing. A cookie string to parse.
 * @return {string|null} Client ID, or null if unavailable.
 */
export const getReaderId = ( cookieString = null ) => {
	const cookieName = getViewData().cid_cookie || DEFAULT_CID_COOKIE;
	const cookies = null === cookieString ? document.cookie || '' : cookieString;
	const match = cookies.match( new RegExp( '(?:^|;\\s*)' + cookieName + '=([^;]+)' ) );
	return match ? decodeURIComponent( match[ 1 ] ) : null;
};

/**
 * Assign a reader to a bucket using weighted ranges.
 *
 * Example — control_share=60, variants a/b:
 *   A: 0.00 – 0.60 (60%)
 *   B: 0.60 – 1.00 (40%)
 *
 * @param {string} readerId Stable reader identifier.
 * @param {string} testId   Test ID.
 * @param {Object} config   Test config with variants and control_share.
 * @return {string} Variant key.
 */
export const computeBucket = ( readerId, testId, config ) => {
	const challengers = ( config.variants || [] ).filter( variant => 'a' !== variant );
	if ( ! challengers.length ) {
		return 'a';
	}
	const controlShare = ( config.control_share || DEFAULT_CONTROL_SHARE ) / 100;
	const challengerShare = ( 1 - controlShare ) / challengers.length;

	const ranges = [ [ 'a', controlShare ] ];
	let cursor = controlShare;
	for ( const variant of challengers ) {
		cursor += challengerShare;
		ranges.push( [ variant, cursor ] );
	}

	const normalized = hashString( readerId + '|' + testId ) / 0xffffffff;

	for ( const [ variant, end ] of ranges ) {
		if ( normalized <= end ) {
			return variant;
		}
	}
	return ranges[ ranges.length - 1 ][ 0 ];
};

/**
 * Get the reader's assigned bucket for a test.
 *
 * Precedence: view_as=ab_variant:x preview > server-computed bucket (logged-in
 * readers) > client-side hash of the reader's client ID. Returns null when no
 * stable identity is available (fail open: no A/B suppression).
 *
 * @param {string}      testId       Test ID.
 * @param {Object}      config       Test config with variants and control_share.
 * @param {string|null} viewAsString Optional, for testing. A query string with view_as params.
 * @param {string|null} cookieString Optional, for testing. A cookie string to parse.
 * @return {string|null} Variant key, or null.
 */
export const getAssignedBucket = ( testId, config, viewAsString = null, cookieString = null ) => {
	const variants = config.variants || [];

	// Variant preview for editors: ?view_as=ab_variant:b.
	const viewAs = parseViewAs( viewAsString );
	if ( viewAs?.ab_variant && -1 < variants.indexOf( viewAs.ab_variant ) ) {
		return viewAs.ab_variant;
	}

	// Server-computed bucket for logged-in readers.
	const serverBucket = getViewData().ab_buckets?.[ testId ];
	if ( serverBucket && -1 < variants.indexOf( serverBucket ) ) {
		return serverBucket;
	}

	const readerId = getReaderId( cookieString );
	if ( ! readerId ) {
		return null;
	}
	return computeBucket( readerId, testId, config );
};

/**
 * Get an A/B override value for a prompt, to compose with getOverride():
 * - false - The prompt is a test variant the reader is not assigned to; suppress it.
 * - null (default) - Not part of a valid test, or the reader's assigned variant;
 *   let segmentation and frequency controls decide. Never returns true — the
 *   assigned variant must still pass the normal display checks.
 *
 * @param {HTMLElement} prompt       HTML element of the prompt being checked.
 * @param {string|null} viewAsString Optional, for testing. A query string with view_as params.
 * @param {string|null} cookieString Optional, for testing. A cookie string to parse.
 * @return {boolean|null} The override value to pass to the shouldPromptBeDisplayed function.
 */
export const getAbOverride = ( prompt, viewAsString = null, cookieString = null ) => {
	const testId = prompt.getAttribute( 'data-ab-test-id' );
	if ( ! testId ) {
		return null;
	}
	const config = getViewData().ab_tests?.[ testId ];
	if ( ! config ) {
		// Unknown or invalid test (e.g. missing challenger): fail open.
		return null;
	}
	const bucket = getAssignedBucket( testId, config, viewAsString, cookieString );
	if ( ! bucket ) {
		return null;
	}
	return bucket === prompt.getAttribute( 'data-ab-variant' ) ? null : false;
};
