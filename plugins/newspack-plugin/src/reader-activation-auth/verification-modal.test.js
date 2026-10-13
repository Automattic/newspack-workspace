/**
 * The verification modal hands control back to its opener once: a reader who
 * dismisses it carries on with whatever was waiting on them, such as a checkout.
 * The modal element stays in the page afterwards and is closed again whenever
 * another script closes every modal at once, so these pin that a dismissal is
 * reported for the open it belongs to and never a second time.
 */

import { openVerificationModal } from './verification-modal';

const MODAL_ID = 'newspack-my-account__newspack-reader-verification';

/**
 * Render the modal in the shape Reader_Activation::render_verification_modal() prints.
 *
 * @return {HTMLElement} The modal element.
 */
function renderModal() {
	document.body.innerHTML = `
		<div id="${ MODAL_ID }" class="newspack-ui__modal-container" data-state="closed">
			<p>We'll send a verification code to <strong class="email-address"></strong>.</p>
			<p data-error-target role="status" hidden></p>
			<button type="button" data-send-otp>Send code</button>
		</div>
	`;
	return document.getElementById( MODAL_ID );
}

/**
 * Close a modal the way the newspack-ui modals script reports it: the state
 * attribute is written, then a closeModal event follows. The event follows every
 * write of "closed", including one to a modal that was already closed.
 *
 * @param {HTMLElement} modal The modal element.
 */
function closeModal( modal ) {
	modal.setAttribute( 'data-state', 'closed' );
	modal.dispatchEvent( new CustomEvent( 'closeModal' ) );
}

describe( 'openVerificationModal() dismissal', () => {
	it( 'reports a dismissal when the reader closes the modal', () => {
		const modal = renderModal();
		const onDismiss = jest.fn();
		openVerificationModal( { email: 'reader@example.test', verificationNonce: 'nonce-123', onDismiss } );
		expect( modal.getAttribute( 'data-state' ) ).toBe( 'open' );

		closeModal( modal );

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not report the dismissal again when the closed modal is closed a second time', () => {
		const modal = renderModal();
		const onDismiss = jest.fn();
		openVerificationModal( { email: 'reader@example.test', verificationNonce: 'nonce-123', onDismiss } );

		closeModal( modal );
		closeModal( modal );

		expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports a later dismissal to the open it belongs to', () => {
		const modal = renderModal();
		const firstDismiss = jest.fn();
		const secondDismiss = jest.fn();
		openVerificationModal( { email: 'reader@example.test', verificationNonce: 'nonce-123', onDismiss: firstDismiss } );
		closeModal( modal );

		openVerificationModal( { email: 'reader@example.test', verificationNonce: 'nonce-456', onDismiss: secondDismiss } );
		closeModal( modal );

		expect( firstDismiss ).toHaveBeenCalledTimes( 1 );
		expect( secondDismiss ).toHaveBeenCalledTimes( 1 );
	} );
} );
