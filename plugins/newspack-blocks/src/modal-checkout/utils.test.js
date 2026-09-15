/**
 * Tests for modal-checkout utils.
 */

import { afterDeferredScripts, getCheckoutData, whenReaderDataSynced, whenSignedInReaderDataSynced } from './utils';

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

	it( 'resolves true at once when reader activation or its flush method is absent', async () => {
		// Nothing to wait for: the checkout opens with whatever the server holds.
		await expect( whenReaderDataSynced( 'matched_segments' ) ).resolves.toBe( true );
		window.newspackReaderActivation = { store: {} };
		await expect( whenReaderDataSynced( 'matched_segments' ) ).resolves.toBe( true );
	} );

	it( 'flushes only after the current task, so a key written by a later DOMContentLoaded listener is pending', async () => {
		// When this bundle prints before the popups view script, its listener
		// registers first and popups writes matched_segments in a later listener
		// of the same dispatch; a flush taken synchronously would see nothing.
		jest.useFakeTimers();
		const flush = jest.fn( () => Promise.resolve( true ) );
		window.newspackReaderActivation = { store: { flush } };
		const waited = whenReaderDataSynced( 'matched_segments' );
		await Promise.resolve();
		expect( flush ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 0 );
		expect( flush ).toHaveBeenCalledWith( 'matched_segments' );
		await expect( waited ).resolves.toBe( true );
	} );

	it( 'resolves with the flush result once the store has flushed the key', async () => {
		let settle;
		const flush = jest.fn( () => new Promise( resolve => ( settle = resolve ) ) );
		window.newspackReaderActivation = { store: { flush } };
		let result;
		const waited = whenReaderDataSynced( 'matched_segments' ).then( synced => ( result = synced ) );
		await new Promise( resolve => setTimeout( resolve, 0 ) );
		expect( flush ).toHaveBeenCalledWith( 'matched_segments' );
		expect( result ).toBeUndefined();
		settle( false );
		await waited;
		expect( result ).toBe( false );
	} );

	it( 'resolves false when the store throws synchronously, so the checkout still opens', async () => {
		window.newspackReaderActivation = {
			store: {
				flush: () => {
					throw new Error( 'API not available.' );
				},
			},
		};
		await expect( whenReaderDataSynced( 'matched_segments' ) ).resolves.toBe( false );
	} );

	it( 'gives up at the cap with false so a stalled sync cannot hold the checkout', async () => {
		jest.useFakeTimers();
		window.newspackReaderActivation = { store: { flush: () => new Promise( () => {} ) } };
		let result;
		const waited = whenReaderDataSynced( 'matched_segments', 3000 ).then( synced => ( result = synced ) );
		jest.advanceTimersByTime( 2999 );
		await Promise.resolve();
		expect( result ).toBeUndefined();
		jest.advanceTimersByTime( 1 );
		await waited;
		expect( result ).toBe( false );
	} );
} );

describe( 'whenSignedInReaderDataSynced()', () => {
	afterEach( () => {
		delete window.newspackReaderActivation;
		jest.useRealTimers();
	} );

	it( 'resolves true at once when reader activation is absent', async () => {
		await expect( whenSignedInReaderDataSynced( 'matched_segments' ) ).resolves.toBe( true );
	} );

	it( 'flushes the key only after the session has hydrated', async () => {
		let hydrated;
		const hydrateSession = jest.fn( () => new Promise( resolve => ( hydrated = resolve ) ) );
		const flush = jest.fn( () => Promise.resolve( true ) );
		window.newspackReaderActivation = { hydrateSession, store: { flush } };
		let result;
		const waited = whenSignedInReaderDataSynced( 'matched_segments' ).then( synced => ( result = synced ) );
		await Promise.resolve();
		expect( hydrateSession ).toHaveBeenCalledTimes( 1 );
		expect( flush ).not.toHaveBeenCalled();
		hydrated( 'nonce' );
		await waited;
		expect( flush ).toHaveBeenCalledWith( 'matched_segments' );
		expect( result ).toBe( true );
	} );

	it( 'gives up at the cap with false when hydration never settles', async () => {
		jest.useFakeTimers();
		const flush = jest.fn( () => Promise.resolve( true ) );
		window.newspackReaderActivation = { hydrateSession: () => new Promise( () => {} ), store: { flush } };
		let result;
		const waited = whenSignedInReaderDataSynced( 'matched_segments', 3000 ).then( synced => ( result = synced ) );
		jest.advanceTimersByTime( 2999 );
		await Promise.resolve();
		expect( result ).toBeUndefined();
		jest.advanceTimersByTime( 1 );
		await waited;
		expect( result ).toBe( false );
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
