/**
 * "Cancel order" on the Orders list and on the order page asks for confirmation:
 * the link opens a modal whose confirm button carries that order's cancel URL,
 * so nothing is cancelled until the reader confirms.
 */

const CANCEL_URL = 'https://example.test/cart/?cancel_order=true&order_id=84&_wpnonce=abc';

beforeAll( () => {
	jest.isolateModules( () => {
		require( './orders' );
	} );
} );

/**
 * Render a cancel link in the given wrapper, next to the confirmation modal.
 *
 * @param {string} wrapperClass Class of the element that holds the order's actions.
 * @return {Object} The cancel link and the modal.
 */
function renderCancelLink( wrapperClass ) {
	document.body.innerHTML = `
		<div class="newspack-ui__dropdown active ${ wrapperClass }">
			<button type="button" class="newspack-ui__dropdown__toggle" aria-expanded="true">Actions</button>
			<a class="newspack-ui__button cancel" href="${ CANCEL_URL }">Cancel order</a>
		</div>
		<dialog id="newspack-my-account__confirm-order-cancellation" data-state="closed">
			<a class="newspack-ui__button newspack-ui__button--destructive" href="#"><span>Cancel order</span></a>
		</dialog>
	`;
	return {
		cancelLink: document.querySelector( 'a.cancel' ),
		modal: document.getElementById( 'newspack-my-account__confirm-order-cancellation' ),
	};
}

test.each( [ 'newspack-my-account__order-actions', 'newspack-my-account__order-header' ] )(
	'Cancel order in %s opens the confirmation instead of cancelling',
	wrapperClass => {
		const { cancelLink, modal } = renderCancelLink( wrapperClass );

		const click = new MouseEvent( 'click', { bubbles: true, cancelable: true } );
		cancelLink.dispatchEvent( click );

		expect( click.defaultPrevented ).toBe( true );
		expect( modal.getAttribute( 'data-state' ) ).toBe( 'open' );
		expect( modal.querySelector( '.newspack-ui__button--destructive' ).getAttribute( 'href' ) ).toBe( CANCEL_URL );
		// The menu closes, and its toggle takes focus so the modal hands focus back to it.
		const dropdown = cancelLink.closest( '.newspack-ui__dropdown' );
		const toggle = dropdown.querySelector( '.newspack-ui__dropdown__toggle' );
		expect( dropdown.classList.contains( 'active' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( document.activeElement ).toBe( toggle );
	}
);

test( 'Cancel order follows its link when the page has no confirmation modal', () => {
	const { cancelLink, modal } = renderCancelLink( 'newspack-my-account__order-actions' );
	modal.remove();

	const click = new MouseEvent( 'click', { bubbles: true, cancelable: true } );
	cancelLink.dispatchEvent( click );

	expect( click.defaultPrevented ).toBe( false );
} );
