/* globals newspack_my_account */

/**
 * Initialize functions for the Subscriptions page.
 */

import { domReady } from '../../utils';

domReady( function () {
	// Show a confirmation dialog before cancelling a subscription.
	const cancelButton = document.querySelector( '.subscription_details .button.cancel' );
	const { labels } = newspack_my_account || {};

	// Show a confirmation dialog before cancelling a subscription.
	if ( cancelButton ) {
		const confirmCancel = event => {
			const message = labels?.cancel_subscription_message || 'Are you sure you want to cancel this subscription?';

			// eslint-disable-next-line no-alert
			if ( ! confirm( message ) ) {
				event.preventDefault();
			}
		};
		cancelButton.addEventListener( 'click', confirmCancel );
	}
} );
