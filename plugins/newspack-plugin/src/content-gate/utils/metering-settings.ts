/**
 * ID of the element `Metering::enqueue_scripts()` prints the allowance into.
 * Keep in step with `Metering::SETTINGS_ELEMENT_ID`.
 */
const SETTINGS_ELEMENT_ID = 'newspack-content-gate-metering-settings';

/**
 * The metering allowance for the current post, parsed from the JSON element PHP
 * prints. Served through wp_localize_script()/JSON, so scalars can arrive as
 * either numbers or strings — callers coerce at the point of use.
 */
export interface MeteringSettings {
	count: number | string;
	post_id: number | string;
	meter_key?: number | string;
	gate_id?: number | string;
	expiration?: number | string;
	next_expiration?: number | string;
	excerpt: string;
	article_view?: { action: string; data: Record< string, unknown > };
	[ key: string ]: unknown;
}

let cached: MeteringSettings | undefined;
let warned = false;

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
 * @return The allowance, or null when the post carries none.
 */
export function getMeteringSettings(): MeteringSettings | null {
	if ( cached ) {
		return cached;
	}
	const element = document.getElementById( SETTINGS_ELEMENT_ID );
	if ( ! element ) {
		return null;
	}
	let parsed: unknown;
	try {
		parsed = JSON.parse( element.textContent as string );
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
	cached = parsed as MeteringSettings;
	return cached;
}

/**
 * The reader-data store key the meter counts views under.
 *
 * @param settings Metering settings.
 *
 * @return Store key.
 */
export function getMeteringStoreKey( settings: MeteringSettings ): string {
	return 'metering-' + ( settings.meter_key || settings.gate_id || 0 );
}
