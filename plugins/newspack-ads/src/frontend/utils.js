/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 *
 * @return {void}
 */
export function domReady( callback ) {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if (
		document.readyState === 'complete' || // DOMContentLoaded + Images/Styles/etc loaded, so we call directly.
		document.readyState === 'interactive' // DOMContentLoaded fires at this point, so we call directly.
	) {
		return void callback();
	}
	// DOMContentLoaded has not fired yet, delay callback until then.
	document.addEventListener( 'DOMContentLoaded', callback );
}

/**
 * Check if the site has a sticky header.
 *
 * @return {boolean} Whether the site has a sticky header.
 */
export function hasStickyHeader() {
	return document.body.classList.contains( 'h-stk' );
}

/**
 * Debounce a function.
 *
 * @param {Function} fn    The function to debounce.
 * @param {number}   delay The delay in milliseconds.
 *
 * @return {Function} The debounced function.
 */
export function debounce( fn, delay ) {
	let timeoutId;
	return function ( ...args ) {
		clearTimeout( timeoutId );
		timeoutId = setTimeout( () => fn.apply( this, args ), delay );
	};
}

/**
 * Detect whether an element is colliding with any of the given elements.
 *
 * @param {Element}  element  The element to detect collisions for.
 * @param {NodeList} elements The elements to detect collisions with.
 *
 * @return {boolean} Whether the element is colliding with any of the given elements.
 */
export function elementCollides( element, elements ) {
	const rect = element.getBoundingClientRect();
	// Out of screen bounds.
	if ( rect.left < 0 || window.innerWidth < rect.right || rect.top < 0 || window.innerHeight < rect.bottom ) {
		return true;
	}
	return [ ...elements ].some( el => {
		const targetRect = el.getBoundingClientRect();
		return ! ( rect.right <= targetRect.left || rect.left >= targetRect.right || rect.bottom <= targetRect.top || rect.top >= targetRect.bottom );
	} );
}
