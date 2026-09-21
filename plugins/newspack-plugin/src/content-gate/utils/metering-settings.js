/**
 * ID of the element `Metering::enqueue_scripts()` prints the settings into.
 * Keep in step with `Metering::SETTINGS_ELEMENT_ID`.
 */
const SETTINGS_ELEMENT_ID = 'newspack-metering-settings';

let cached;
let warned;

/**
 * The metering allowance for the current post.
 *
 * Read on demand rather than at module evaluation, and from the DOM rather than a
 * global: a performance optimizer can hold back an inline script while letting the
 * metering file through, and a meter that cannot read its allowance leaves every
 * metered article readable, because metering makes the server send the whole
 * article. The settings element is `type="application/json"`, so it is data the
 * parser puts in the DOM rather than a script anything can reorder.
 *
 * The parsed object is mirrored onto `window.newspack_metering_settings`, which is
 * where this payload has always been readable from.
 *
 * @return {Object|null} The settings, or null when the post carries none.
 */
export function getMeteringSettings() {
	if ( undefined !== cached ) {
		return cached;
	}
	const element = document.getElementById( SETTINGS_ELEMENT_ID );
	if ( ! element ) {
		cached = null;
		return cached;
	}
	try {
		cached = JSON.parse( element.textContent );
	} catch ( error ) {
		cached = null;
	}
	if ( ! cached || 'object' !== typeof cached ) {
		cached = null;
		if ( ! warned ) {
			warned = true;
			// eslint-disable-next-line no-console
			console.warn( 'Newspack: could not read the metering settings, so the meter did not run.' );
		}
		return cached;
	}
	window.newspack_metering_settings = cached;
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
