/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

/**
 * Style dependencies
 */

import './view.scss';

/**
 * Whether the frame still shows its initial about:blank, so nothing has committed.
 * Google Docs Viewer intermittently answers HTTP 204, which leaves the frame blank
 * with no load event. That is the case the retry exists for. contentDocument is
 * null once a cross-origin document commits, and for a detached frame.
 *
 * @param {HTMLIFrameElement} iframe The iframe.
 * @return {boolean} Whether nothing has committed yet.
 */
const isStillBlank = iframe => {
	const doc = iframe.contentDocument;
	return doc !== null && doc.URL === 'about:blank';
};

domReady( () => {
	const iframes = Array.from( document.querySelectorAll( '.wp-block-newspack-blocks-iframe iframe' ) );
	iframes.forEach( iframe => {
		// Retry only while nothing has committed, without relying on the load event,
		// which may have fired before this script ran (delayed JS). Navigate with
		// location.replace(), since resetting src on a loaded frame adds a history
		// entry and makes readers press Back twice (NPPM-3180).
		const retry = setInterval( () => {
			if ( ! isStillBlank( iframe ) ) {
				clearInterval( retry );
				return;
			}
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
