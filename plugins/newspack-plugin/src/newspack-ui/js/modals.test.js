/**
 * The modal script runs every newspack-ui modal: a dialog opens as a modal with
 * focus on its first field, else its first action, else its heading; it keeps Tab
 * inside, closes on Escape and returns focus.
 *
 * It also marks a submit button busy only when its form actually submits, and
 * releases it when the modal opens again, since a form that keeps the page has
 * nothing else to release it. A form that handles its own submit owns its button.
 */

import { isButtonLoading, setButtonLoading } from '../../utils';

/**
 * Render a modal holding one form, and load the modal script.
 *
 * @param {boolean} interceptSubmit Whether the form handles its own submit.
 */
function renderModal( interceptSubmit ) {
	document.body.innerHTML = `
		<div id="newspack-my-account__example" class="newspack-ui__modal-container" data-state="open">
			<div class="newspack-ui__modal">
				<form class="newspack-ui__modal__content" action="https://example.test/checkout" target="checkout-frame">
					<button type="submit" class="newspack-ui__button"><span>Continue</span></button>
				</form>
			</div>
		</div>
	`;
	jest.isolateModules( () => {
		require( './modals' );
	} );
	const modal = document.getElementById( 'newspack-my-account__example' );
	const form = modal.querySelector( 'form' );
	if ( interceptSubmit ) {
		form.addEventListener( 'submit', event => event.preventDefault() );
	}
	return { modal, form, button: form.querySelector( 'button' ) };
}

const nextTask = () => new Promise( resolve => setTimeout( resolve, 0 ) );

afterEach( () => {
	jest.restoreAllMocks();
	document.body.innerHTML = '';
} );

describe( 'newspack-ui modal forms', () => {
	it( 'releases a submit button whose form handed off, when its modal opens again', async () => {
		const { modal, form, button } = renderModal( false );
		// jsdom reports the form navigation it can't perform; the page staying is the point.
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		form.requestSubmit( button );
		await nextTask();
		expect( isButtonLoading( button ) ).toBe( true );

		modal.setAttribute( 'data-state', 'closed' );
		await nextTask();
		expect( isButtonLoading( button ) ).toBe( true );

		modal.setAttribute( 'data-state', 'open' );
		await nextTask();
		expect( isButtonLoading( button ) ).toBe( false );
	} );

	it( 'keeps a button its own form marked busy when the modal reopens', async () => {
		const { modal, form, button } = renderModal( true );
		form.addEventListener( 'submit', () => setButtonLoading( button, true ) );

		form.requestSubmit( button );
		await nextTask();
		modal.setAttribute( 'data-state', 'closed' );
		await nextTask();
		modal.setAttribute( 'data-state', 'open' );
		await nextTask();

		expect( isButtonLoading( button ) ).toBe( true );
	} );

	it( 'leaves the button of a form that handles its own submit to that form', async () => {
		const { form, button } = renderModal( true );

		form.requestSubmit( button );
		await nextTask();

		expect( isButtonLoading( button ) ).toBe( false );
	} );
} );

describe( 'newspack-ui modal dialogs', () => {
	const nativeDialog = {};

	beforeAll( () => {
		[ 'showModal', 'show', 'close' ].forEach( method => {
			nativeDialog[ method ] = HTMLDialogElement.prototype[ method ];
		} );
		// Browsers focus the first focusable descendant on open, which is the header's Close button.
		HTMLDialogElement.prototype.showModal = function () {
			this.setAttribute( 'open', '' );
			this.querySelector( 'button' )?.focus();
		};
		HTMLDialogElement.prototype.show = HTMLDialogElement.prototype.showModal;
		HTMLDialogElement.prototype.close = function () {
			if ( this.hasAttribute( 'open' ) ) {
				this.removeAttribute( 'open' );
				setTimeout( () => this.dispatchEvent( new Event( 'close' ) ) );
			}
		};
		Element.prototype.checkVisibility = function () {
			return ! this.closest( '[hidden], [data-faded]' ) && 'hidden' !== window.getComputedStyle( this ).visibility;
		};
	} );

	afterAll( () => {
		Object.assign( HTMLDialogElement.prototype, nativeDialog );
		delete Element.prototype.checkVisibility;
	} );

	/**
	 * Render a trigger and a dialog modal, and load the modal script.
	 *
	 * @param {string} content The modal content markup.
	 * @param {string} extra   Markup to render after the modal.
	 */
	function renderDialog( content, extra = '' ) {
		document.body.innerHTML = `
			<button id="trigger">Open</button>
			<dialog id="newspack-my-account__example" class="newspack-ui__modal-container" data-state="closed">
				<div class="newspack-ui__modal">
					<header class="newspack-ui__modal__header">
						<h2>Example</h2>
						<button class="newspack-ui__modal__close">Close</button>
					</header>
					<section class="newspack-ui__modal__content">${ content }</section>
				</div>
			</dialog>
			${ extra }
		`;
		jest.isolateModules( () => {
			require( './modals' );
		} );
		const trigger = document.getElementById( 'trigger' );
		const modal = document.getElementById( 'newspack-my-account__example' );
		trigger.focus();
		return { trigger, modal };
	}

	const open = async modal => {
		modal.setAttribute( 'data-state', 'open' );
		await nextTask();
	};

	const pressTab = ( modal, shiftKey = false ) => {
		const event = new KeyboardEvent( 'keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true } );
		document.activeElement.dispatchEvent( event );
		return event;
	};

	it( 'opens as a modal labelled by its heading', async () => {
		const { modal } = renderDialog( '<input type="email" />' );
		await open( modal );

		expect( modal.open ).toBe( true );
		const heading = modal.querySelector( 'h2' );
		expect( modal.getAttribute( 'aria-labelledby' ) ).toBe( heading.id );
		expect( heading.id ).not.toBe( '' );
	} );

	it( 'focuses the first field, not the Close button', async () => {
		const { modal } = renderDialog( '<button type="button">Help</button><input type="hidden" /><input type="email" id="email" />' );
		await open( modal );

		expect( document.activeElement.id ).toBe( 'email' );
	} );

	it( 'focuses the first action, even a destructive one, not the Close button', async () => {
		const { modal } = renderDialog(
			'<button class="newspack-ui__button--destructive" id="delete">Delete</button><button class="newspack-ui__modal__close">Cancel</button>'
		);
		await open( modal );

		expect( document.activeElement.id ).toBe( 'delete' );
	} );

	it( 'focuses the heading when there is nothing else to focus', async () => {
		const { modal } = renderDialog( '<p>Done.</p>' );
		await open( modal );

		expect( document.activeElement ).toBe( modal.querySelector( 'h2' ) );
		expect( document.activeElement.getAttribute( 'tabindex' ) ).toBe( '-1' );
	} );

	it( 'skips hidden fields', async () => {
		const { modal } = renderDialog( '<div hidden><input type="email" /></div><input type="password" id="password" />' );
		await open( modal );

		expect( document.activeElement.id ).toBe( 'password' );
	} );

	it( 'keeps Tab and Shift+Tab inside the modal', async () => {
		const { modal } = renderDialog( '<input type="email" id="email" /><button type="submit" id="submit">Continue</button>' );
		await open( modal );
		const close = modal.querySelector( '.newspack-ui__modal__close' );

		document.getElementById( 'submit' ).focus();
		expect( pressTab( modal ).defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( close );

		expect( pressTab( modal, true ).defaultPrevented ).toBe( true );
		expect( document.activeElement.id ).toBe( 'submit' );

		document.getElementById( 'email' ).focus();
		expect( pressTab( modal ).defaultPrevented ).toBe( false );
	} );

	it( 'keeps Shift+Tab inside the modal from its heading', async () => {
		const { modal } = renderDialog( '<p>Done.</p>' );
		await open( modal );
		expect( document.activeElement ).toBe( modal.querySelector( 'h2' ) );

		expect( pressTab( modal, true ).defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( modal.querySelector( '.newspack-ui__modal__close' ) );
	} );

	it( 'closes on Escape and returns focus to the trigger', async () => {
		const { trigger, modal } = renderDialog( '<input type="email" />' );
		const onClose = jest.fn();
		modal.addEventListener( 'closeModal', onClose );
		await open( modal );

		const cancel = new Event( 'cancel', { cancelable: true } );
		modal.dispatchEvent( cancel );
		await nextTask();

		expect( cancel.defaultPrevented ).toBe( true );
		expect( modal.dataset.state ).toBe( 'closed' );
		expect( modal.open ).toBe( false );
		expect( onClose ).toHaveBeenCalledTimes( 1 );
		expect( document.activeElement ).toBe( trigger );
	} );

	it( 'stays open on Escape while a request is in flight', async () => {
		const { modal } = renderDialog( '<button type="submit">Save</button>' );
		await open( modal );
		setButtonLoading( modal.querySelector( 'button[type="submit"]' ), true );

		modal.dispatchEvent( new Event( 'cancel', { cancelable: true } ) );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'open' );
		expect( modal.open ).toBe( true );
	} );

	it( 'stays open when a file picker inside it is dismissed', async () => {
		const { modal } = renderDialog( '<input type="file" id="csv" />' );
		await open( modal );

		document.getElementById( 'csv' ).dispatchEvent( new Event( 'cancel', { bubbles: true } ) );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'open' );
		expect( modal.open ).toBe( true );
	} );

	it( 'closes when a script sets its state', async () => {
		const { trigger, modal } = renderDialog( '<input type="email" />' );
		await open( modal );
		expect( modal.open ).toBe( true );
		expect( modal.contains( document.activeElement ) ).toBe( true );

		modal.setAttribute( 'data-state', 'closed' );
		await nextTask();

		expect( modal.open ).toBe( false );
		expect( document.activeElement ).toBe( trigger );
	} );

	it( 'syncs its state when the browser closes it', async () => {
		const { modal } = renderDialog( '<input type="email" />' );
		await open( modal );

		HTMLDialogElement.prototype.close.call( modal );
		await nextTask();
		await nextTask();

		expect( modal.dataset.state ).toBe( 'closed' );
	} );

	it( 'hosts the notices while open, so they are not made inert', async () => {
		const { modal } = renderDialog( '<input type="email" />' );
		const notices = document.createElement( 'div' );
		notices.id = 'newspack-ui__notices';
		document.body.appendChild( notices );

		await open( modal );
		expect( notices.parentElement ).toBe( modal );

		modal.setAttribute( 'data-state', 'closed' );
		await nextTask();
		expect( notices.parentElement ).toBe( document.body );
	} );

	it.each( [
		[ 'after', [ 'modal', 'checkout' ] ],
		[ 'before', [ 'checkout', 'modal' ] ],
	] )( 'moves focus into a dialog that opens %s another closes, and back to the first trigger', async ( _, order ) => {
		const { trigger, modal } = renderDialog(
			'<button type="submit" id="continue">Continue</button>',
			`<dialog id="checkout" class="newspack-ui__modal-container" data-state="closed">
				<header class="newspack-ui__modal__header"><h2>Checkout</h2><button class="newspack-ui__modal__close">Close</button></header>
				<section class="newspack-ui__modal__content"><input type="email" id="checkout-email" /></section>
			</dialog>`
		);
		const checkout = document.getElementById( 'checkout' );
		await open( modal );
		document.getElementById( 'continue' ).focus();

		const states = { modal: [ modal, 'closed' ], checkout: [ checkout, 'open' ] };
		order.forEach( name => states[ name ][ 0 ].setAttribute( 'data-state', states[ name ][ 1 ] ) );
		await nextTask();
		expect( document.activeElement.id ).toBe( 'checkout-email' );

		checkout.setAttribute( 'data-state', 'closed' );
		await nextTask();
		expect( document.activeElement ).toBe( trigger );
	} );

	it( 'wraps Shift+Tab into the last control of a frame showing a page from this site', async () => {
		const { modal } = renderDialog( '<iframe id="frame"></iframe>' );
		const frame = document.getElementById( 'frame' );
		frame.contentDocument.body.innerHTML = '<input id="first" /><button id="last">Pay</button>';
		frame.contentWindow.Element.prototype.checkVisibility = Element.prototype.checkVisibility;
		await open( modal );
		modal.querySelector( '.newspack-ui__modal__close' ).focus();

		expect( pressTab( modal, true ).defaultPrevented ).toBe( true );
		expect( frame.contentDocument.activeElement.id ).toBe( 'last' );
	} );

	it( 'leaves Tab from the Close button to the browser when a frame follows it', async () => {
		const { modal } = renderDialog( '<iframe></iframe>' );
		await open( modal );
		modal.querySelector( '.newspack-ui__modal__close' ).focus();

		expect( pressTab( modal ).defaultPrevented ).toBe( false );
	} );

	it( 'stays modal for payment fields inside it, and steps down for a challenge outside it', async () => {
		const { modal } = renderDialog( '<iframe src="https://js.stripe.com/v3/elements-inner-card.html"></iframe><input type="email" />' );
		const show = jest.spyOn( HTMLDialogElement.prototype, 'show' );
		const callsOn = spy => spy.mock.contexts.filter( context => context === modal ).length;
		const nextFrame = () => new Promise( resolve => requestAnimationFrame( resolve ) );
		await open( modal );

		modal.querySelector( '.newspack-ui__modal__content' ).classList.add( 'ready' );
		await nextFrame();
		expect( callsOn( show ) ).toBe( 0 );

		document.body.insertAdjacentHTML(
			'beforeend',
			'<div style="position: fixed"><iframe src="https://js.stripe.com/v3/three-ds-2-challenge.html"></iframe></div>'
		);
		await nextFrame();
		expect( callsOn( show ) ).toBe( 1 );
	} );

	it( 'stays modal for payment fields and buttons on the page', async () => {
		const { modal } = renderDialog( '<input type="email" />', '<iframe src="https://js.stripe.com/v3/elements-inner-card.html"></iframe>' );
		const show = jest.spyOn( HTMLDialogElement.prototype, 'show' );
		await open( modal );

		modal.querySelector( '.newspack-ui__modal__content' ).classList.add( 'ready' );
		await new Promise( resolve => requestAnimationFrame( resolve ) );

		expect( show.mock.contexts.filter( context => context === modal ) ).toHaveLength( 0 );
	} );

	it( 'steps back up when a reCAPTCHA challenge finishes fading out', async () => {
		const { modal } = renderDialog( '<input type="email" />' );
		const showModal = jest.spyOn( HTMLDialogElement.prototype, 'showModal' );
		const callsOn = spy => spy.mock.contexts.filter( context => context === modal ).length;
		const nextFrame = () => new Promise( resolve => requestAnimationFrame( resolve ) );
		await open( modal );
		document.body.insertAdjacentHTML( 'beforeend', '<div id="layer"><iframe src="https://www.google.com/recaptcha/api2/bframe"></iframe></div>' );
		await nextFrame();
		expect( modal._steppedDown ).toBe( true );

		// reCAPTCHA's visibility transition ends after its last style change, so only transitionend reports it.
		const layer = document.getElementById( 'layer' );
		layer.dataset.faded = '';
		await nextFrame();
		expect( callsOn( showModal ) ).toBe( 1 );
		layer.dispatchEvent( new Event( 'transitionend', { bubbles: true } ) );
		await nextFrame();

		expect( callsOn( showModal ) ).toBe( 2 );
	} );

	it( 'steps down to non-modal while a reCAPTCHA challenge shows', async () => {
		const { modal } = renderDialog( '<input type="email" id="email" />' );
		const showModal = jest.spyOn( HTMLDialogElement.prototype, 'showModal' );
		const show = jest.spyOn( HTMLDialogElement.prototype, 'show' );
		const layer = document.createElement( 'div' );
		layer.style.visibility = 'hidden';
		layer.innerHTML = '<iframe src="https://www.google.com/recaptcha/api2/bframe"></iframe>';
		document.body.appendChild( layer );
		const callsOn = spy => spy.mock.contexts.filter( context => context === modal ).length;
		await open( modal );
		expect( callsOn( showModal ) ).toBe( 1 );

		const nextFrame = () => new Promise( resolve => requestAnimationFrame( resolve ) );
		layer.style.visibility = 'visible';
		await nextFrame();
		expect( callsOn( show ) ).toBe( 1 );
		expect( modal.dataset.state ).toBe( 'open' );
		expect( document.activeElement.id ).toBe( 'email' );

		layer.style.visibility = 'hidden';
		await nextFrame();
		expect( callsOn( showModal ) ).toBe( 2 );
		expect( document.activeElement.id ).toBe( 'email' );
	} );
} );
