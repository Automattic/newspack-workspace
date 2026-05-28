/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

/**
 * Style dependencies
 */

import './view.scss';

domReady( () => {
	const iframes = Array.from( document.querySelectorAll( '.wp-block-newspack-blocks-iframe iframe' ) );
	iframes.forEach( iframe => {
		const timerId = setInterval( function () {
			iframe.src = iframe.src;
		}, 2000 );

		iframe.onload = function () {
			clearInterval( timerId );
		};

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
