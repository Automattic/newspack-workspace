/**
 * Trap focus in the modal when opened.
 * See: https://uxdesign.cc/how-to-trap-focus-inside-modal-to-make-it-ada-compliant-6a50f9a70700
 */
export function trapFocus( currentModal, iframe = false ) {
	const focusableEls = 'button, [href], input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])';
	const focusableElsAll = currentModal.querySelectorAll( focusableEls );
	// Make sure we have elements to focus on before continuing.
	if ( 0 === focusableElsAll.length ) {
		return false;
	}

	const firstFocusableEl = focusableElsAll?.[ 0 ]; // get first element to be focused inside modal
	let lastFocusableEl; // Define last element to be focused on.

	firstFocusableEl.focus();

	// If iframe, we're in the checkout and need to handle the two-page checkout differently than normal modals:
	if ( iframe ) {
		document.addEventListener( 'keydown', function ( e ) {
			const isTabPressed = e.key === 'Tab' || e.keyCode === 9;

			if ( ! isTabPressed ) {
				return;
			}

			/* eslint-disable @wordpress/no-global-active-element */
			if ( e.shiftKey ) {
				if ( document.activeElement === firstFocusableEl ) {
					const iframeBody = iframe.contentWindow.document,
						afterSuccess = iframeBody.getElementById( 'checkout-after-success' ); // Get after success button.

					// If the after success button is visible, make it the last element.
					if ( afterSuccess !== null ) {
						lastFocusableEl = afterSuccess;
					} else {
						lastFocusableEl = iframeBody.getElementById( 'checkout_cancel' );
					}

					lastFocusableEl.focus();
					e.preventDefault();
				}
			}
			/* eslint-enable @wordpress/no-global-active-element */
		} );

		// When the fake "last element" in the modal checkout has focus, move focus to the close button.
		document.getElementById( 'newspack-a11y-last-element' ).addEventListener( 'focus', () => {
			firstFocusableEl.focus();
		} );
	} else {
		lastFocusableEl = focusableElsAll[ focusableElsAll.length - 1 ]; // get last element to be focused inside modal

		document.addEventListener( 'keydown', function ( e ) {
			const isTabPressed = e.key === 'Tab' || e.keyCode === 9;
			if ( ! isTabPressed ) {
				return;
			}

			/* eslint-disable @wordpress/no-global-active-element */
			if ( e.shiftKey ) {
				if ( document.activeElement === firstFocusableEl ) {
					lastFocusableEl.focus();
					e.preventDefault();
				}
			} else if ( document.activeElement === lastFocusableEl ) {
				firstFocusableEl.focus();
				e.preventDefault();
			}
			/* eslint-enable @wordpress/no-global-active-element */
		} );
	}
}
