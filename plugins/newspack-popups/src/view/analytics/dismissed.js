import { getEventPayload, getRawId, sendEvent } from '../utils';

/**
 * Execute a callback function to send a GA event when a prompt is dismissed.
 *
 * @param {Array} prompts Array of prompts loaded in the DOM.
 */

export const manageDismissals = prompts => {
	prompts.forEach( prompt => {
		const closeButton = prompt.querySelector( '.newspack-lightbox__close' );
		const forms = [ ...prompt.querySelectorAll( '.newspack-popup-container form' ) ];
		if ( closeButton ) {
			const handleEvent = () => {
				const payload = getEventPayload( 'dismissed', getRawId( prompt.getAttribute( 'id' ) ) );
				sendEvent( payload );
			};

			closeButton.addEventListener( 'click', handleEvent );

			// If a form inside an overlay prompt is submitted, closing it should not result in a `dismissed` action.
			forms.forEach( form => {
				form.addEventListener( 'submit', () => closeButton.removeEventListener( 'click', handleEvent ) );
			} );
		}
	} );
};
