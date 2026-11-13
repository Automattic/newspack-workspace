import { getEventPayload, sendEvent } from './utils';
import { getCheckoutData } from '../../utils';

/**
 * Event fired when switching between steps of the multi-step checkout flow.
 *
 * @param {string} action Action name for the event: 'continue' or 'back'.
 */

export const managePagination = ( action = 'continue' ) => {
	if ( 'function' !== typeof window.gtag ) {
		return;
	}

	const {
		action_type,
		amount,
		currency,
		product_id,
		product_type,
		recurrence,
		referrer,
		variation_id = '',
		gate_post_id = '',
		newspack_popup_id = '',
		prompt_title = '',
	} = getCheckoutData( 'modal-checkout-product-details' );

	const params = {
		action_type,
		amount,
		currency,
		product_id,
		product_type,
		recurrence,
		referrer,
	};

	// There's only a variation ID for variable products, after you've selected one.
	if ( variation_id ) {
		params.variation_id = variation_id;
	}

	// If this checkout started from a content gate, add the gate ID to the payload.
	if ( gate_post_id ) {
		params.gate_post_id = gate_post_id;
	}

	// If this checkout started from a campaign prompt, add the popup ID and title to the payload.
	if ( newspack_popup_id ) {
		params.newspack_popup_id = newspack_popup_id;
	}
	if ( prompt_title ) {
		params.prompt_title = prompt_title;
	}

	const payload = getEventPayload( action, params );
	sendEvent( payload );
};
