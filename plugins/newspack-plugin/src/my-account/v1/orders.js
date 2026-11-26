/**
 * Confirm before cancelling an order, from the Orders list's row menu or the order page.
 * The cancel link goes straight to WooCommerce, so the click opens the confirmation
 * modal instead and hands it the link's URL. Without the modal, the link cancels as is.
 */

const CANCEL_LINK_SELECTOR = ':is(.newspack-my-account__order-actions, .newspack-my-account__order-header) a.cancel';

document.addEventListener( 'click', event => {
	const cancelLink = event.target.closest?.( CANCEL_LINK_SELECTOR );
	const modal = cancelLink && document.getElementById( 'newspack-my-account__confirm-order-cancellation' );
	const confirmLink = modal?.querySelector( 'a.newspack-ui__button--destructive' );
	if ( ! confirmLink ) {
		return;
	}
	event.preventDefault();
	confirmLink.setAttribute( 'href', cancelLink.getAttribute( 'href' ) );
	// Close the row menu and focus its toggle, so closing the modal returns focus to a
	// visible control rather than the now-hidden link.
	const dropdown = cancelLink.closest( '.newspack-ui__dropdown' );
	const toggle = dropdown?.querySelector( '.newspack-ui__dropdown__toggle' );
	if ( dropdown ) {
		dropdown.classList.remove( 'active' );
		toggle?.setAttribute( 'aria-expanded', 'false' );
		toggle?.focus();
	}
	modal.setAttribute( 'data-state', 'open' );
} );
