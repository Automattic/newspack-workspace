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
 * ── Storage contract ────────────────────────────────────────────────────────────
 * This contract is DUPLICATED, by necessity, in newspack-blocks
 * (`src/shared/js/cta-attribution.js`), which owns the checkout and donate forms and
 * therefore owns the replay. Both plugins ship independently; there is no shared JS
 * package between them. Change one, change the other.
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

/** Storage key. Shared with newspack-blocks. */
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
