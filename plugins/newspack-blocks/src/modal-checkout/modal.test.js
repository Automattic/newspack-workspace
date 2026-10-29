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

/**
 * A sign-in inside the checkout waits for the reader's data to sync before the
 * checkout opens, and the reader can close what is on screen meanwhile: the
 * checkout's spinner on the modal path, the sign-in dialog on the redirect path.
 * That close abandons the checkout. modal.js is driven through a checkout button
 * on the page and the sign-in dialog's callbacks, with the sync settled by hand.
 */
describe( 'modal checkout, closing the sign-in checkout while it waits for the sync', () => {
	const CART_URL = 'http://localhost/checkout/?cart=1';
	const PAGE_WITH_BUTTON =
		PAGE +
		`<div class="wp-block-newspack-blocks-checkout-button"><form data-checkout='{"product_id":"9"}'>
			<input type="hidden" name="newspack_checkout" value="1"><input type="hidden" name="product_id" value="9">
			<button type="submit">Buy</button></form></div>`;
	const setPendingCheckout = jest.fn();
	const openAuthModal = jest.fn();
	let overlayCount;
	const overlays = { add: jest.fn( () => `overlay-${ ++overlayCount }` ), remove: jest.fn() };
	let checkout;
	let form;
	let reader;
	let flushes;
	let cartRequest;

	beforeAll( () => {
		// The redirect path navigates by assigning location.href, which jsdom cannot do.
		delete window.location;
		window.location = {
			href: 'http://localhost/',
			origin: 'http://localhost',
			pathname: '/',
			search: '',
			hash: '',
			protocol: 'http:',
			host: 'localhost',
		};
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	} );

	afterAll( () => {
		delete window.fetch;
		delete window.newspack_ras_config;
		jest.restoreAllMocks();
	} );

	/**
	 * Load the page and modal.js for one case.
	 *
	 * @param {Object}  options
	 * @param {boolean} options.redirect      Whether the gateway forces the redirect checkout.
	 * @param {boolean} options.authenticated Whether the reader is already signed in.
	 */
	function setup( { redirect = false, authenticated = false } = {} ) {
		overlayCount = 0;
		flushes = [];
		cartRequest = null;
		reader = { authenticated };
		window.location.href = 'http://localhost/';
		document.body.innerHTML = PAGE_WITH_BUTTON;
		window.newspack_ras_config = { is_logged_in: false };
		window.newspackBlocksModal = {
			newspack_class_prefix: 'newspack-ui',
			ajax_url: '/wp-admin/admin-ajax.php',
			has_unsupported_payment_gateway: redirect,
			is_registration_required: true,
			checkout_registration_flag: 'registered',
			labels: {
				checkout_modal_title: 'Checkout',
				thankyou_modal_title: 'Thank you',
				auth_modal_title: 'Sign in',
				signin_modal_title: 'Sign in',
				register_modal_title: 'Register',
			},
			processing_payment_messages: [],
		};
		window.newspackReaderActivation = {
			setPendingCheckout,
			openAuthModal,
			overlays,
			getReader: () => reader,
			hydrateSession: () => Promise.resolve( 'nonce' ),
			store: { flush: () => new Promise( resolve => flushes.push( resolve ) ) },
		};
		window.fetch = jest.fn( ( url, options ) => {
			if ( options?.method === 'POST' ) {
				return Promise.resolve( { ok: true } );
			}
			return new Promise( ( resolve, reject ) => {
				cartRequest = { resolve: () => resolve( { ok: true, json: () => Promise.resolve( { url: CART_URL } ) } ), reject };
			} );
		} );
		jest.isolateModules( () => {
			require( './modal' );
		} );
		checkout = document.getElementById( 'newspack_modal_checkout' );
		form = document.querySelector( '.wp-block-newspack-blocks-checkout-button form' );
		[ openAuthModal, setPendingCheckout, overlays.add, overlays.remove ].forEach( mock => mock.mockClear() );
	}

	const settle = () => new Promise( resolve => setTimeout( resolve, 30 ) );
	const press = () => form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	const checkoutSubmitted = () => !! document.querySelector( `form[target="newspack_modal_checkout_iframe"][action="${ CART_URL }"]` );
	const clearedPendingCheckout = () => setPendingCheckout.mock.calls.filter( call => 0 === call.length ).length;

	/**
	 * Press the button and sign in through the dialog it opened.
	 *
	 * @return {Promise<Object>} The dialog config, with its sync still pending.
	 */
	async function signIn() {
		press();
		cartRequest.resolve();
		await settle();
		const config = openAuthModal.mock.calls[ openAuthModal.mock.calls.length - 1 ][ 0 ];
		reader = { authenticated: true };
		config.onSuccess( '', {} );
		await settle();
		return config;
	}

	it( 'opens the checkout once the sync ends', async () => {
		setup();
		await signIn();
		expect( checkout.dataset.state ).toBe( 'open' );
		expect( checkoutSubmitted() ).toBe( false );
		flushes[ 0 ]( true );
		await settle();
		expect( checkoutSubmitted() ).toBe( true );
	} );

	it( 'does not open the checkout the reader closed while it waited', async () => {
		setup();
		await signIn();
		window.newspackCloseModalCheckout();
		expect( checkout.dataset.state ).toBe( 'closed' );
		flushes[ 0 ]( true );
		await settle();
		expect( checkout.dataset.state ).toBe( 'closed' );
		expect( checkoutSubmitted() ).toBe( false );
	} );

	it( 'navigates to the redirect checkout once the sync ends', async () => {
		setup( { redirect: true } );
		await signIn();
		expect( window.location.href ).toBe( 'http://localhost/' );
		flushes[ 0 ]( true );
		await settle();
		expect( window.location.href ).toBe( CART_URL );
	} );

	it( 'does not navigate to the redirect checkout the reader closed the dialog on, and forgets it', async () => {
		setup( { redirect: true } );
		const config = await signIn();
		config.onClose();
		expect( clearedPendingCheckout() ).toBe( 1 );
		flushes[ 0 ]( true );
		await settle();
		expect( window.location.href ).toBe( 'http://localhost/' );
	} );

	it( 'keeps the pending checkout of a dialog dismissed before signing in, for the magic link sent from it', async () => {
		setup( { redirect: true } );
		press();
		cartRequest.resolve();
		await settle();
		const config = openAuthModal.mock.calls[ 0 ][ 0 ];
		expect( setPendingCheckout ).toHaveBeenLastCalledWith( CART_URL );
		config.onDismiss();
		config.onClose();
		expect( clearedPendingCheckout() ).toBe( 0 );
	} );

	it( 'abandons the redirect checkout when the dialog a second press re-opened is closed', async () => {
		setup( { redirect: true } );
		press();
		cartRequest.resolve();
		await settle();
		const first = openAuthModal.mock.calls[ 0 ][ 0 ];
		const second = await signIn();
		expect( openAuthModal ).toHaveBeenCalledTimes( 2 );
		// The dialog reports its close through both configs, the earlier one first.
		first.onDismiss();
		first.onClose();
		second.onClose();
		expect( clearedPendingCheckout() ).toBe( 1 );
		flushes[ 0 ]( true );
		await settle();
		expect( window.location.href ).toBe( 'http://localhost/' );
	} );

	it( 'holds an overlay while the redirect checkout builds its cart, and lets go if that fails', async () => {
		setup( { redirect: true, authenticated: true } );
		press();
		expect( overlays.add ).toHaveBeenCalledTimes( 1 );
		expect( overlays.remove ).not.toHaveBeenCalled();
		cartRequest.reject( new Error( 'unreachable' ) );
		await settle();
		expect( overlays.remove ).toHaveBeenCalledWith( 'overlay-1' );
	} );
} );
