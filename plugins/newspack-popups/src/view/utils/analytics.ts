/* globals gtag, newspackPopupsData */

/** A GA4 event payload: the prompt's sanitized GA data plus any extra params and the action name. */
type GaEventPayload = NewspackPopupsGaData & Record< string, unknown >;

/**
 * Get a GA4 event payload for a given prompt.
 *
 * @param {string} action      Action name for the event.
 * @param {number} promptId    ID of the prompt
 * @param {Object} extraParams Additional key/value pairs to add as params to the event payload.
 *
 * @return {Object} Event payload.
 */
export const getEventPayload = ( action: string, promptId: number, extraParams: Record< string, unknown > = {} ): GaEventPayload | false => {
	if ( ! newspackPopupsData || ! newspackPopupsData[ promptId ] ) {
		return false;
	}

	return { ...newspackPopupsData[ promptId ], ...extraParams, action };
};

/**
 * Send an event to GA4.
 *
 * @param {Object} payload   Event payload.
 * @param {string} eventName Name of the event. Defaults to `np_prompt_interaction` but can be overriden if necessary.
 */
export const sendEvent = ( payload: GaEventPayload | false, eventName = 'np_prompt_interaction' ): void => {
	if ( 'function' === typeof gtag && payload ) {
		gtag( 'event', eventName, payload );
	}
};
