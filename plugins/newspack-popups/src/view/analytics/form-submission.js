import { getEventPayload, getRawId, sendEvent } from '../utils';

/**
 * Send a GA event when a form inside a prompt is submitted.
 *
 * The blocks a prompt can hold (Reader Registration, Newsletter Subscribe,
 * Donate, Checkout Button) each handle their own `submit`, and most cancel the
 * native one. The event still reaches every listener on the form, so this one
 * records the attempt without changing what the block does with it. Insights
 * counts it alongside `seen`, `clicked` and `dismissed`, under the prompt's own
 * `action_type`, the same way the content gate reports its form submissions.
 *
 * @param {Array} prompts Array of prompts loaded in the DOM.
 */
export const manageFormSubmissions = prompts => {
	prompts.forEach( prompt => {
		const forms = [ ...prompt.querySelectorAll( '.newspack-popup-container form' ) ];
		const handleEvent = () => {
			const payload = getEventPayload( 'form_submission', getRawId( prompt.getAttribute( 'id' ) ) );
			sendEvent( payload );
		};

		forms.forEach( form => form.addEventListener( 'submit', handleEvent ) );
	} );
};
