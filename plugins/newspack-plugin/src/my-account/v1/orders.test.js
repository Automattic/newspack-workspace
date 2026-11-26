/**
 * "Cancel order" on the order page asks for confirmation: the link opens a modal whose
 * confirm button carries the order's cancel URL, so nothing is cancelled until the
 * reader confirms.
 */

const CANCEL_URL = 'https://example.test/cart/?cancel_order=true&order_id=84&_wpnonce=abc';

beforeAll( () => {
	jest.isolateModules( () => {
		require( './orders' );
	} );
} );

/**
 * Render the order page header's cancel link next to the confirmation modal.
 *
 * @return {Object} The cancel link and the modal.
 */
function renderCancelLink() {
	document.body.innerHTML = `
		<header class="newspack-my-account__order-header">
			<a class="newspack-ui__button cancel" href="${ CANCEL_URL }">Cancel order</a>
		</header>
		<dialog id="newspack-my-account__confirm-order-cancellation" data-state="closed">
			<a class="newspack-ui__button newspack-ui__button--destructive" href="#"><span>Cancel order</span></a>
		</dialog>
	`;
	return {
		cancelLink: document.querySelector( 'a.cancel' ),
		modal: document.getElementById( 'newspack-my-account__confirm-order-cancellation' ),
	};
}

test( 'Cancel order opens the confirmation instead of cancelling', () => {
	const { cancelLink, modal } = renderCancelLink();

	const click = new MouseEvent( 'click', { bubbles: true, cancelable: true } );
	cancelLink.dispatchEvent( click );

	expect( click.defaultPrevented ).toBe( true );
	expect( modal.getAttribute( 'data-state' ) ).toBe( 'open' );
	expect( modal.querySelector( '.newspack-ui__button--destructive' ).getAttribute( 'href' ) ).toBe( CANCEL_URL );
} );

test( 'Cancel order follows its link when the page has no confirmation modal', () => {
	const { cancelLink, modal } = renderCancelLink();
	modal.remove();

	const click = new MouseEvent( 'click', { bubbles: true, cancelable: true } );
	cancelLink.dispatchEvent( click );

	expect( click.defaultPrevented ).toBe( false );
} );
