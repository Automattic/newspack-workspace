import { getEventPayload, sendEvent } from './utils';
import { getCheckoutData } from '../../utils';
/**
 * Event fired when switching between steps of the multi-step checkout flow.
 *
 * @param {string} action Action name for the event: 'continue' or 'back'.
 */

export const manageCheckoutSuccess = () => {
	if ( 'function' !== typeof window.gtag ) {
		return;
	}

	const params = getCheckoutData( 'modal-checkout-product-details' );
	const payload = getEventPayload( 'form_submission_success', params );
	sendEvent( payload );
};
