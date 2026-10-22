/**
 * My Account buttons that open modal checkout are found by their classes, so the
 * markup the account templates print has to keep matching those selectors.
 */

jest.mock( './utils', () => ( { registerModalCheckoutButton: jest.fn() } ) );

/**
 * Render markup and load the script.
 *
 * @param {string} html Page markup.
 * @return {Array} The [ element, actionType ] pairs registered for modal checkout.
 */
function load( html ) {
	document.body.innerHTML = html;
	let calls = [];
	jest.isolateModules( () => {
		const { registerModalCheckoutButton } = require( './utils' );
		registerModalCheckoutButton.mockClear();
		require( './modal-checkout' );
		calls = registerModalCheckoutButton.mock.calls.map( ( [ element, , actionType ] ) => [ element, actionType ] );
	} );
	return calls;
}

beforeEach( () => {
	global.newspackMyAccountV1 = { labels: {} };
} );

describe( 'My Account modal checkout buttons', () => {
	it( 'registers the order again button, with or without its legacy wrapper', () => {
		const calls = load( `
			<a class="newspack-ui__button order-again" href="https://example.test/?order_again=1">Order again</a>
			<p class="order-again"><a class="button" href="https://example.test/?order_again=2">Order again</a></p>
		` );

		expect( calls.filter( ( [ , action ] ) => action === 'order_again' ).map( ( [ element ] ) => element.getAttribute( 'href' ) ) ).toEqual( [
			'https://example.test/?order_again=1',
			'https://example.test/?order_again=2',
		] );
	} );

	it( 'registers only the Pay now link in the orders tables', () => {
		const calls = load( `
			<a class="newspack-ui__link" href="https://example.test/my-account/view-order/650/">View</a>
			<span class="newspack-my-account__order-pay"><a class="newspack-ui__link pay" href="https://example.test/checkout/order-pay/650/">Pay now</a></span>
		` );

		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ][ 0 ].getAttribute( 'href' ) ).toBe( 'https://example.test/checkout/order-pay/650/' );
		expect( calls[ 0 ][ 1 ] ).toBe( 'pay_order' );
	} );
} );
