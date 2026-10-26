/**
 * Tests for the express checkout submission attempt.
 */

import { createHooks } from '@wordpress/hooks';

import { registerExpressCheckoutAttempt } from './checkout-attempt';

// Written out rather than imported from the module, so the tests fail if the
// module ever stops listening on the filter Stripe actually applies.
const STRIPE_ORDER_FILTER = 'wcstripe.express-checkout.cart-place-order-extension-data';

// The data carrier the modal checkout document prints for a cart: a $10 simple
// product bought from a Checkout Button block.
const CHECKOUT_DATA = {
	action_type: 'checkout_button',
	currency: 'USD',
	product_id: '85',
	product_type: 'product',
	referrer: '/support-us/',
	amount: '10',
	quantity: 1,
	price_summary: 'Example Product: $10.00',
	summary_template: 'Example Product: {{PRICE}}',
	recurrence: 'once',
};

function renderProductDetails() {
	const details = document.createElement( 'p' );
	details.id = 'modal-checkout-product-details';
	details.hidden = true;
	details.dataset.checkout = JSON.stringify( CHECKOUT_DATA );
	document.body.appendChild( details );
}

let hooks;

beforeEach( () => {
	// The page's shared registry: Stripe's express checkout script registers on
	// and applies the filter through window.wp.hooks.
	hooks = createHooks();
	window.wp = { hooks };
	window.gtag = jest.fn();
	renderProductDetails();
} );

afterEach( () => {
	delete window.gtag;
	delete window.wp;
	document.body.innerHTML = '';
} );

describe( 'registerExpressCheckoutAttempt()', () => {
	it( 'sends one form_submission when Stripe applies its order filter after an express checkout approval', () => {
		registerExpressCheckoutAttempt();

		hooks.applyFilters( STRIPE_ORDER_FILTER, {} );

		expect( window.gtag ).toHaveBeenCalledTimes( 1 );
		expect( window.gtag ).toHaveBeenCalledWith( 'event', 'np_modal_checkout_interaction', {
			action: 'form_submission',
			action_type: 'checkout_button',
			amount: '10',
			currency: 'USD',
			product_id: '85',
			product_type: 'product',
			quantity: '1',
			price_summary: 'Example Product: $10.00',
			recurrence: 'once',
			referrer: '/support-us/',
		} );
	} );

	it( 'still registers when run as a DOMContentLoaded listener', () => {
		// afterDeferredScripts() hands the event to its callback when it has to
		// wait, which is how the modal checkout script runs this on a slow load.
		registerExpressCheckoutAttempt( new Event( 'DOMContentLoaded' ) );

		hooks.applyFilters( STRIPE_ORDER_FILTER, {} );

		expect( window.gtag ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'returns the extension data it was given', () => {
		const extensions = { 'woocommerce/order-attribution': { source_type: 'typein' } };
		registerExpressCheckoutAttempt();

		expect( hooks.applyFilters( STRIPE_ORDER_FILTER, extensions ) ).toBe( extensions );
	} );

	it( 'returns the extension data when sending the event throws', () => {
		const extensions = { 'woocommerce/order-attribution': { source_type: 'typein' } };
		window.gtag = jest.fn( () => {
			throw new Error( 'gtag failed' );
		} );
		registerExpressCheckoutAttempt();

		expect( hooks.applyFilters( STRIPE_ORDER_FILTER, extensions ) ).toBe( extensions );
	} );

	it.each( [
		[ 'no wp global', undefined ],
		[ 'a hooks registry without addFilter', { hooks: {} } ],
		[
			'a hooks registry whose addFilter throws',
			{
				hooks: {
					addFilter: () => {
						throw new Error( 'broken registry' );
					},
				},
			},
		],
	] )( 'does not throw on a page with %s', ( _label, wp ) => {
		window.wp = wp;

		expect( () => registerExpressCheckoutAttempt() ).not.toThrow();
	} );
} );
