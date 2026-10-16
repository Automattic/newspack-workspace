/**
 * With rate limiting on, adding a payment method first asks the server whether
 * the reader may try again. A check that fails has to tell the reader and hand
 * the button back, rather than leave a spinner that does nothing.
 */

const RealXMLHttpRequest = global.XMLHttpRequest;
let pendingRequest;

class FakeXMLHttpRequest {
	open() {}
	setRequestHeader() {}
	send() {
		pendingRequest = this;
	}
	respond( status, responseText ) {
		this.readyState = 4;
		this.status = status;
		this.responseText = responseText;
		this.onreadystatechange();
	}
}

/**
 * Render the add payment method form and load the script.
 */
function renderForm() {
	document.body.innerHTML = `
		<div class="woocommerce-notices-wrapper"></div>
		<form id="add_payment_method">
			<button type="submit">Add payment method</button>
		</form>
	`;
	Element.prototype.scrollIntoView = jest.fn();
	jest.isolateModules( () => {
		require( './payment-methods' );
	} );
	return document.querySelector( '#add_payment_method button' );
}

beforeEach( () => {
	global.XMLHttpRequest = FakeXMLHttpRequest;
	global.newspack_my_account = {
		labels: { generic_error: 'Something went wrong. Please try again.' },
		nonce: 'nonce-123',
		rest_url: 'https://example.test/wp-json/',
		should_rate_limit: true,
	};
} );

afterEach( () => {
	global.XMLHttpRequest = RealXMLHttpRequest;
	delete global.newspack_my_account;
	document.body.innerHTML = '';
} );

describe( 'add payment method rate check', () => {
	it( 'shows an error and releases the button when the check fails', () => {
		const button = renderForm();

		button.click();
		expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );

		pendingRequest.respond( 403, '{"code":"rest_cookie_invalid_nonce"}' );

		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( document.querySelector( '.woocommerce-error' ).textContent ).toBe( 'Something went wrong. Please try again.' );
	} );
} );
