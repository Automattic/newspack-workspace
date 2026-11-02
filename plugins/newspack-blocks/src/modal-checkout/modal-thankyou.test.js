/**
 * The thank-you screen goes without the checkout modal's title bar and moves focus
 * to its message. A failed order keeps the title bar and its Close button, and the
 * next checkout screen shows it again.
 *
 * Closing checkout keeps its last screen until the modal has transitioned out.
 *
 * jsdom never finishes loading an about:blank frame, so the frame's ready callback
 * is captured and called once each screen is written.
 */

jest.mock( './utils', () => ( {
	...jest.requireActual( './utils' ),
	iframeReady: ( iframe, callback ) => {
		iframe.onScreenReady = callback;
	},
} ) );

const PAGE = `
	<dialog id="newspack_modal_checkout" class="newspack-ui__modal-container" data-state="closed">
		<div class="newspack-ui__modal">
			<header class="newspack-ui__modal__header">
				<h2></h2>
				<button class="newspack-ui__modal__close">Close</button>
			</header>
			<div class="newspack-ui__modal__content">
				<div class="newspack-ui__spinner"></div>
			</div>
		</div>
	</dialog>
`;

const SUCCESS = `
	<div class="woocommerce-order">
		<div class="modal-checkout-thankyou">
			<h2 id="modal-checkout-product-details" tabindex="-1" data-checkout='{"product_id":"12"}'>Transaction successful</h2>
		</div>
		<form><button type="button" id="checkout-after-success">Continue</button></form>
	</div>
`;

const FAILURE = `
	<div class="woocommerce-order">
		<div class="newspack-ui__notice newspack-ui__notice--error" role="alert"><p>Unfortunately your order cannot be processed.</p></div>
		<a href="#pay" class="newspack-ui__button">Pay</a>
	</div>
`;

const CHECKOUT = '<form><input type="email" id="billing_email" /></form>';

describe( 'modal checkout title bar around the thank-you screen', () => {
	beforeAll( () => {
		document.body.innerHTML = PAGE;
		window.newspackBlocksModal = {
			newspack_class_prefix: 'newspack-ui',
			labels: { checkout_modal_title: 'Checkout', thankyou_modal_title: 'Transaction successful' },
			processing_payment_messages: [],
		};
		window.newspackReaderActivation = { overlays: { add: jest.fn(), remove: jest.fn() } };
		window.newspackRAS = [];
		require( './modal' );
	} );

	const header = () => document.querySelector( '.newspack-ui__modal__header' );

	/**
	 * Show a screen in the checkout frame, as the frame reports it.
	 *
	 * @param {string}  markup   The screen's markup.
	 * @param {boolean} complete Whether the frame reports a finished checkout.
	 *
	 * @return {Document} The frame's document.
	 */
	function showScreen( markup, complete ) {
		const iframe = document.querySelector( 'iframe[name="newspack_modal_checkout_iframe"]' );
		const doc = iframe.contentDocument;
		doc.open();
		doc.write( `<html><body id="newspack_modal_checkout_container">${ markup }</body></html>` );
		doc.close();
		doc.body[ complete ? 'checkoutComplete' : 'checkoutReady' ] = true;
		iframe.onScreenReady();
		return doc;
	}

	beforeEach( () => {
		window.newspackOpenModalCheckout( { url: 'about:blank' } );
	} );

	it( 'hides the title bar and focuses the message on the thank-you screen', () => {
		const doc = showScreen( SUCCESS, true );

		expect( header().hidden ).toBe( true );
		expect( doc.activeElement ).toBe( doc.getElementById( 'modal-checkout-product-details' ) );
	} );

	it( 'keeps the title bar on a failed order', () => {
		showScreen( FAILURE, true );

		expect( header().hidden ).toBe( false );
	} );

	it( 'does not title a failed order as a successful transaction', () => {
		showScreen( FAILURE, true );

		expect( header().querySelector( 'h2' ).innerText ).toBe( 'Checkout' );
	} );

	it( 'shows the title bar again on the next checkout screen', () => {
		showScreen( SUCCESS, true );
		showScreen( CHECKOUT, false );

		expect( header().hidden ).toBe( false );
	} );

	it( 'shows the title bar again when checkout opens after a thank-you screen', () => {
		showScreen( SUCCESS, true );
		window.newspackOpenModalCheckout( { url: 'about:blank' } );

		expect( header().hidden ).toBe( false );
	} );

	describe( 'while checkout transitions out', () => {
		const dialog = () => document.getElementById( 'newspack_modal_checkout' );
		const frame = () => document.querySelector( '.newspack-ui__modal__content iframe[name="newspack_modal_checkout_iframe"]' );
		const flushPromises = () => new Promise( resolve => setTimeout( resolve, 0 ) );
		let endTransition;

		beforeEach( () => {
			const finished = new Promise( resolve => {
				endTransition = resolve;
			} );
			dialog().getAnimations = () => [ { effect: { getComputedTiming: () => ( { endTime: 400 } ) }, finished } ];
		} );

		afterEach( () => {
			delete dialog().getAnimations;
		} );

		it( 'keeps the thank-you screen until checkout has transitioned out', async () => {
			showScreen( SUCCESS, true );
			window.newspackCloseModalCheckout();

			expect( dialog().dataset.state ).toBe( 'closed' );
			expect( frame() ).not.toBeNull();
			expect( header().hidden ).toBe( true );

			endTransition();
			await flushPromises();

			expect( frame() ).toBeNull();
			expect( header().hidden ).toBe( false );
		} );

		it( 'resets checkout before it opens again partway through', async () => {
			showScreen( SUCCESS, true );
			window.newspackCloseModalCheckout();
			window.newspackOpenModalCheckout( { url: 'about:blank' } );

			expect( header().hidden ).toBe( false );
			expect( frame() ).not.toBeNull();
			expect( frame().style.visibility ).toBe( 'hidden' );

			endTransition();
			await flushPromises();

			expect( frame() ).not.toBeNull();
		} );

		it( 'leaves checkout alone once it is open again', async () => {
			showScreen( SUCCESS, true );
			window.newspackCloseModalCheckout();
			dialog().setAttribute( 'data-state', 'open' );

			endTransition();
			await flushPromises();

			expect( frame() ).not.toBeNull();
			expect( header().hidden ).toBe( true );
		} );
	} );
} );
