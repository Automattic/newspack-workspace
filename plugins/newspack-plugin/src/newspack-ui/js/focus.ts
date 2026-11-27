/**
 * Focus helpers shared by Newspack UI modals.
 */

const FOCUSABLE = 'a[href], button, iframe, input, select, textarea, [tabindex]';
// A frame counts as a field: it holds a form of its own, like modal checkout.
const FIELDS = 'input:not([type="button"], [type="submit"], [type="reset"], [type="image"], [readonly]), select, textarea:not([readonly]), iframe';

/** A focusable candidate, which may be a form control carrying `disabled`/`type`. */
type FocusableCandidate = HTMLElement & { disabled?: boolean; type?: string };

/**
 * Whether an element is rendered and not hidden.
 *
 * @param element The element.
 * @return Whether it's visible.
 */
export function isVisible( element: HTMLElement ): boolean {
	if ( typeof element.checkVisibility === 'function' ) {
		return element.checkVisibility( { visibilityProperty: true, checkVisibilityCSS: true } );
	}
	return element.getClientRects().length > 0 && 'hidden' !== window.getComputedStyle( element ).visibility;
}

/**
 * The elements Tab can reach inside a modal, in order.
 *
 * @param modal The modal element.
 * @return Focusable elements.
 */
export function getFocusableElements( modal: HTMLElement ): HTMLElement[] {
	return [ ...modal.querySelectorAll< HTMLElement >( FOCUSABLE ) ].filter(
		element =>
			! ( element as FocusableCandidate ).disabled &&
			'hidden' !== ( element as FocusableCandidate ).type &&
			element.tabIndex >= 0 &&
			! element.closest( '[inert], [aria-hidden="true"]' ) &&
			isVisible( element )
	);
}

/**
 * Make an element focusable by script only, so it can take initial focus.
 *
 * @param element The element.
 * @return The element.
 */
function makeProgrammaticallyFocusable( element: HTMLElement ): HTMLElement {
	if ( ! element.hasAttribute( 'tabindex' ) ) {
		element.setAttribute( 'tabindex', '-1' );
	}
	return element;
}

/**
 * Where focus lands when a modal or one of its steps opens: an element marked
 * data-autofocus, else its first field, else its title, else the first heading in
 * its content, else the dialog itself. An action only takes focus when marked, so
 * the first keypress can't confirm or close the modal.
 *
 * @param modal The modal element, or one of its steps.
 * @return The element to focus.
 */
export function getInitialFocus( modal: HTMLElement ): HTMLElement {
	const candidates = getFocusableElements( modal ).filter( element => ! element.closest( '.newspack-ui__modal__header' ) );
	const target = candidates.find( element => element.hasAttribute( 'data-autofocus' ) ) || candidates.find( element => element.matches( FIELDS ) );
	if ( target ) {
		return target;
	}
	const isReachable = ( element: HTMLElement ) => ! element.closest( '[inert], [aria-hidden="true"]' ) && isVisible( element );
	const heading =
		[ ...modal.querySelectorAll< HTMLElement >( '.newspack-ui__modal__header h2' ) ].find( isReachable ) ||
		[ ...modal.querySelectorAll< HTMLElement >( 'h1, h2, h3, h4, h5, h6' ) ].find( isReachable );
	return makeProgrammaticallyFocusable( heading || modal.closest< HTMLElement >( '.newspack-ui__modal-container' ) || modal );
}

/**
 * Whether focus sits on an element only a script can focus, such as the title it
 * got when the modal opened, so Tab should move on to the content.
 *
 * @param element The focused element.
 * @return Whether it's script-focusable only.
 */
export function isProgrammaticFocus( element: Element | null ): boolean {
	return !! element && ( element as HTMLElement ).tabIndex < 0 && 'IFRAME' !== element.tagName;
}
