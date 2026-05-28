/**
 * Initialize functions for the Subscriptions page.
 */

import { domReady } from '../../utils';

domReady( function () {
	// Show a confirmation dialog before cancelling a subscription.
	const cancelButtons = Array.from(
		document.querySelectorAll( '.subscription_details .button.cancel, .newspack-my-account__subscription--actions .cancel' )
	);

	cancelButtons.forEach( cancelButton => {
		const confirmationModal = document.getElementById( 'newspack-my-account__confirm-subscription-cancellation' );
		if ( confirmationModal ) {
			cancelButton.classList.remove( 'wcs_block_ui_on_click' ); // Don't block subscription details table on click.
			const confirmCancel = event => {
				event.preventDefault();
				confirmationModal.setAttribute( 'data-state', 'open' );
			};
			cancelButton.addEventListener( 'click', confirmCancel );
		}
	} );
} );
