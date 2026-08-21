/**
 * Defer reader-activity recording until a prerendered page is actually shown.
 *
 * Browsers can prerender a link the reader never clicks (Chrome's "Preload
 * pages" setting, a CDN, or a site running speculative loading in `prerender`
 * mode). Scripts run in that hidden document, so anything that records a visit
 * runs too — inflating pageviews, spending prompt frequency caps and eating
 * metered free reads for a page nobody opened (NPPM-3134).
 *
 * Only *recording* belongs behind this gate. Decisions that affect what the
 * prerendered document displays — whether metering locks the content, which
 * segment a prompt matches — must still run during prerender, or the page
 * would activate and then visibly change under the reader.
 *
 * Kept deliberately in step with
 * newspack-popups/src/view/utils/prerender.js, which needs the same gate for
 * its pageview counters. The two are duplicated rather than shared because
 * there is nowhere good to share them to: `packages/` is React components and
 * build tooling, not view-layer utilities, and this is ~10 lines of
 * dependency-free DOM code — the same call made for the preview-links pair (see
 * the header of ../content-gate/preview-links.js). (Sharing would not create
 * version coupling — workspace packages are bundled into each plugin's own
 * dist/ at build time — so that is not the reason.) If you change one, change
 * the other; the copies are identical today.
 */

/**
 * Run a callback now, or once a prerendered document is activated.
 *
 * If the prerender is discarded because the reader never navigates, the
 * callback never runs — which is the point.
 *
 * Browsers without the Speculation Rules API expose no `document.prerendering`,
 * so the callback runs inline exactly as it did before.
 *
 * @param {Function} callback Callback to run.
 */
export function whenActivated( callback ) {
	if ( ! document.prerendering ) {
		callback();
		return;
	}
	document.addEventListener( 'prerenderingchange', () => callback(), { once: true } );
}
