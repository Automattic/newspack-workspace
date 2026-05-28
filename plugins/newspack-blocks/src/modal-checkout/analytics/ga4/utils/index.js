/**
 * Get a GA4 event payload for a given prompt.
 *
 * @param {string} action      Action name for the event.
 * @param {number} promptId    ID of the prompt
 * @param {Object} extraParams Additional key/value pairs to add as params to the event payload.
 *
 * @return {Object} Event payload.
 */

export const getEventPayload = ( action, extraParams = {} ) => {
	return { ...extraParams, action };
};

/**
 * Checkout data keys that can be included in the event payload.
 *
 * @type {string[]}
 */
const eventKeys = [
	'action',
	'action_type',
	'amount',
	'currency',
	'product_id',
	'product_type',
	'variation_id',
	'variation_ids',
	'is_variable',
	'is_grouped',
	'child_ids',
	'price_summary',
	'newspack_popup_id',
	'prompt_title',
	'gate_post_id',
	'recurrence',
	'referrer',
];

/**
 * Send an event to GA4.
 *
 * @param {Object} payload   Event payload.
 * @param {string} eventName Name of the event. Defaults to `np_modal_checkout_interaction` but can be overriden if necessary.
 */
export const sendEvent = ( payload, eventName = 'np_modal_checkout_interaction' ) => {
	if ( 'function' === typeof window.gtag && payload ) {
		const filteredPayload = {};
		for ( const key of eventKeys ) {
			if ( payload[ key ] ) {
				// Normalize boolean values to 'yes' or 'no'.
				if ( typeof payload[ key ] === 'boolean' ) {
					payload[ key ] = payload[ key ] ? 'yes' : 'no';
				} else if ( payload[ key ] === 'true' ) {
					payload[ key ] = 'yes';
				} else if ( payload[ key ] === 'false' ) {
					payload[ key ] = 'no';
				}
				filteredPayload[ key ] = payload[ key ].toString();
			}
		}
		window.gtag( 'event', eventName, filteredPayload );
	}
};
