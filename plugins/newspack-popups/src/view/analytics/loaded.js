import { getEventPayload, getRawId, sendEvent } from '../utils';

/**
 * Execute a callback function to send a GA event when a prompt becomes unhidden.
 *
 * @param {Function} handleEvent Callback function to execute when the prompt becomes eligible for display.
 * @return {MutationObserver} Observer instance.
 */
const getObserver = handleEvent => {
	return new MutationObserver( mutations => {
		mutations.forEach( mutation => {
			if (
				mutation.attributeName === 'amp-access-hide' &&
				mutation.type === 'attributes' &&
				! mutation.target.hasAttribute( 'amp-access-hide' )
			) {
				handleEvent();
			}
		} );
	} );
};

/**
 * Event fired as soon as a prompt is loaded in the DOM.
 *
 * @param {Array} prompts Array of prompts loaded in the DOM.
 */
export const manageLoadedEvents = prompts => {
	prompts.forEach( prompt => {
		const handleEvent = () => {
			const payload = getEventPayload( 'loaded', getRawId( prompt.getAttribute( 'id' ) ) );
			sendEvent( payload );
		};

		getObserver( handleEvent ).observe( prompt, { attributes: true } );
	} );
};
