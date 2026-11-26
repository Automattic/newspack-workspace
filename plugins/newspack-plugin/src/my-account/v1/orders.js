/**
 * Confirm before cancelling an order from the order page. The cancel link goes straight
 * to WooCommerce, so the click opens the confirmation modal instead and hands it the
 * link's URL. Without the modal, the link cancels as is.
 */

document.addEventListener( 'click', event => {
	const cancelLink = event.target.closest?.( '.newspack-my-account__order-header a.cancel' );
	const modal = cancelLink && document.getElementById( 'newspack-my-account__confirm-order-cancellation' );
	const confirmLink = modal?.querySelector( 'a.newspack-ui__button--destructive' );
	if ( ! confirmLink ) {
		return;
	}
	event.preventDefault();
	confirmLink.setAttribute( 'href', cancelLink.getAttribute( 'href' ) );
	modal.setAttribute( 'data-state', 'open' );
} );
