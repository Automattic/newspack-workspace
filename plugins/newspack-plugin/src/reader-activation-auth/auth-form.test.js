/**
 * A reader who registers through the auth form on a site that verifies new
 * accounts is sent through the verification modal before the flow carries on.
 * The opener's success callback carries on with a flow of its own (a redirect,
 * a checkout), so a call that comes early runs that flow over the verification
 * step, and each extra call runs it again. An opener can ask to skip the step:
 * modal checkout does, because a completed payment verifies the reader. An
 * inline form has no opener, so it has to finish by itself once the step is over.
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

const AUTH_FORM = `
	<div class="newspack-reader-auth">
		<form>
			<input type="hidden" name="action" value="signin" />
			<input type="email" name="npe" />
			<p class="response"></p>
			<button type="submit">Continue</button>
			<div data-action="success">
				<p class="success-title"></p>
				<p class="success-description"></p>
			</div>
		</form>
	</div>
`;

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
	};
}

/**
 * Render a page holding one auth form and the verification modal, then start the form.
 *
 * @param {string} authFormMarkup The auth form, wrapped the way the page prints it.
 *
 * @return {Object} The form, its action input and the verification modal.
 */
function renderPage( authFormMarkup ) {
	document.body.innerHTML = `
		${ authFormMarkup }
		<div id="${ VERIFICATION_MODAL_ID }" class="newspack-ui__modal-container" data-state="closed">
			<p>We'll send a verification code to <strong class="email-address"></strong>.</p>
			<p data-error-target role="status" hidden></p>
			<button type="button" data-send-otp>Send code</button>
		</div>
	`;
	window.newspackReaderActivation = createReaderActivation();
	window.newspackRAS.forEach( callback => callback( window.newspackReaderActivation ) );
	const form = document.querySelector( '.newspack-reader-auth form' );
	return {
		form,
		formStep: form.querySelector( 'input[name="action"]' ),
		verificationModal: document.getElementById( VERIFICATION_MODAL_ID ),
	};
}

/**
 * Render the form inside the auth modal, as every page prints it in the footer.
 *
 * @return {Object} The form, its action input and the verification modal.
 */
function renderAuthModal() {
	return renderPage( `
		<div class="newspack-ui__modal-container newspack-reader-auth-modal" data-state="closed">
			<h2></h2>
			${ AUTH_FORM }
		</div>
	` );
}

/**
 * Open the auth modal for an opener that carries on with its own flow on success.
 *
 * @param {Function} onSuccess Called when the reader is through the auth flow.
 * @param {Object}   config    Extra options for the opener.
 */
function openAuthModalWithCallback( onSuccess, config = {} ) {
	openAuthModal( { onSuccess, skipSuccess: true, skipNewslettersSignup: true, closeOnSuccess: true, ...config } );
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
		window.newspack_ras_config = { verify_new_reader_accounts: true };
		window.newspack_reader_activation_labels = { signin: { title: 'Sign in' } };
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.newspackReaderActivation;
	} );

	it( 'waits for the verification step before reporting success', () => {
		const { form, verificationModal } = renderAuthModal();
		const onSuccess = jest.fn();
		openAuthModalWithCallback( onSuccess );

		form.endLoginFlow( 'Registered', 200, REGISTERED );
		expect( verificationModal.getAttribute( 'data-state' ) ).toBe( 'open' );

		// The form resolves the flow by itself one second after a reader signs in.
		jest.advanceTimersByTime( 1000 );

		expect( onSuccess ).not.toHaveBeenCalled();
	} );

	it( 'reports success once, as a registration, when the reader dismisses the verification step', () => {
		const { form, verificationModal } = renderAuthModal();
		const onSuccess = jest.fn();
		openAuthModalWithCallback( onSuccess );
		form.endLoginFlow( 'Registered', 200, REGISTERED );
		jest.advanceTimersByTime( 1000 );

		closeModal( verificationModal );

		expect( onSuccess ).toHaveBeenCalledTimes( 1 );
		expect( onSuccess ).toHaveBeenCalledWith( 'Registered', expect.objectContaining( { registered: 1 } ) );
	} );

	it( 'does not report success again when every modal on the page is closed later', () => {
		const { form, verificationModal } = renderAuthModal();
		const onSuccess = jest.fn();
		openAuthModalWithCallback( onSuccess );
		form.endLoginFlow( 'Registered', 200, REGISTERED );
		jest.advanceTimersByTime( 1000 );
		closeModal( verificationModal );

		closeEveryModal();
		closeEveryModal();

		expect( onSuccess ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'skips the verification step for an opener that asks to, and reports the registration at once', () => {
		const { form, verificationModal } = renderAuthModal();
		const onSuccess = jest.fn();
		openAuthModalWithCallback( onSuccess, { skipVerification: true } );

		form.endLoginFlow( 'Registered', 200, REGISTERED );

		expect( verificationModal.getAttribute( 'data-state' ) ).toBe( 'closed' );
		expect( onSuccess ).toHaveBeenCalledTimes( 1 );
		expect( onSuccess ).toHaveBeenCalledWith( 'Registered', expect.objectContaining( { registered: 1 } ) );
	} );

	it( 'moves a form with no opener to its success step when the reader dismisses the verification step', () => {
		// The logged-out My Account page prints the form inline, outside the auth modal.
		const { form, formStep, verificationModal } = renderPage( AUTH_FORM );
		form.endLoginFlow( 'Registered', 200, REGISTERED );
		jest.advanceTimersByTime( 1000 );

		closeModal( verificationModal );

		expect( formStep.value ).toBe( 'success' );
	} );
} );
