/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

/**
 * Style dependencies
 */

import './view.scss';

/**
 * Whether the frame still shows its initial about:blank, so it has loaded nothing.
 * Google Docs Viewer intermittently answers HTTP 204, which leaves the frame blank
 * with no load event. That is the case the retry exists for.
 *
 * contentDocument is null for a loaded cross-origin document, a detached frame, and
 * a sandboxed frame without allow-same-origin (from the first tick, so such a frame
 * would never retry; this block renders no sandbox attribute).
 *
 * @param {HTMLIFrameElement} iframe The iframe.
 * @return {boolean} Whether the frame is still on its initial about:blank.
 */
const isStillBlank = iframe => {
	const doc = iframe.contentDocument;
	return doc !== null && doc.URL === 'about:blank';
};

/**
 * Whether the frame has an address of its own to retry. A lazy loader can hold it
 * back until the frame nears the viewport (perfmatters moves it to data-src). With
 * no src, iframe.src is '', which location.replace() resolves to this page, and an
 * empty src attribute reads back as this page's URL. Either way a retry would load
 * the page inside its own frame, where this script runs again on the copy's frames.
 *
 * @param {HTMLIFrameElement} iframe The iframe.
 * @return {boolean} Whether the frame has a src to load.
 */
const hasOwnSrc = iframe => Boolean( iframe.getAttribute( 'src' ) );

/**
 * Re-navigations before giving up on a frame that never loads. A 204 resolves in a
 * retry or two; without a bound, a permanently blank embed keeps requesting for as
 * long as the page is open.
 */
const MAX_RETRIES = 10;

domReady( () => {
	const iframes = Array.from( document.querySelectorAll( '.wp-block-newspack-blocks-iframe iframe' ) );
	iframes.forEach( iframe => {
		// Don't wait for the load event: it may have fired before this script ran
		// (delayed JS). Navigate with location.replace(), since resetting src on a
		// loaded frame adds a history entry and makes readers press Back twice
		// (NPPM-3180). The src check runs on each tick rather than once here, so a
		// frame a lazy loader fills in before the first tick still gets its retries.
		let attempts = 0;
		const retry = setInterval( () => {
			if ( ! hasOwnSrc( iframe ) || ! isStillBlank( iframe ) || attempts >= MAX_RETRIES ) {
				clearInterval( retry );
				return;
			}
			attempts++;
			iframe.contentWindow.location.replace( iframe.src );
		}, 2000 );

		// Add a listener for dynamic resizing if the iframe supports it.
		window.addEventListener( 'message', function ( event ) {
			// Reject messages from untrusted origins.
			if ( event.origin !== new URL( iframe.src ).origin || iframe.contentWindow !== event.source ) {
				return;
			}

			let iframeHeight = 0;
			if ( event.data && event.data.height ) {
				if ( typeof event.data.height === 'number' ) {
					iframeHeight = event.data.height;
				} else if ( typeof event.data.height === 'string' ) {
					iframeHeight = Number( event.data.height );
				}
			}
			if ( ! isNaN( iframeHeight ) && iframeHeight > 0 ) {
				// Remove height from the iframe's parent element if needed.
				if ( iframe.parentElement && iframe.parentElement.style.height !== 'auto' ) {
					iframe.parentElement.style.height = 'auto';
				}

				// Set the new height dynamically.
				iframe.style.height = iframeHeight + 'px';
			}
		} );
	} );
} );
