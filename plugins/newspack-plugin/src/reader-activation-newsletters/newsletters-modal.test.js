/**
 * Escape is a way to close the newsletters modal, so it runs the same teardown and
 * onDismiss as the modal's own close buttons, once per open.
 */

import { openNewslettersSignupModal } from './newsletters-modal';

/**
 * Render the newsletters signup modal.
 */
function renderModal() {
	document.body.innerHTML = `
		<dialog class="newspack-ui newspack-ui__modal-container newspack-newsletters-signup-modal" data-state="closed">
			<button class="newspack-ui__modal__close">Close</button>
			<div class="newspack-newsletters-signup"><form></form></div>
		</dialog>
	`;
	return document.querySelector( '.newspack-newsletters-signup-modal' );
}

/**
 * Press Escape the way the modal script handles it: it closes the modal unless a
 * request is in flight, then the newsletters modal hears the same event.
 *
 * @param {HTMLElement} modal      The modal element.
 * @param {boolean}     allowClose Whether the modal script lets it close.
 */
function pressEscape( modal, allowClose = true ) {
	if ( allowClose ) {
		modal.setAttribute( 'data-state', 'closed' );
	}
	modal.dispatchEvent( new Event( 'cancel', { cancelable: true } ) );
}

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'newsletters signup modal', () => {
	it( 'dismisses on Escape', () => {
		const modal = renderModal();
		const onDismiss = jest.fn();
		openNewslettersSignupModal( { onDismiss } );

		pressEscape( modal );

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stays put when the modal script keeps it open', () => {
		const modal = renderModal();
		const onDismiss = jest.fn();
		openNewslettersSignupModal( { onDismiss } );

		pressEscape( modal, false );

		expect( onDismiss ).not.toHaveBeenCalled();
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'dismisses once after being opened more than once', () => {
		const modal = renderModal();
		const onDismiss = jest.fn();
		openNewslettersSignupModal( { onDismiss } );
		openNewslettersSignupModal( { onDismiss } );

		modal.querySelector( '.newspack-ui__modal__close' ).click();

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );
} );
