/**
 * Tests for modal-checkout utils.
 */

import {
	afterDeferredScripts,
	getCheckoutData,
	getInitialFocus,
	hasFrameProcessingScreen,
	isPaymentChallengeShown,
	isSameDocumentRedirect,
	mountFrameProcessingScreen,
	preventDisabledCloseClicks,
	removeFrameProcessingScreen,
	whenReaderDataSynced,
	whenSignedInReaderDataSynced,
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

describe( 'whenReaderDataSynced()', () => {
	afterEach( () => {
		delete window.newspackReaderActivation;
		jest.useRealTimers();
	} );

	it( 'resolves at once when reader activation or its flush method is absent', async () => {
		await expect( whenReaderDataSynced( 'matched_segments' ) ).resolves.toBeUndefined();
		window.newspackReaderActivation = { store: {} };
		await expect( whenReaderDataSynced( 'matched_segments' ) ).resolves.toBeUndefined();
	} );

	it( 'resolves once the store has flushed the key', async () => {
		let settle;
		const flush = jest.fn( () => new Promise( resolve => ( settle = resolve ) ) );
		window.newspackReaderActivation = { store: { flush } };
		let resolved = false;
		const waited = whenReaderDataSynced( 'matched_segments' ).then( () => ( resolved = true ) );
		await Promise.resolve();
		expect( flush ).toHaveBeenCalledWith( 'matched_segments' );
		expect( resolved ).toBe( false );
		settle();
		await waited;
		expect( resolved ).toBe( true );
	} );

	it( 'gives up waiting at the cap so a stalled sync cannot hold the checkout', async () => {
		jest.useFakeTimers();
		window.newspackReaderActivation = { store: { flush: () => new Promise( () => {} ) } };
		let resolved = false;
		const waited = whenReaderDataSynced( 'matched_segments', 3000 ).then( () => ( resolved = true ) );
		jest.advanceTimersByTime( 2999 );
		await Promise.resolve();
		expect( resolved ).toBe( false );
		jest.advanceTimersByTime( 1 );
		await waited;
		expect( resolved ).toBe( true );
	} );
} );

describe( 'whenSignedInReaderDataSynced()', () => {
	afterEach( () => {
		delete window.newspackReaderActivation;
		jest.useRealTimers();
	} );

	it( 'resolves at once when reader activation is absent', async () => {
		await expect( whenSignedInReaderDataSynced( 'matched_segments' ) ).resolves.toBeUndefined();
	} );

	it( 'flushes the key only after the session has hydrated', async () => {
		let hydrated;
		const hydrateSession = jest.fn( () => new Promise( resolve => ( hydrated = resolve ) ) );
		const flush = jest.fn( () => Promise.resolve() );
		window.newspackReaderActivation = { hydrateSession, store: { flush } };
		let resolved = false;
		const waited = whenSignedInReaderDataSynced( 'matched_segments' ).then( () => ( resolved = true ) );
		await Promise.resolve();
		expect( hydrateSession ).toHaveBeenCalledTimes( 1 );
		expect( flush ).not.toHaveBeenCalled();
		hydrated( 'nonce' );
		await waited;
		expect( flush ).toHaveBeenCalledWith( 'matched_segments' );
		expect( resolved ).toBe( true );
	} );

	it( 'gives up at the cap when hydration never settles', async () => {
		jest.useFakeTimers();
		const flush = jest.fn( () => Promise.resolve() );
		window.newspackReaderActivation = { hydrateSession: () => new Promise( () => {} ), store: { flush } };
		let resolved = false;
		const waited = whenSignedInReaderDataSynced( 'matched_segments', 3000 ).then( () => ( resolved = true ) );
		jest.advanceTimersByTime( 2999 );
		await Promise.resolve();
		expect( resolved ).toBe( false );
		jest.advanceTimersByTime( 1 );
		await waited;
		expect( resolved ).toBe( true );
		expect( flush ).not.toHaveBeenCalled();
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
