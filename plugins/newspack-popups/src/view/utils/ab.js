/* globals newspack_popups_view */

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
 * anonymous assignments made by the POC carry over, and is bit-for-bit
 * identical to the server-side Newspack_Popups_AB_Tests::hash_djb2().
 *
 * ASCII-ONLY PRECONDITION: this hashes UTF-16 code units (charCodeAt) while
 * the server hashes UTF-8 bytes — the two agree only while every hashed input
 * (client ID, test ID) is ASCII, which holds today by construction. If either
 * input's charset ever widens, normalize both implementations together.
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
	if ( ! match ) {
		return null;
	}
	try {
		// A cookie value the reader set by hand can contain a stray percent, which
		// throws URIError here. Uncaught, that aborts prompt processing for the whole
		// page, so an unreadable id degrades to "no id" rather than to no prompts.
		//
		// The server reads this same cookie with sanitize_text_field(), which does not
		// URL-decode. The two agree for Newspack-generated client IDs; a value needing
		// decoding is a second way the pair can diverge, beyond the ASCII-only
		// precondition documented on computeBucket().
		return decodeURIComponent( match[ 1 ] );
	} catch ( e ) {
		return null;
	}
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
 * Precedence: server-echoed variant preview (view_as=ab_variant:x, admin-gated
 * in PHP) > server-computed bucket (logged-in readers) > client-side hash of
 * the reader's client ID > control. The control fallback keeps the one-variant
 * invariant for readers with no stable identity (e.g. cookies blocked) — an
 * inline test must not render both arms.
 *
 * @param {string}      testId       Test ID.
 * @param {Object}      config       Test config with variants and control_share.
 * @param {string|null} cookieString Optional, for testing. A cookie string to parse.
 * @return {string} Variant key.
 */
export const getAssignedBucket = ( testId, config, cookieString = null ) => {
	const variants = config.variants || [];

	// Variant preview for editors, echoed by the server through the admin-gated
	// View_As spec — never parsed from the URL here.
	const viewAsVariant = getViewData().ab_view_as;
	if ( viewAsVariant && -1 < variants.indexOf( viewAsVariant ) ) {
		return viewAsVariant;
	}

	// Server-computed bucket for logged-in readers.
	const serverBucket = getViewData().ab_buckets?.[ testId ];
	if ( serverBucket && -1 < variants.indexOf( serverBucket ) ) {
		return serverBucket;
	}

	const readerId = getReaderId( cookieString );
	if ( ! readerId ) {
		// No stable identity: fail to control.
		return 'a';
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
 * @param {string|null} cookieString Optional, for testing. A cookie string to parse.
 * @return {boolean|null} The override value to pass to the shouldPromptBeDisplayed function.
 */
export const getAbOverride = ( prompt, cookieString = null ) => {
	const testId = prompt.getAttribute( 'data-ab-test-id' );
	if ( ! testId ) {
		return null;
	}
	const config = getViewData().ab_tests?.[ testId ];
	if ( ! config ) {
		// Unknown or invalid test (e.g. missing challenger): fail open.
		return null;
	}
	const bucket = getAssignedBucket( testId, config, cookieString );
	return bucket === prompt.getAttribute( 'data-ab-variant' ) ? null : false;
};
