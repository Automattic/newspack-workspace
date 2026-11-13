import { getEventPayload, sendEvent } from './utils';

/**
 * Execute a callback function to send a GA event when a prompt is dismissed.
 *
 * @param {Object} data Information about the purchase being made.
 */
export const manageOpened = data => {
	if ( 'function' !== typeof window.gtag ) {
		return;
	}

	let action = 'opened';

	const { amount = '', is_variable = '', is_grouped = '', price = '', variation_id = '' } = data;

	const params = {
		...data,
	};

	// On the first variable screen, there may not be a price so we want to check for it.
	if ( amount || price ) {
		params.amount = amount ? amount : price;
	}

	// Change the action when opening the initial variation modal.
	if ( is_variable && ! variation_id ) {
		action = 'opened_variations';
	}
	if ( is_grouped ) {
		action = 'opened_grouped';
	}

	const payload = getEventPayload( action, params );

	sendEvent( payload );
};
