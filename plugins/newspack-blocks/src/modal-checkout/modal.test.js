/**
 * After a purchase, modal checkout offers the newsletter signup modal and then
 * sends the reader to the post-checkout destination. However the reader leaves
 * that modal (answering it, or closing it), they are sent on exactly once. A
 * content gate reloads the page once no overlay is left, which would cancel the
 * navigation, so an overlay is held until the reader has left or come back.
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

// jsdom cannot load a new page, so the destinations here are ones it can reach:
// the previous history entry, or a fragment of the current page.
const GO_BACK = { behavior: 'referrer', url: '' };
const CUSTOM_URL = { behavior: 'custom', url: '#thank-you' };

/**
 * The thank-you screen as the checkout iframe prints it.
 *
 * @param {Object} afterSuccess          Where the reader goes after the purchase.
 * @param {string} afterSuccess.behavior The post-checkout behavior.
 * @param {string} afterSuccess.url      The post-checkout destination.
 *
 * @return {string} The thank-you markup.
 */
const thankYou = ( { behavior, url } ) => `
	<div id="newspack_modal_checkout_container">
		<p id="modal-checkout-product-details" data-checkout='{"product_id":"12"}'></p>
		<form>
			<input type="hidden" name="after_success_behavior" value="${ behavior }">
			<input type="hidden" name="after_success_url" value="${ url }">
		</form>
	</div>
`;

describe( 'modal checkout, leaving the newsletter signup modal after a purchase', () => {
	const openNewslettersSignupModal = jest.fn();
	const setPendingCheckout = jest.fn();
	let overlayCount = 0;
	const overlays = { add: jest.fn( () => `overlay-${ ++overlayCount }` ), remove: jest.fn() };
	let goBack;

	beforeAll( () => {
		document.body.innerHTML = PAGE;
		window.newspackBlocksModal = {
			newspack_class_prefix: 'newspack-ui',
			labels: { checkout_modal_title: 'Checkout', thankyou_modal_title: 'Thank you' },
			processing_payment_messages: [],
		};
		window.newspackReaderActivation = { openNewslettersSignupModal, setPendingCheckout, overlays };
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
	 * @param {Object} afterSuccess Where the reader goes after the purchase.
	 *
	 * @return {Object} The options modal checkout opened the newsletter signup modal with.
	 */
	function finishCheckout( afterSuccess ) {
		window.newspackOpenModalCheckout( { url: 'about:blank' } );
		const iframe = document.querySelector( 'iframe[name="newspack_modal_checkout_iframe"]' );
		iframe.contentDocument.open();
		iframe.contentDocument.write( `<html><body>${ thankYou( afterSuccess ) }</body></html>` );
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
		const signupModal = finishCheckout( GO_BACK );

		signupModal.onDismiss();

		expect( goBack ).toHaveBeenCalledTimes( 1 );
		expect( setPendingCheckout ).toHaveBeenCalledWith();
	} );

	it( 'sends the reader on once when they answer the signup modal and then close it', () => {
		const signupModal = finishCheckout( GO_BACK );

		signupModal.onSuccess();
		signupModal.onDismiss();

		expect( goBack ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps an overlay held while a configured redirect loads', () => {
		const signupModal = finishCheckout( CUSTOM_URL );
		jest.useFakeTimers();

		signupModal.onDismiss();
		jest.advanceTimersByTime( 60000 );

		expect( overlays.add ).toHaveBeenCalledTimes( 1 );
		expect( overlays.remove ).not.toHaveBeenCalled();
	} );

	it( 'lets go of the overlay when the reader comes back to the page', () => {
		const signupModal = finishCheckout( CUSTOM_URL );
		signupModal.onDismiss();
		const heldOverlay = overlays.add.mock.results[ 0 ].value;

		// The browser restores the page as the reader left it, and says so.
		window.onpageshow( { persisted: true } );

		expect( overlays.remove ).toHaveBeenCalledWith( heldOverlay );
	} );

	it( 'holds an overlay for a second when going back, then lets go in case there was nowhere to go', () => {
		const signupModal = finishCheckout( GO_BACK );
		jest.useFakeTimers();

		signupModal.onDismiss();
		const heldOverlay = overlays.add.mock.results[ 0 ].value;
		jest.advanceTimersByTime( 999 );

		expect( overlays.remove ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1 );

		expect( overlays.remove ).toHaveBeenCalledWith( heldOverlay );
	} );
} );
