/**
 * Trap focus in the modal when opened.
 * See: https://uxdesign.cc/how-to-trap-focus-inside-modal-to-make-it-ada-compliant-6a50f9a70700
 */
export function trapFocus( currentModal ) {
	const focusableEls = 'button, [href], input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])';
	const visibleFocusableEls = [];
	const focusableElsAll = currentModal.querySelectorAll( focusableEls );

	// Make sure we have elements to focus on before continuing.
	if ( 0 === focusableElsAll.length ) {
		return false;
	}

	const firstFocusableEl = focusableElsAll?.[ 0 ]; // get first element to be focused inside modal

	// Get rid of elements that aren't visible:
	focusableElsAll.forEach( function ( el, index ) {
		if ( el.offsetParent !== null ) {
			visibleFocusableEls[ index ] = el;
		}
	} );

	const lastFocusableEl = visibleFocusableEls[ visibleFocusableEls.length - 1 ]; // get last element to be focused inside modal
	firstFocusableEl.focus();

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
