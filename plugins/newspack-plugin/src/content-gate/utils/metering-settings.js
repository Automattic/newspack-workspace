/**
 * ID of the element `Metering::enqueue_scripts()` prints the allowance into.
 * Keep in step with `Metering::SETTINGS_ELEMENT_ID`.
 */
const SETTINGS_ELEMENT_ID = 'newspack-content-gate-metering-settings';

let cached;
let warned;

/**
 * The metering allowance for the current post.
 *
 * Read from the DOM on demand, never at module evaluation. A performance optimizer can
 * hold an inline script back while letting another through, and a meter that cannot
 * read its allowance leaves every metered article readable, because metering makes the
 * server send the whole article. The allowance therefore travels as a
 * `type="application/json"` element, which is data the parser puts in the DOM rather
 * than a script anything can reorder.
 *
 * Only a successful parse is memoized. Caching the miss would freeze the first caller's
 * answer for the page, which is the order dependency this exists to remove.
 *
 * @return {Object|null} The allowance, or null when the post carries none.
 */
export function getMeteringSettings() {
	if ( cached ) {
		return cached;
	}
	const element = document.getElementById( SETTINGS_ELEMENT_ID );
	if ( ! element ) {
		return null;
	}
	let parsed;
	try {
		parsed = JSON.parse( element.textContent );
	} catch ( error ) {
		parsed = null;
	}
	if ( ! parsed || 'object' !== typeof parsed ) {
		if ( ! warned ) {
			warned = true;
			// eslint-disable-next-line no-console
			console.warn( 'Newspack: could not read the metering allowance, so the meter did not run.' );
		}
		return null;
	}
	cached = parsed;
	return cached;
}

/**
 * The reader-data store key the meter counts views under.
 *
 * @param {Object} settings Metering settings.
 *
 * @return {string} Store key.
 */
export function getMeteringStoreKey( settings ) {
	return 'metering-' + ( settings.meter_key || settings.gate_id || 0 );
}
