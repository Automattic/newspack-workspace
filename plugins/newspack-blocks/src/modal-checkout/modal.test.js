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
	<dialog id="newspack_modal_checkout" class="newspack-ui__modal-container" data-state="closed">
		<div class="newspack-ui__modal">
			<div class="newspack-ui__modal__header">
				<h2></h2>
				<button class="newspack-ui__modal__close">Close</button>
			</div>
			<div class="newspack-ui__modal__content">
				<div class="newspack-ui__spinner"></div>
			</div>
		</div>
	</dialog>
	<dialog class="newspack-ui__modal-container newspack-newsletters-signup-modal" data-state="closed"></dialog>
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
	// Every overlay gets its own id: the checkout modal registers one too, and a test
	// about the held overlay has to tell the two apart.
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
	 * @param {Object}   afterSuccess Where the reader goes after the purchase.
	 * @param {Function} close        How the reader closes the checkout.
	 *
	 * @return {Object} The options modal checkout opened the newsletter signup modal with.
	 */
	function finishCheckout( afterSuccess, close = () => window.newspackCloseModalCheckout() ) {
		window.newspackOpenModalCheckout( { url: 'about:blank' } );
		const iframe = document.querySelector( 'iframe[name="newspack_modal_checkout_iframe"]' );
		iframe.contentDocument.open();
		iframe.contentDocument.write( `<html><body>${ thankYou( afterSuccess ) }</body></html>` );
		iframe.contentDocument.close();
		iframe.contentDocument.querySelector( '#newspack_modal_checkout_container' ).checkoutComplete = true;
		openNewslettersSignupModal.mockClear();
		close();
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

	it( 'offers the signup modal when the reader leaves the thank-you screen with Escape', () => {
		const cancel = new Event( 'cancel', { cancelable: true } );

		const signupModal = finishCheckout( GO_BACK, () => document.getElementById( 'newspack_modal_checkout' ).dispatchEvent( cancel ) );

		expect( cancel.defaultPrevented ).toBe( true );
		signupModal.onDismiss();
		expect( goBack ).toHaveBeenCalledTimes( 1 );
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
		window.dispatchEvent( new window.PageTransitionEvent( 'pageshow', { persisted: true } ) );

		expect( overlays.remove ).toHaveBeenCalledWith( heldOverlay );
	} );

	it( 'holds an overlay for a second when going back, then lets go in case there was nowhere to go', () => {
		const signupModal = finishCheckout( GO_BACK );
		jest.useFakeTimers();

		signupModal.onDismiss();
		expect( overlays.add ).toHaveBeenCalledTimes( 1 );
		const heldOverlay = overlays.add.mock.results[ 0 ].value;
		jest.advanceTimersByTime( 999 );

		expect( overlays.remove ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1 );

		expect( overlays.remove ).toHaveBeenCalledWith( heldOverlay );
	} );
} );

describe( 'modal checkout as a dialog', () => {
	const setPendingCheckout = jest.fn();
	const nativeDialog = {};
	let checkout;
	let spinner;
	let loads = 0;

	beforeAll( () => {
		[ 'showModal', 'close' ].forEach( method => {
			nativeDialog[ method ] = HTMLDialogElement.prototype[ method ];
		} );
		HTMLDialogElement.prototype.showModal = function () {
			this.setAttribute( 'open', '' );
		};
		HTMLDialogElement.prototype.close = function () {
			this.removeAttribute( 'open' );
		};
		document.body.innerHTML = PAGE;
		window.newspackBlocksModal = {
			newspack_class_prefix: 'newspack-ui',
			ajax_url: '/wp-admin/admin-ajax.php',
			labels: { checkout_modal_title: 'Checkout', thankyou_modal_title: 'Thank you' },
			processing_payment_messages: [],
		};
		window.newspackReaderActivation = { setPendingCheckout };
		window.fetch = jest.fn().mockResolvedValue( { ok: true } );
		// The checkout iframe holds no product details here, which modal checkout reports.
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		jest.isolateModules( () => {
			require( './modal' );
		} );
		checkout = document.getElementById( 'newspack_modal_checkout' );
		spinner = checkout.querySelector( '.newspack-ui__spinner' );
	} );

	afterAll( () => {
		Object.assign( HTMLDialogElement.prototype, nativeDialog );
		delete window.fetch;
		jest.restoreAllMocks();
	} );

	beforeEach( () => {
		setPendingCheckout.mockClear();
		window.fetch.mockClear();
	} );

	afterEach( () => {
		spinner.removeAttribute( 'aria-busy' );
		if ( 'open' === checkout.dataset.state ) {
			window.newspackCloseModalCheckout();
		}
	} );

	/**
	 * Open checkout and load a checkout screen into its iframe.
	 *
	 * @param {string} screen The checkout screen's markup.
	 *
	 * @return {Promise<Element>} The checkout container inside the iframe.
	 */
	async function loadCheckout( screen = '<input id="billing_first_name" />' ) {
		window.newspackOpenModalCheckout( { url: 'about:blank' } );
		checkout.setAttribute( 'open', '' );
		const iframe = checkout.querySelector( 'iframe' );
		const doc = iframe.contentDocument;
		doc.open();
		doc.write( `<html><body><div id="newspack_modal_checkout_container">${ screen }</div></body></html>` );
		doc.close();
		// jsdom loads no page into the iframe, so the checkout's own address is faked.
		Object.defineProperty( doc, 'URL', { configurable: true, value: `http://localhost/checkout/?load=${ ++loads }` } );
		iframe.contentWindow.Element.prototype.getClientRects = () => [ {} ];
		await new Promise( resolve => setTimeout( resolve, 100 ) );
		return doc.getElementById( 'newspack_modal_checkout_container' );
	}

	const placeOrder = container => container.dispatchEvent( new CustomEvent( 'checkout-place-order-start' ) );
	const pressEscape = () => {
		const cancel = new Event( 'cancel', { cancelable: true } );
		checkout.dispatchEvent( cancel );
		return cancel;
	};

	it( 'moves focus to the first field once the checkout shows', async () => {
		const container = await loadCheckout( '<a href="#terms">Terms</a><input id="billing_first_name" />' );

		container.dispatchEvent( new CustomEvent( 'checkout-ready' ) );

		expect( container.ownerDocument.activeElement.id ).toBe( 'billing_first_name' );
	} );

	it( 'leaves focus on the Close button when the reader moved there while it loaded', async () => {
		const container = await loadCheckout();
		const close = checkout.querySelector( '.newspack-ui__modal__close' );
		close.focus();

		container.dispatchEvent( new CustomEvent( 'checkout-ready' ) );

		expect( document.activeElement ).toBe( close );
	} );

	it( 'closes on Escape and empties the cart, like its Close button', async () => {
		await loadCheckout();

		expect( pressEscape().defaultPrevented ).toBe( true );

		expect( checkout.dataset.state ).toBe( 'closed' );
		expect( window.fetch.mock.calls[ 0 ][ 1 ].body.get( 'action' ) ).toBe( 'abandon_modal_checkout' );
		expect( setPendingCheckout ).toHaveBeenCalledWith();
	} );

	it( 'stays open on Escape while a payment is processing', async () => {
		placeOrder( await loadCheckout() );

		pressEscape();

		expect( checkout.dataset.state ).toBe( 'open' );
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'steps the processing screen aside for a payment challenge, and back once it is done', async () => {
		const container = await loadCheckout();
		placeOrder( container );
		const challenge = shown => container.dispatchEvent( new CustomEvent( 'checkout-payment-challenge', { detail: { shown } } ) );

		challenge( true );
		expect( spinner.style.display ).toBe( 'none' );

		challenge( false );
		expect( spinner.style.display ).toBe( 'flex' );
	} );

	it( 'closes on Escape again after the reader closed it during a payment', async () => {
		placeOrder( await loadCheckout() );
		window.newspackCloseModalCheckout();

		await loadCheckout();
		pressEscape();

		expect( checkout.dataset.state ).toBe( 'closed' );
	} );
} );
