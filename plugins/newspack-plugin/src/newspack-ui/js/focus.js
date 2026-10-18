/**
 * Focus helpers shared by Newspack UI modals.
 */

const FOCUSABLE = 'a[href], button, iframe, input, select, textarea, [tabindex]';
const FIELDS = 'input:not([type="button"], [type="submit"], [type="reset"], [type="image"], [readonly]), select, textarea:not([readonly])';

/**
 * Whether an element is rendered and not hidden.
 *
 * @param {HTMLElement} element The element.
 * @return {boolean} Whether it's visible.
 */
export function isVisible( element ) {
	if ( typeof element.checkVisibility === 'function' ) {
		return element.checkVisibility( { visibilityProperty: true, checkVisibilityCSS: true } );
	}
	return element.getClientRects().length > 0 && 'hidden' !== window.getComputedStyle( element ).visibility;
}

/**
 * The elements Tab can reach inside a modal, in order.
 *
 * @param {HTMLElement} modal The modal element.
 * @return {HTMLElement[]} Focusable elements.
 */
export function getFocusableElements( modal ) {
	return [ ...modal.querySelectorAll( FOCUSABLE ) ].filter(
		element =>
			! element.disabled &&
			'hidden' !== element.type &&
			element.tabIndex >= 0 &&
			! element.closest( '[inert], [aria-hidden="true"]' ) &&
			isVisible( element )
	);
}

/**
 * Where focus lands when a modal opens: its first field, else its first action, else
 * its heading. Never the header's Close button.
 *
 * @param {HTMLElement} modal The modal element.
 * @return {HTMLElement|null} The element to focus.
 */
export function getInitialFocus( modal ) {
	const candidates = getFocusableElements( modal ).filter( element => ! element.closest( '.newspack-ui__modal__header' ) );
	const target = candidates.find( element => element.matches( FIELDS ) ) || candidates[ 0 ];
	if ( target ) {
		return target;
	}
	const heading = modal.querySelector( '.newspack-ui__modal__header h2' );
	if ( heading && isVisible( heading ) ) {
		heading.setAttribute( 'tabindex', '-1' );
		return heading;
	}
	return null;
}
