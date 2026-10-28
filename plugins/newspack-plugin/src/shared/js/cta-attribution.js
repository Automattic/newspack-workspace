/**
 * CTA attribution hand-off (NPPD-1887).
 *
 * When a reader clicks a conversion-intent CTA inside a gate or prompt and is sent
 * to a landing page, the surface that caused the conversion is left behind. This
 * module persists the surface's identity so the landing page's checkout/donate form
 * can replay it as a hidden field, letting the existing server chain
 * (`INPUT_GET` -> cart item data -> `_gate_post_id` / `_newspack_popup_id` order meta)
 * fire exactly as it does for a form rendered inside the gate itself.
 *
 * This plugin writes the record when the CTA is clicked (`persistCtaAttribution()`)
 * and replays it into the checkout and donate forms it took over from newspack-blocks
 * (`applyCtaAttribution()`).
 *
 * Two cart paths exist, and they apply DIFFERENT filters. Checkout buttons go through
 * `Modal_Checkout::add_to_cart_and_redirect()` -> `newspack_blocks_modal_checkout_cart_item_data`;
 * donations go through `Donations::process_donation_request()` -> `newspack_donations_cart_item_data`.
 * Injecting the field here is necessary but not sufficient — a listener must exist on
 * whichever filter the target form uses, or the id rides all the way into `INPUT_GET`
 * and is then dropped.
 *
 * ── Storage contract ────────────────────────────────────────────────────────────
 * The reading half is DUPLICATED in newspack-blocks (`src/shared/js/cta-attribution.js`)
 * for as long as that plugin ships, and either plugin's copy can be the one replaying.
 * There is no shared JS package between them. Change one, change the other.
 *
 *   sessionStorage[ 'newspack_cta_attribution' ] = JSON.stringify( {
 *     v:    1,                   // SCHEMA — reject records written by a drifted shape
 *     type: 'gate' | 'prompt',   // which surface
 *     id:   '123',               // gate_post_id or newspack_popup_id
 *     ts:   1751932800000,       // Date.now() at click
 *   } )
 *
 * `sessionStorage`, not a cookie, on purpose:
 *   - survives multi-hop navigation (gate -> /subscribe -> /plans -> convert)
 *   - is copied into a new tab opened from `target="_blank"`
 *   - dies with the tab, which is a naturally conservative attribution window
 *   - cannot vary a page-cache key
 *
 * Last touch wins: a second CTA click overwrites the first.
 */

/** Storage key. Shared with newspack-blocks' copy of the reader. */
export const STORAGE_KEY = 'newspack_cta_attribution';

/**
 * Storage-record schema version. The contract is duplicated across newspack-plugin and
 * newspack-blocks (no shared JS package), so a mismatch is a real failure mode if the
 * two ever drift — one side bumps TTL or the record shape, the other silently honors a
 * stale record. Stamping the version and checking it on read makes drift fail safe: a
 * record from a different version is ignored rather than trusted. Bump on any change to
 * the record fields, the TTL semantics, or the storage key. (dkoo review, #575.)
 */
export const SCHEMA = 1;

/**
 * Attribution lifetime, in milliseconds.
 *
 * Session-scoped AND time-bounded (product decision, NPPD-1887): a reader who clicks
 * the CTA, wanders off, and converts two hours later in the same tab gets no credit.
 * Kept in sync with the same constant in newspack-blocks.
 */
export const TTL_MS = 30 * 60 * 1000;

/** Surface type -> the form field the server reads. */
const FIELD_BY_TYPE = {
	gate: 'gate_post_id',
	prompt: 'newspack_popup_id',
};

/**
 * Persist the surface that owns this click.
 *
 * Never throws: Safari private mode and storage-disabled browsers make
 * `sessionStorage.setItem` raise. Attribution is best-effort — losing it must never
 * break the navigation the reader actually asked for.
 *
 * @param {string}        type Surface type: 'gate' or 'prompt'.
 * @param {string|number} id   The gate_post_id or newspack_popup_id.
 *
 * @return {boolean} Whether the value was stored.
 */
export function persistCtaAttribution( type, id ) {
	if ( ! id || ( 'gate' !== type && 'prompt' !== type ) ) {
		return false;
	}
	try {
		window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( { v: SCHEMA, type, id: String( id ), ts: Date.now() } ) );
		return true;
	} catch ( e ) {
		return false;
	}
}

/**
 * Read a fresh attribution record, or null.
 *
 * Never throws: `sessionStorage` access raises in Safari private mode, and the stored
 * value is user-writable so `JSON.parse` can fail. Attribution is best-effort — a bad
 * record must never block a checkout.
 *
 * @return {{type: string, id: string, ts: number}|null} The record, or null.
 */
export function readCtaAttribution() {
	let raw;
	try {
		raw = window.sessionStorage.getItem( STORAGE_KEY );
	} catch ( e ) {
		return null;
	}
	if ( ! raw ) {
		return null;
	}
	let record;
	try {
		record = JSON.parse( raw );
	} catch ( e ) {
		return null;
	}
	// Reject records written by a different schema version (drift fail-safe).
	if ( ! record || record.v !== SCHEMA || ! FIELD_BY_TYPE[ record.type ] || ! record.id ) {
		return null;
	}
	// Session-scoped AND time-bounded (NPPD-1887 product decision). A reader who clicks
	// the CTA, wanders off, and converts two hours later in the same tab gets no credit.
	if ( 'number' !== typeof record.ts || Date.now() - record.ts > TTL_MS ) {
		return null;
	}
	return record;
}

/**
 * Inject the persisted surface id into a checkout/donate form, if it needs one.
 *
 * Two guards, both load-bearing:
 *
 *   1. A form that ALREADY carries the field wins. That is a form rendered inside the
 *      gate or prompt itself, whose id was stamped server-side (prompts) or by
 *      `gate.js::addFormInputs()` (gates). A replayed value must never overwrite the
 *      surface the reader is actually looking at. Same precedence rule that
 *      `copyContextFields()` applies to PICKER_CONTEXT_FIELDS.
 *
 *   2. A form inside a gate is never replayed into. `gate.js` owns attribution there,
 *      and it binds on the gate's 'seen' event — which may not have fired yet when
 *      this runs. Without this guard, a stale id from a gate on a PREVIOUS page could
 *      beat the current page's gate to the field.
 *
 * Called from the submit handler rather than at DOM-ready, so it observes the final
 * state of the form after every other script has had its turn.
 *
 * @param {HTMLFormElement} form The form about to be submitted.
 *
 * @return {boolean} Whether a field was injected.
 */
export function applyCtaAttribution( form ) {
	if ( ! form || 'function' !== typeof form.querySelector ) {
		return false;
	}
	if ( form.closest( '.newspack-content-gate__gate' ) ) {
		return false;
	}
	const record = readCtaAttribution();
	if ( ! record ) {
		return false;
	}
	const name = FIELD_BY_TYPE[ record.type ];
	if ( form.querySelector( `input[name="${ name }"]` ) ) {
		return false;
	}
	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.name = name;
	input.value = record.id;
	form.prepend( input );
	return true;
}
