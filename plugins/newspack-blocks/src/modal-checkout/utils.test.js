/**
 * Tests for modal-checkout utils.
 */

import {
	afterDeferredScripts,
	afterModalExit,
	getCheckoutData,
	getFirstField,
	getInitialFocus,
	getPaymentFocus,
	hasFrameProcessingScreen,
	isPaymentChallengeShown,
	isSameDocumentRedirect,
	mountFrameProcessingScreen,
	preventDisabledCloseClicks,
	removeFrameProcessingScreen,
	restorePaymentFocus,
} from './utils';

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'afterDeferredScripts()', () => {
	let readyState;
	let navigationEntries;

	beforeEach( () => {
		readyState = 'complete';
		navigationEntries = [];
		Object.defineProperty( document, 'readyState', { configurable: true, get: () => readyState } );
		Object.defineProperty( window.performance, 'getEntriesByType', {
			configurable: true,
			value: type => ( type === 'navigation' ? navigationEntries : [] ),
		} );
	} );

	afterEach( () => {
		delete document.readyState;
		delete window.performance.getEntriesByType;
	} );

	// Images still loading keep readyState at `interactive` after DOMContentLoaded,
	// so a bundle arriving then must not wait for an event that will not repeat.
	// Missing navigation timing leaves nothing to consult, and the trigger
	// degrades to running immediately rather than to never running.
	it.each( [
		[ 'the document is complete', 'complete', [] ],
		[ 'DOMContentLoaded already fired and images keep the document interactive', 'interactive', [ { domContentLoadedEventStart: 850 } ] ],
		[ 'navigation timing is unavailable', 'interactive', [] ],
	] )( 'runs at once when %s', ( _, state, entries ) => {
		readyState = state;
		navigationEntries = entries;
		const callback = jest.fn();

		afterDeferredScripts( callback );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits for DOMContentLoaded, once, when parsing has ended but the event has not fired', () => {
		// The window an async bundle lands in when it beats the deferred scripts:
		// readyState is already `interactive`, DOMContentLoaded is still pending.
		readyState = 'interactive';
		navigationEntries = [ { domContentLoadedEventStart: 0 } ];
		const callback = jest.fn();

		afterDeferredScripts( callback );
		expect( callback ).not.toHaveBeenCalled();

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits for DOMContentLoaded while the document is still loading, without consulting timing', () => {
		// `loading` alone says the event is ahead; the empty navigation timing
		// must not be read as permission to run.
		readyState = 'loading';
		navigationEntries = [];
		const callback = jest.fn();

		afterDeferredScripts( callback );
		expect( callback ).not.toHaveBeenCalled();

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );
} );

/**
 * Build a checkout-button-style form: a `quantity` hidden input (as view.php
 * emits when a block's default seat count is above 1) plus a `data-checkout`
 * attribute carrying the server-computed checkout data.
 *
 * @param {Object} checkoutData Object to JSON-encode into data-checkout.
 * @return {HTMLFormElement} The form element.
 */
const formWithQuantityField = checkoutData => {
	document.body.innerHTML = `<form data-checkout='${ JSON.stringify(
		checkoutData
	) }'><input type="hidden" name="quantity" value="3"><button type="submit">Buy</button></form>`;
	return document.body.querySelector( 'form' );
};

describe( 'getCheckoutData()', () => {
	it( 'carries the quantity from a hidden form field when data-checkout omits it', () => {
		// Mirrors a product source: Checkout_Data::get_checkout_data() omits
		// `quantity` entirely for a bare product, so the only value with a seat
		// count to report is the DOM's own hidden `quantity` input.
		const form = formWithQuantityField( { product_id: '42', amount: '10' } );

		const data = getCheckoutData( form );

		expect( data.quantity ).toBe( '3' );
	} );

	it( 'lets data-checkout win when it does carry a quantity (cart/order sources)', () => {
		// Mirrors a cart or order source: Checkout_Data::get_checkout_data() sets
		// a real `quantity` there, and the merge order in getCheckoutData() must
		// keep letting that JSON value win over whatever the DOM field says —
		// this test exists to pin that merge order, not to change it.
		const form = formWithQuantityField( { product_id: '42', amount: '10', quantity: 5 } );

		const data = getCheckoutData( form );

		expect( data.quantity ).toBe( 5 );
	} );
} );

describe( 'focus in a checkout screen', () => {
	// jsdom lays nothing out, so an element counts as rendered unless it, or an ancestor, is hidden.
	beforeAll( () => {
		jest.spyOn( Element.prototype, 'getClientRects' ).mockImplementation( function () {
			return this.closest( '[hidden]' ) ? [] : [ {} ];
		} );
	} );

	afterAll( () => {
		jest.restoreAllMocks();
	} );

	it( 'lands on the first field, past links and buttons before it', () => {
		document.body.innerHTML = '<a href="#terms">Terms</a><button type="button">Edit</button><input type="hidden" /><input id="name" />';

		expect( getInitialFocus( document.body ).id ).toBe( 'name' );
	} );

	it( 'lands on the first action when there are no fields', () => {
		document.body.innerHTML = '<p>Thank you.</p><input type="submit" value="Go" id="go" /><button id="continue">Continue</button>';

		expect( getInitialFocus( document.body ).id ).toBe( 'go' );
	} );

	it( 'skips fields that are hidden, disabled or out of the tab order', () => {
		document.body.innerHTML =
			'<div hidden><input /></div><input disabled /><select tabindex="-1"></select><input readonly /><textarea id="note"></textarea>';

		expect( getInitialFocus( document.body ).id ).toBe( 'note' );
	} );

	it( 'finds nothing on a screen with nothing to reach', () => {
		document.body.innerHTML = '<p>Processing.</p>';

		expect( getInitialFocus( document.body ) ).toBeNull();
	} );

	it( 'finds no field to open a dialog on when it only has actions', () => {
		document.body.innerHTML = '<button id="continue">Continue</button><input type="submit" value="Go" />';

		expect( getFirstField( document.body ) ).toBeNull();
	} );
} );

describe( 'isPaymentChallengeShown()', () => {
	beforeAll( () => {
		jest.spyOn( Element.prototype, 'getClientRects' ).mockImplementation( function () {
			return this.closest( '[hidden]' ) ? [] : [ {} ];
		} );
	} );

	afterAll( () => {
		jest.restoreAllMocks();
	} );

	it( 'ignores card fields in the form', () => {
		document.body.innerHTML = '<form><iframe src="https://js.stripe.com/v3/elements-inner-card.html"></iframe></form>';

		expect( isPaymentChallengeShown( document ) ).toBe( false );
	} );

	it( 'ignores hidden frames fixed over the page', () => {
		document.body.innerHTML =
			'<div style="position: fixed; visibility: hidden"><iframe src="https://js.stripe.com/v3/controller.html"></iframe></div>';

		expect( isPaymentChallengeShown( document ) ).toBe( false );
	} );

	it( 'sees a 3D Secure challenge fixed over the page', () => {
		document.body.innerHTML = '<div style="position: fixed"><iframe src="https://js.stripe.com/v3/three-ds-2-challenge.html"></iframe></div>';

		expect( isPaymentChallengeShown( document ) ).toBe( true );
	} );
} );

describe( 'isSameDocumentRedirect()', () => {
	const checkoutUrl = 'https://example.test/checkout/?modal_checkout=1';

	// A gateway that needs the shopper (3D Secure, a voucher, a wallet QR code)
	// answers the place-order request with a fragment on the checkout page
	// itself, and its own script takes over in place.
	it.each( [
		[ 'a Stripe confirm hash', '#wc-stripe-confirm-pi:82:pi_123_secret_456:abc123' ],
		[ 'a Stripe setup-intent hash', '#wc-stripe-confirm-si:82:seti_123_secret_456:abc123' ],
		[ 'a Stripe voucher hash', '#wc-stripe-voucher-82:boleto:pi_123_secret_456:https%3A%2F%2Fexample.test' ],
		[ 'a WooPayments confirm hash', '#wcpay-confirm-pi:82:pi_123_secret_456:abc123' ],
		[ 'the full checkout URL with a fragment', `${ checkoutUrl }#confirm-pi-pi_123_secret_456:https%3A%2F%2Fexample.test` ],
	] )( 'is true for %s', ( _, redirect ) => {
		expect( isSameDocumentRedirect( redirect, checkoutUrl ) ).toBe( true );
	} );

	// Anything that leaves the checkout page is an ordinary redirect, not a gateway step.
	it.each( [
		[ 'the order-received page', 'https://example.test/checkout/order-received/80/?key=wc_order_abc&modal_checkout=1' ],
		[ 'an off-site authentication page', 'https://hooks.stripe.test/redirect/authenticate/src_123' ],
		[ 'the checkout page with a different query and a fragment', 'https://example.test/checkout/?modal_checkout=1&retry=1#step' ],
		[ 'another page on the same site with a fragment', 'https://example.test/other/?modal_checkout=1#step' ],
		[ 'the checkout path on another site with a fragment', 'https://elsewhere.test/checkout/?modal_checkout=1#step' ],
		[ 'the checkout page without a fragment', checkoutUrl ],
		[ 'an empty fragment', '#' ],
		[ 'an empty string', '' ],
		[ 'undefined', undefined ],
		[ 'a non-string value', { redirect: '#wc-stripe-confirm-pi:1:2:3' } ],
	] )( 'is false for %s', ( _, redirect ) => {
		expect( isSameDocumentRedirect( redirect, checkoutUrl ) ).toBe( false );
	} );
} );

describe( 'mountFrameProcessingScreen()', () => {
	let frameDocument;

	beforeEach( () => {
		frameDocument = document.implementation.createHTMLDocument( 'checkout' );
	} );

	it( 'mounts the processing screen in the checkout document with the live message', () => {
		const message = document.createElement( 'p' );
		message.textContent = 'Finalizing transaction...';

		const screen = mountFrameProcessingScreen( frameDocument, 'newspack-ui__spinner', message );

		expect( screen.ownerDocument ).toBe( frameDocument );
		expect( screen.parentNode ).toBe( frameDocument.body );
		expect( screen.classList.contains( 'newspack-ui__spinner' ) ).toBe( true );
		expect( screen.contains( message ) ).toBe( true );
		// The message keeps updating after the move, so later text shows here too.
		message.textContent = 'Verifying details...';
		expect( screen.textContent ).toBe( 'Verifying details...' );
	} );

	it( 'reuses one screen per document when mounted again', () => {
		const first = mountFrameProcessingScreen( frameDocument, 'newspack-ui__spinner', document.createElement( 'p' ) );
		const second = mountFrameProcessingScreen( frameDocument, 'newspack-ui__spinner', document.createElement( 'p' ) );

		expect( second ).toBe( first );
		expect( frameDocument.querySelectorAll( '.newspack-ui__spinner' ) ).toHaveLength( 1 );
		// A second mount replaces the message rather than stacking a duplicate under it.
		expect( first.querySelectorAll( 'p' ) ).toHaveLength( 1 );
	} );

	it( 'does nothing without a document to mount into', () => {
		expect( mountFrameProcessingScreen( null, 'newspack-ui__spinner', document.createElement( 'p' ) ) ).toBeNull();
	} );

	it( 'is removed by removeFrameProcessingScreen()', () => {
		mountFrameProcessingScreen( frameDocument, 'newspack-ui__spinner', document.createElement( 'p' ) );

		removeFrameProcessingScreen( frameDocument );

		expect( frameDocument.querySelector( '.newspack-ui__spinner' ) ).toBeNull();
		expect( () => removeFrameProcessingScreen( null ) ).not.toThrow();
	} );

	it( 'is reported by hasFrameProcessingScreen() while mounted', () => {
		expect( hasFrameProcessingScreen( frameDocument ) ).toBe( false );
		mountFrameProcessingScreen( frameDocument, 'newspack-ui__spinner', document.createElement( 'p' ) );
		expect( hasFrameProcessingScreen( frameDocument ) ).toBe( true );
		removeFrameProcessingScreen( frameDocument );
		expect( hasFrameProcessingScreen( frameDocument ) ).toBe( false );
		expect( hasFrameProcessingScreen( null ) ).toBe( false );
	} );
} );

describe( 'preventDisabledCloseClicks()', () => {
	// Other scripts (newspack-ui's shared modal handler) close the modal from their own
	// listener on the close button, so the guard must stop the click before it gets there.
	let modal, button, otherCloseHandler;

	beforeEach( () => {
		document.body.innerHTML = '<div id="modal"><header><button class="newspack-ui__modal__close">Close</button></header></div>';
		modal = document.getElementById( 'modal' );
		button = modal.querySelector( 'button' );
		otherCloseHandler = jest.fn();
		button.addEventListener( 'click', otherCloseHandler );
		preventDisabledCloseClicks( modal, '.newspack-ui__modal__close' );
	} );

	it( 'keeps a click on a disabled close button from reaching any close handler', () => {
		button.setAttribute( 'aria-disabled', 'true' );

		button.click();

		expect( otherCloseHandler ).not.toHaveBeenCalled();
	} );

	it( 'lets the click through once the close button is enabled again', () => {
		button.setAttribute( 'aria-disabled', 'true' );
		button.removeAttribute( 'aria-disabled' );

		button.click();

		expect( otherCloseHandler ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'payment focus across a checkout refresh', () => {
	const renderPayment = ( { checked = 'stripe', coverFees = true } = {} ) => {
		document.body.innerHTML = `
			<input id="billing_email" type="email" />
			<div id="payment" class="woocommerce-checkout-payment">
				<ul>
					<li><input id="payment_method_stripe" type="radio" name="payment_method" value="stripe" ${ 'stripe' === checked ? 'checked' : '' } /></li>
					<li><input id="payment_method_ppcp" type="radio" name="payment_method" value="ppcp" ${ 'ppcp' === checked ? 'checked' : '' } /></li>
				</ul>
				${ coverFees ? '<input id="newspack-wc-pay-fees_stripe" type="checkbox" name="newspack-wc-pay-fees" />' : '' }
			</div>`;
	};

	const refresh = options => {
		document.activeElement?.blur();
		renderPayment( options );
	};

	it( 'notes nothing when focus is outside the payment section', () => {
		renderPayment();
		document.getElementById( 'billing_email' ).focus();

		expect( getPaymentFocus( document ) ).toBeNull();
	} );

	it( 'returns focus to the checked payment method after arrowing to it', () => {
		renderPayment();
		document.getElementById( 'payment_method_ppcp' ).focus();
		const focused = getPaymentFocus( document );
		expect( focused ).toEqual( { id: 'payment_method_ppcp', name: 'payment_method' } );

		refresh( { checked: 'ppcp' } );
		restorePaymentFocus( document, focused );

		expect( document.activeElement.id ).toBe( 'payment_method_ppcp' );
	} );

	it( 'returns focus to a toggled checkbox by its id', () => {
		renderPayment();
		document.getElementById( 'newspack-wc-pay-fees_stripe' ).focus();
		const focused = getPaymentFocus( document );
		expect( focused ).toEqual( { id: 'newspack-wc-pay-fees_stripe', name: '' } );

		refresh();
		restorePaymentFocus( document, focused );

		expect( document.activeElement.id ).toBe( 'newspack-wc-pay-fees_stripe' );
	} );

	it( 'falls back to the checked payment method when the control is gone', () => {
		renderPayment();
		document.getElementById( 'newspack-wc-pay-fees_stripe' ).focus();
		const focused = getPaymentFocus( document );

		refresh( { coverFees: false } );
		restorePaymentFocus( document, focused );

		expect( document.activeElement.id ).toBe( 'payment_method_stripe' );
	} );

	it( 'leaves focus alone when it has already moved off the body', () => {
		renderPayment();
		document.getElementById( 'newspack-wc-pay-fees_stripe' ).focus();
		const focused = getPaymentFocus( document );

		refresh();
		document.getElementById( 'billing_email' ).focus();

		expect( restorePaymentFocus( document, focused ) ).toBeNull();
		expect( document.activeElement.id ).toBe( 'billing_email' );
	} );
} );

describe( 'afterModalExit()', () => {
	/**
	 * A transition whose end the test decides.
	 *
	 * @param {number} endTime When it ends, in milliseconds.
	 * @return {Object} The transition, and a function that ends it.
	 */
	const createTransition = ( endTime = 400 ) => {
		let end;
		const finished = new Promise( resolve => {
			end = resolve;
		} );
		return { effect: { getComputedTiming: () => ( { endTime } ) }, finished, end };
	};

	const flushPromises = () => new Promise( resolve => setTimeout( resolve, 0 ) );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'runs straight away when nothing is transitioning', () => {
		const modal = document.createElement( 'div' );
		modal.getAnimations = () => [];
		const callback = jest.fn();

		afterModalExit( modal, callback );

		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs straight away without animation support', () => {
		const callback = jest.fn();

		afterModalExit( document.createElement( 'div' ), callback );

		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits for every transition to finish', async () => {
		const overlay = createTransition( 250 );
		const visibility = createTransition( 400 );
		const modal = document.createElement( 'div' );
		modal.getAnimations = () => [ overlay, visibility ];
		const callback = jest.fn();

		afterModalExit( modal, callback );
		overlay.end();
		await flushPromises();
		expect( callback ).not.toHaveBeenCalled();

		visibility.end();
		await flushPromises();
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not wait for a looping animation', () => {
		const modal = document.createElement( 'div' );
		modal.getAnimations = () => [ createTransition( Infinity ) ];
		const callback = jest.fn();

		afterModalExit( modal, callback );

		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs once the transitions should have ended, even without hearing they did', () => {
		jest.useFakeTimers();
		const modal = document.createElement( 'div' );
		modal.getAnimations = () => [ createTransition( 400 ) ];
		const callback = jest.fn();

		afterModalExit( modal, callback );
		jest.advanceTimersByTime( 400 );
		expect( callback ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 100 );
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs early when asked, and only once', async () => {
		const transition = createTransition( 400 );
		const modal = document.createElement( 'div' );
		modal.getAnimations = () => [ transition ];
		const callback = jest.fn();

		const finish = afterModalExit( modal, callback );
		finish();
		expect( callback ).toHaveBeenCalledTimes( 1 );

		transition.end();
		await flushPromises();
		finish();
		expect( callback ).toHaveBeenCalledTimes( 1 );
	} );
} );
