declare global {
	interface Window {
		gtag?: ( ...args: unknown[] ) => void;
	}
}

/**
 * Values accepted in a GA4 modal-checkout event payload.
 */
export type Ga4EventParams = Record< string, string | number | boolean >;

/**
 * Get a GA4 event payload for a given prompt.
 *
 * @param action      Action name for the event.
 * @param extraParams Additional key/value pairs to add as params to the event payload.
 *
 * @return Event payload.
 */

export const getEventPayload = ( action: string, extraParams: object = {} ): Ga4EventParams => {
	return { ...extraParams, action };
};

/**
 * Checkout data keys that can be included in the event payload.
 */
const eventKeys: string[] = [
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
 * @param payload   Event payload.
 * @param eventName Name of the event. Defaults to `np_modal_checkout_interaction` but can be overriden if necessary.
 */
export const sendEvent = ( payload: Ga4EventParams, eventName = 'np_modal_checkout_interaction' ): void => {
	if ( 'function' === typeof window.gtag && payload ) {
		const filteredPayload: Record< string, string > = {};
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
