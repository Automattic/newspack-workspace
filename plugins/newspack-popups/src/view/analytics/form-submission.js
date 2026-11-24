import { getEventPayload, getRawId, sendEvent } from '../utils';

/**
 * Forms whose submission counts as a prompt interaction: the four blocks the
 * data API derives a prompt's `action_type` from (`$watched_blocks` in
 * `Newspack_Popups_Data_Api`), found by the selectors the content gate uses
 * (`addFormInputs()` in newspack-plugin's `content-gate/gate.js`). The three
 * lists are kept in step by hand, so a change to one belongs in all of them.
 * A form unrelated to the prompt's own blocks (a search block, a third-party
 * embed) is never counted, because it is not a conversion of that intent.
 */
const FORM_SELECTORS = [
	'.newspack-registration form',
	'.wp-block-newspack-blocks-checkout-button form',
	'.wp-block-newspack-blocks-donate form',
	'.newspack-newsletters-subscribe form',
];

/**
 * Send a GA event when a Newspack form inside a prompt is submitted.
 *
 * The blocks handle their own `submit`; some cancel the native one with
 * `preventDefault()`, which does not stop sibling listeners, so this one
 * records the attempt without changing what the block does with it. reCAPTCHA
 * v2 is the exception: it holds the first submit and re-submits once the
 * challenge passes, so an abandoned challenge is not counted.
 *
 * The event carries the prompt's own `action_type`, not one derived from the
 * form as the content gate does. Insights groups each row by its own
 * `action_type`, and a prompt holding more than one watched block prints
 * `'undefined'`, so a per-form value would file that prompt's submissions under
 * an intent whose impressions sit in another row. Like the gate, it counts the
 * attempt rather than the outcome.
 *
 * @param {Array} prompts Array of prompts loaded in the DOM.
 */
export const manageFormSubmissions = prompts => {
	prompts.forEach( prompt => {
		const forms = [ ...prompt.querySelectorAll( FORM_SELECTORS.join( ', ' ) ) ];
		const handleEvent = () => {
			const payload = getEventPayload( 'form_submission', getRawId( prompt.getAttribute( 'id' ) ) );
			sendEvent( payload );
		};

		forms.forEach( form => form.addEventListener( 'submit', handleEvent ) );
	} );
};
