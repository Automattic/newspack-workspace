/**
 * A reader who registers through the auth modal on a site that verifies new
 * accounts is sent through the verification modal before the flow that asked
 * them to sign in carries on. Modal checkout is the caller these tests stand in
 * for: its success callback submits the checkout, so a call that comes early
 * opens the checkout over the verification step, and each extra call submits the
 * checkout again for a purchase the reader has already made.
 */

// checkout.js loads the whole reader-activation library, which would take the auth
// form's start-up callback off the newspackRAS queue and run it before a form exists.
jest.mock( '../reader-activation/checkout', () => ( { getPendingCheckout: () => false } ) );

import './auth-form';
import { openAuthModal } from './auth-modal';

const VERIFICATION_MODAL_ID = 'newspack-my-account__newspack-reader-verification';

/**
 * What the register request resolves with for a new, unverified reader.
 */
const REGISTERED = {
	email: 'reader@example.test',
	authenticated: 1,
	registered: 1,
	verified: false,
	verification_nonce: 'nonce-123',
};

/**
 * A stand-in for the reader-activation library with the part the auth form
 * leans on here: every change to the reader is announced to its listeners.
 *
 * @return {Object} The reader-activation stand-in.
 */
function createReaderActivation() {
	const listeners = [];
	let reader = { email: '', authenticated: false };
	const update = changes => {
		reader = { ...reader, ...changes };
		listeners.forEach( listener => listener() );
	};
	return {
		on: ( event, listener ) => 'reader' === event && listeners.push( listener ),
		getReader: () => reader,
		setReaderEmail: email => update( { email } ),
		setAuthenticated: authenticated => update( { authenticated } ),
		dispatchActivity: jest.fn(),
		hasAuthLink: () => false,
		getOTPHash: () => null,
		getOTPTimeRemaining: () => 0,
		setOTPTimer: jest.fn(),
	};
}

/**
 * Render the auth modal and the verification modal, then start the auth form.
 *
 * @return {Object} The form and the verification modal.
 */
function renderModals() {
	document.body.innerHTML = `
		<div class="newspack-ui__modal-container newspack-reader-auth-modal" data-state="closed">
			<h2></h2>
			<div class="newspack-reader-auth">
				<form>
					<input type="hidden" name="action" value="signin" />
					<input type="email" name="npe" />
					<p class="response"></p>
					<button type="submit">Continue</button>
				</form>
			</div>
		</div>
		<div id="${ VERIFICATION_MODAL_ID }" class="newspack-ui__modal-container" data-state="closed">
			<p>We'll send a verification code to <strong class="email-address"></strong>.</p>
			<p data-error-target role="status" hidden></p>
			<button type="button" data-send-otp>Send code</button>
		</div>
	`;
	window.newspackReaderActivation = createReaderActivation();
	window.newspackRAS.forEach( callback => callback( window.newspackReaderActivation ) );
	return {
		form: document.querySelector( '.newspack-reader-auth form' ),
		verificationModal: document.getElementById( VERIFICATION_MODAL_ID ),
	};
}

/**
 * Open the auth modal the way modal checkout does for a reader who has to sign in first.
 *
 * @param {Function} onSuccess Called when the reader is through the auth flow.
 */
function openAuthModalForCheckout( onSuccess ) {
	openAuthModal( { onSuccess, skipSuccess: true, skipNewslettersSignup: true, closeOnSuccess: true } );
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

/**
 * Close every modal on the page, as modal checkout does once a purchase is complete.
 */
function closeEveryModal() {
	document.querySelectorAll( '.newspack-ui__modal-container' ).forEach( closeModal );
}

describe( 'auth form with post-registration verification', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		window.newspack_ras_config = { verify_new_reader_accounts: true, verification_url: 'https://example.test/verify' };
		window.newspack_reader_activation_labels = {
			signin: { title: 'Sign in' },
			register: { title: 'Create an account' },
		};
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.newspackReaderActivation;
	} );

	it( 'waits for the verification step before reporting success', () => {
		const { form, verificationModal } = renderModals();
		const onSuccess = jest.fn();
		openAuthModalForCheckout( onSuccess );

		form.endLoginFlow( 'Registered', 200, REGISTERED );
		expect( verificationModal.getAttribute( 'data-state' ) ).toBe( 'open' );

		// The form resolves the flow by itself one second after a reader signs in.
		jest.advanceTimersByTime( 1000 );

		expect( onSuccess ).not.toHaveBeenCalled();
	} );

	it( 'reports success once, as a registration, when the reader dismisses the verification step', () => {
		const { form, verificationModal } = renderModals();
		const onSuccess = jest.fn();
		openAuthModalForCheckout( onSuccess );
		form.endLoginFlow( 'Registered', 200, REGISTERED );
		jest.advanceTimersByTime( 1000 );

		closeModal( verificationModal );

		expect( onSuccess ).toHaveBeenCalledTimes( 1 );
		expect( onSuccess ).toHaveBeenCalledWith( 'Registered', expect.objectContaining( { registered: 1 } ) );
	} );

	it( 'does not report success again when every modal is closed after the purchase', () => {
		const { form, verificationModal } = renderModals();
		const onSuccess = jest.fn();
		openAuthModalForCheckout( onSuccess );
		form.endLoginFlow( 'Registered', 200, REGISTERED );
		jest.advanceTimersByTime( 1000 );
		closeModal( verificationModal );

		closeEveryModal();
		closeEveryModal();

		expect( onSuccess ).toHaveBeenCalledTimes( 1 );
	} );
} );
