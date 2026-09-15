/**
 * Tests for modal-checkout utils.
 */

import { afterDeferredScripts, getCheckoutData, whenReaderDataSynced } from './utils';

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
