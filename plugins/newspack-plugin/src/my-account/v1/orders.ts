/**
 * Confirm before cancelling an order from the order page. The cancel link goes straight
 * to WooCommerce, so the click opens the confirmation modal instead and hands it the
 * link's URL. Without the modal, the link cancels as is.
 */

document.addEventListener( 'click', ( event: Event ) => {
	const cancelLink = ( event.target as HTMLElement ).closest?.( '.newspack-my-account__order-header a.cancel' );
	const modal = cancelLink && document.getElementById( 'newspack-my-account__confirm-order-cancellation' );
	const confirmLink = modal?.querySelector( 'a.newspack-ui__button--destructive' );
	if ( ! confirmLink ) {
		return;
	}
	event.preventDefault();
	// confirmLink is derived from modal, which is derived from cancelLink, so both are present here.
	confirmLink.setAttribute( 'href', cancelLink!.getAttribute( 'href' ) as string );
	modal!.setAttribute( 'data-state', 'open' );
} );
