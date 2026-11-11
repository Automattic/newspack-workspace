/**
 * Without newspack-plugin there is no newspack-ui script to run the checkout
 * dialog, so modal checkout opens and closes it itself, and still closes on Escape.
 */

describe( 'modal checkout without newspack-ui', () => {
	const nativeDialog = {};
	let checkout;
	let trigger;

	beforeAll( () => {
		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-checkout-button">
				<form data-checkout='{"product_id":"12"}'><button type="submit" id="trigger">Subscribe</button></form>
			</div>
			<dialog id="newspack_modal_checkout" class="newspack-blocks newspack-blocks__modal-container">
				<div class="newspack-blocks__modal">
					<header class="newspack-blocks__modal__header">
						<h2 id="newspack-modal-checkout-label">Checkout</h2>
						<button class="newspack-blocks__modal__close">Close</button>
					</header>
					<section class="newspack-blocks__modal__content">
						<div class="newspack-blocks__spinner"></div>
					</section>
				</div>
			</dialog>
		`;
		[ 'showModal', 'close' ].forEach( method => {
			nativeDialog[ method ] = HTMLDialogElement.prototype[ method ];
		} );
		// Browsers focus the first focusable descendant on open, which is the header's Close button.
		HTMLDialogElement.prototype.showModal = function () {
			this.setAttribute( 'open', '' );
			this.querySelector( 'button' )?.focus();
		};
		HTMLDialogElement.prototype.close = function () {
			this.removeAttribute( 'open' );
		};
		window.fetch = jest.fn().mockResolvedValue( { ok: true } );
		// The checkout iframe never loads here, so closing it finds no checkout data to report.
		jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		window.newspackBlocksModal = {
			newspack_class_prefix: 'newspack-blocks',
			labels: { checkout_modal_title: 'Checkout', thankyou_modal_title: 'Thank you' },
			processing_payment_messages: [],
		};
		require( './modal' );
		checkout = document.getElementById( 'newspack_modal_checkout' );
		trigger = document.getElementById( 'trigger' );
	} );

	afterAll( () => {
		Object.assign( HTMLDialogElement.prototype, nativeDialog );
		jest.restoreAllMocks();
		delete window.fetch;
	} );

	/**
	 * Open checkout the way a reader does, from a checkout button.
	 */
	function openFromTrigger() {
		trigger.focus();
		trigger.closest( 'form' ).dispatchEvent( Object.assign( new Event( 'submit', { cancelable: true } ), { submitter: trigger } ) );
	}

	it( 'opens checkout as a modal dialog, with focus off its Close button', () => {
		openFromTrigger();

		expect( checkout.dataset.state ).toBe( 'open' );
		expect( checkout.open ).toBe( true );
		expect( document.activeElement ).toBe( checkout.querySelector( 'h2' ) );

		window.newspackCloseModalCheckout();
	} );

	it( 'closes the dialog and returns focus to the button that opened it', () => {
		openFromTrigger();
		const close = checkout.querySelector( '.newspack-blocks__modal__close' );
		close.focus();

		close.click();

		expect( checkout.dataset.state ).toBe( 'closed' );
		expect( checkout.open ).toBe( false );
		expect( document.activeElement ).toBe( trigger );
	} );

	it( 'closes on Escape', () => {
		openFromTrigger();
		const cancel = new Event( 'cancel', { cancelable: true } );

		checkout.dispatchEvent( cancel );

		expect( cancel.defaultPrevented ).toBe( true );
		expect( checkout.open ).toBe( false );
	} );

	it( 'opens again when the browser closes it during a payment, with focus off its Close button', () => {
		openFromTrigger();
		const spinner = checkout.querySelector( '.newspack-blocks__spinner' );
		spinner.setAttribute( 'aria-busy', 'true' );

		checkout.removeAttribute( 'open' );
		checkout.dispatchEvent( new Event( 'close' ) );

		expect( checkout.open ).toBe( true );
		expect( document.activeElement ).toBe( checkout.querySelector( 'h2' ) );

		spinner.removeAttribute( 'aria-busy' );
		window.newspackCloseModalCheckout();
	} );
} );
