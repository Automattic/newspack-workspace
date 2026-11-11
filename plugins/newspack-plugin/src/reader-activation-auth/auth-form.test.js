/**
 * A reader who registers through the auth form on a site that verifies new
 * accounts is sent through the verification modal before the flow carries on.
 * The opener's success callback carries on with a flow of its own (a redirect,
 * a checkout), so a call that comes early runs that flow over the verification
 * step, and each extra call runs it again. An opener can ask to skip the step:
 * modal checkout does, because a payment that completes at checkout verifies
 * the reader. An inline form has no opener, so it has to finish by itself once
 * the step is over.
 */

// checkout.js loads the whole reader-activation library, which would take the auth
// form's start-up callback off the newspackRAS queue and run it before a form exists.
jest.mock( '../reader-activation/checkout', () => ( { getPendingCheckout: () => false } ) );

import './auth-form';
import '../newspack-ui/js/password-toggle';
import { openAuthModal } from './auth-modal';
import { rejectOTPCode } from './otp-input';

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
		expect( document.querySelector( '.newspack-reader-auth-modal' ).hasAttribute( 'data-verification-handover' ) ).toBe( false );
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
		jest.advanceTimersByTime( 1000 );

		expect( verificationModal.getAttribute( 'data-state' ) ).toBe( 'closed' );
		expect( onSuccess ).toHaveBeenCalledTimes( 1 );
		expect( onSuccess ).toHaveBeenCalledWith( 'Registered', expect.objectContaining( { registered: 1 } ) );
	} );

	it( 'marks the auth modal from the hand-over to verification until the auth modal closes', () => {
		const { form, verificationModal } = renderAuthModal();
		const authModal = document.querySelector( '.newspack-reader-auth-modal' );
		openAuthModal( { onSuccess: jest.fn(), skipSuccess: true, skipNewslettersSignup: true } );
		expect( authModal.hasAttribute( 'data-verification-handover' ) ).toBe( false );

		form.endLoginFlow( 'Registered', 200, REGISTERED );
		expect( authModal.hasAttribute( 'data-verification-handover' ) ).toBe( true );

		closeModal( verificationModal );
		expect( authModal.style.display ).toBe( '' );
		expect( authModal.hasAttribute( 'data-verification-handover' ) ).toBe( true );

		closeModal( authModal );
		expect( authModal.hasAttribute( 'data-verification-handover' ) ).toBe( false );

		openAuthModal( { onSuccess: jest.fn(), skipSuccess: true, skipNewslettersSignup: true } );
		expect( authModal.hasAttribute( 'data-verification-handover' ) ).toBe( false );
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

const STEPS_FORM = `
	<div class="newspack-ui__modal-container newspack-reader-auth-modal" data-state="closed" aria-labelledby="auth-modal-title">
		<div class="newspack-ui__modal__header"><h2 id="auth-modal-title">Sign in</h2></div>
		<div class="newspack-reader-auth">
			<form>
				<input type="hidden" name="action" />
				<div data-action="pwd">
					<button type="button" data-back data-back-target="signin">Use a different email</button>
				</div>
				<div data-action="signin">
					<input type="email" name="npe" />
				</div>
				<div data-action="otp">
					<input name="otp_code" type="text" data-otp-length="6" />
				</div>
				<div data-action="pwd">
					<span class="newspack-ui__password-field">
						<input name="password" type="password" />
						<button type="button" class="newspack-ui__password-toggle" aria-label="Show password" data-label-show="Show password" data-label-hide="Hide password"></button>
					</span>
				</div>
				<div class="response"></div>
				<button type="submit" data-action="signin pwd otp">Continue</button>
				<button type="button" data-action="pwd" data-send-code>Email me a code instead</button>
				<button type="button" class="newspack-ui__button--google-oauth">Continue with Google</button>
				<div data-action="otp">
					<button type="button" data-resend-code>Resend code</button>
					<button type="button" data-back data-back-target="signin">Use a different email</button>
				</div>
				<div data-action="success">
					<h2 id="auth-success-title" class="success-title"></h2>
					<p class="success-description"></p>
				</div>
			</form>
			<a href="#" class="auth-callback" data-action="success">Continue</a>
		</div>
	</div>
`;

describe( 'auth form steps', () => {
	let container;

	beforeEach( () => {
		window.newspack_ras_config = {};
		window.newspack_reader_activation_labels = { signin: { title: 'Sign in' }, code_sent: 'Code sent!' };
		renderPage( STEPS_FORM );
		Object.assign( window.newspackReaderActivation, {
			getOTPHash: () => 'hash',
			getOTPTimeRemaining: () => 0,
			setOTPTimer: jest.fn(),
		} );
		container = document.querySelector( '.newspack-reader-auth' );
		global.fetch = jest.fn( () => Promise.resolve( { status: 200, json: () => Promise.resolve( {} ) } ) );
	} );

	afterEach( () => {
		delete global.fetch;
		delete window.newspackReaderActivation;
	} );

	const flush = () => new Promise( resolve => setTimeout( resolve, 0 ) );

	const requestCode = async email => {
		container.querySelector( 'input[name="npe"]' ).value = email;
		container.setFormAction( 'pwd' );
		container.querySelector( '[data-send-code]' ).click();
		await flush();
	};

	it( 'asks for a new code after the reader changes to another email address', async () => {
		await requestCode( 'a@example.test' );
		expect( container.dataset.formAction ).toBe( 'otp' );

		container.setFormAction( 'signin' );
		await requestCode( 'b@example.test' );

		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
		expect( global.fetch.mock.calls[ 1 ][ 1 ].body.get( 'npe' ) ).toBe( 'b@example.test' );
	} );

	it( 'shows the code already sent when the reader asks again for the same address', async () => {
		await requestCode( 'a@example.test' );
		container.setFormAction( 'signin' );
		await requestCode( 'a@example.test' );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		expect( container.dataset.formAction ).toBe( 'otp' );
	} );

	it( 'shows the busy state on the secondary button, not Continue, while a code is requested', async () => {
		let respond;
		global.fetch = jest.fn( () => new Promise( resolve => ( respond = resolve ) ) );
		container.querySelector( 'input[name="npe"]' ).value = 'a@example.test';
		container.setFormAction( 'pwd' );
		const sendCode = container.querySelector( '[data-send-code]' );
		const submit = container.querySelector( '[type="submit"]' );

		sendCode.click();

		expect( sendCode.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( sendCode.classList.contains( 'newspack-ui__button--loading' ) ).toBe( true );
		expect( submit.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( submit.classList.contains( 'newspack-ui__button--loading' ) ).toBe( false );

		respond( { status: 200, json: () => Promise.resolve( {} ) } );
		await flush();

		expect( sendCode.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	it( 'shows the busy state on Continue when the form is submitted', () => {
		global.fetch = jest.fn( () => new Promise( () => {} ) );
		container.querySelector( 'input[name="npe"]' ).value = 'a@example.test';
		container.querySelector( 'input[name="password"]' ).value = 'secret';
		container.setFormAction( 'pwd' );
		const sendCode = container.querySelector( '[data-send-code]' );
		const google = container.querySelector( '.newspack-ui__button--google-oauth' );
		const submit = container.querySelector( '[type="submit"]' );

		const form = container.querySelector( 'form' );
		[ 'npe', 'password', 'action' ].forEach( name =>
			Object.defineProperty( form, name, { value: form.querySelector( `[name="${ name }"]` ), configurable: true } )
		);
		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );

		expect( submit.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( sendCode.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( google.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	it( 'moves focus to the code input once the code is sent', async () => {
		await requestCode( 'a@example.test' );

		expect( document.activeElement ).toBe( container.querySelector( 'input[name="otp_code"]' ) );
	} );

	it( 'keeps focus on resend during the countdown and sends nothing', async () => {
		window.newspackReaderActivation.getOTPTimeRemaining = () => 30;
		await requestCode( 'a@example.test' );
		const resend = container.querySelector( '[data-resend-code]' );
		resend.focus();

		resend.click();
		await flush();

		expect( resend.disabled ).toBe( false );
		expect( resend.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( document.activeElement ).toBe( resend );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'moves focus to the success message, not Continue, on the success step', () => {
		// jsdom has no layout, so only hidden steps count as invisible.
		Element.prototype.checkVisibility = function () {
			return ! this.closest( '[hidden], [style*="display: none"]' );
		};
		// The stylesheet hides the header on the success step.
		document.querySelector( '.newspack-ui__modal__header' ).style.display = 'none';
		try {
			container.querySelector( 'form' ).endLoginFlow( 'Signed in', 200, { email: 'a@example.test', authenticated: 1 } );

			expect( container.dataset.formAction ).toBe( 'success' );
			expect( document.activeElement ).toBe( container.querySelector( '.success-title' ) );
			const modal = container.closest( '.newspack-ui__modal-container' );
			expect( modal.getAttribute( 'aria-labelledby' ) ).toBe( 'auth-success-title' );

			container.setFormAction( 'signin' );
			expect( modal.getAttribute( 'aria-labelledby' ) ).toBe( 'auth-modal-title' );
		} finally {
			delete Element.prototype.checkVisibility;
		}
	} );

	it( 'hides the password again when the reader leaves the password step', () => {
		container.setFormAction( 'pwd' );
		const password = container.querySelector( 'input[name="password"]' );
		const toggle = container.querySelector( '.newspack-ui__password-toggle' );
		toggle.click();
		expect( password.type ).toBe( 'text' );

		container.setFormAction( 'signin' );

		expect( password.type ).toBe( 'password' );
		expect( toggle.getAttribute( 'aria-label' ) ).toBe( 'Show password' );
	} );

	it( 'hides the controls that go back to the email step when going back closes the modal', () => {
		openAuthModal( { backButtonClosesModal: true, skipAuthenticatedCheck: true } );
		container.setFormAction( 'pwd' );
		expect( container.querySelector( '[data-action="pwd"] [data-back-target]' ).hidden ).toBe( true );
		container.setFormAction( 'otp' );
		expect( container.querySelector( '[data-action="otp"] [data-back-target]' ).hidden ).toBe( true );
	} );

	it( 'keeps the controls that go back to the email step when they lead there', () => {
		openAuthModal( { skipAuthenticatedCheck: true } );
		container.setFormAction( 'pwd' );
		expect( container.querySelector( '[data-action="pwd"] [data-back-target]' ).hidden ).toBe( false );
		container.setFormAction( 'otp' );
		expect( container.querySelector( '[data-action="otp"] [data-back-target]' ).hidden ).toBe( false );
	} );

	it( 'clears a rejected code when the step changes', () => {
		const code = container.querySelector( 'input[name="otp_code"]' );
		const submit = container.querySelector( '[type="submit"]' );
		container.setFormAction( 'otp' );
		code.value = '482917';
		code.dispatchEvent( new Event( 'input' ) );
		rejectOTPCode( code, [ submit ], container.querySelector( '.response' ) );

		container.setFormAction( 'signin' );

		expect( submit.disabled ).toBe( false );
		expect( code.hasAttribute( 'aria-invalid' ) ).toBe( false );
	} );
} );
