/**
 * After a purchase, modal checkout offers the newsletter signup modal and then
 * sends the reader to the post-checkout destination. However the reader leaves
 * that modal (answering it, or closing it), they are sent on exactly once, and
 * a content gate on the page must not reload over the navigation.
 *
 * modal.js is driven through the two globals it exposes, with the signup modal
 * stubbed so the callbacks it was given can be called the way that modal would.
 */

const PAGE = `
	<div id="newspack_modal_checkout" class="newspack-ui__modal-container" data-state="closed">
		<div class="newspack-ui__modal">
			<div class="newspack-ui__modal__header">
				<h2></h2>
				<button class="newspack-ui__modal__close">Close</button>
			</div>
			<div class="newspack-ui__modal__content">
				<div class="newspack-ui__spinner"></div>
			</div>
			<span id="newspack-a11y-last-element" tabindex="0"></span>
		</div>
	</div>
	<div class="newspack-ui__modal-container newspack-newsletters-signup-modal" data-state="closed"></div>
`;

// "Go back" is the post-checkout behavior used here: jsdom can go back, but cannot load a new URL.
const THANK_YOU = `
	<div id="newspack_modal_checkout_container">
		<p id="modal-checkout-product-details" data-checkout='{"product_id":"12"}'></p>
		<form>
			<input type="hidden" name="after_success_behavior" value="referrer">
			<input type="hidden" name="after_success_url" value="">
		</form>
	</div>
`;

describe( 'modal checkout, leaving the newsletter signup modal after a purchase', () => {
	const openNewslettersSignupModal = jest.fn();
	const setPendingCheckout = jest.fn();
	const overlays = { add: jest.fn( () => 'held-overlay' ), remove: jest.fn() };
	let goBack;

	beforeAll( () => {
		document.body.innerHTML = PAGE;
		window.ResizeObserver = class {
			observe() {}
			disconnect() {}
		};
		window.newspackBlocksModal = {
			newspack_class_prefix: 'newspack-ui',
			labels: { checkout_modal_title: 'Checkout', thankyou_modal_title: 'Thank you' },
			processing_payment_messages: [],
			has_unsupported_payment_gateway: false,
			ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
			checkout_url: 'https://example.test/checkout/',
		};
		global.newspackBlocksModal = window.newspackBlocksModal;
		window.newspackReaderActivation = { openNewslettersSignupModal, setPendingCheckout, overlays };
		global.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } ) );
		require( './modal' );
	} );

	beforeEach( () => {
		goBack = jest.spyOn( window.history, 'back' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		jest.useRealTimers();
		goBack.mockRestore();
	} );

	/**
	 * Take a checkout to its thank-you screen and close it, as the reader's "Continue" does.
	 *
	 * @return {Object} The options modal checkout opened the newsletter signup modal with.
	 */
	function finishCheckout() {
		window.newspackOpenModalCheckout( { url: 'about:blank' } );
		const iframe = document.querySelector( 'iframe[name="newspack_modal_checkout_iframe"]' );
		iframe.contentDocument.open();
		iframe.contentDocument.write( `<html><body>${ THANK_YOU }</body></html>` );
		iframe.contentDocument.close();
		iframe.contentDocument.querySelector( '#newspack_modal_checkout_container' ).checkoutComplete = true;
		openNewslettersSignupModal.mockClear();
		window.newspackCloseModalCheckout();
		const signupModal = openNewslettersSignupModal.mock.calls[ 0 ][ 0 ];
		setPendingCheckout.mockClear();
		overlays.add.mockClear();
		overlays.remove.mockClear();
		return signupModal;
	}

	it( 'sends the reader on when they close the signup modal', () => {
		const signupModal = finishCheckout();

		signupModal.onDismiss();

		expect( goBack ).toHaveBeenCalledTimes( 1 );
		expect( setPendingCheckout ).toHaveBeenCalledWith();
	} );

	it( 'sends the reader on once when they answer the signup modal and then close it', () => {
		const signupModal = finishCheckout();

		signupModal.onSuccess();
		signupModal.onDismiss();

		expect( goBack ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'holds an overlay while it navigates, and lets go if there was nowhere to go back to', () => {
		const signupModal = finishCheckout();
		jest.useFakeTimers();

		signupModal.onDismiss();

		expect( overlays.add ).toHaveBeenCalledTimes( 1 );
		expect( overlays.remove ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1000 );

		expect( overlays.remove ).toHaveBeenCalledWith( 'held-overlay' );
	} );
} );
