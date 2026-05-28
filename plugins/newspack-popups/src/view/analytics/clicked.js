import { getEventPayload, getRawId, sendEvent } from '../utils';

/**
 * Send a GA event when a link inside a prompt is clicked.
 *
 * @param {Array} prompts Array of prompts loaded in the DOM.
 */

export const manageClickedEvents = prompts => {
	prompts.forEach( prompt => {
		const anchorLinks = [ ...prompt.querySelectorAll( '.newspack-popup-container a' ) ];
		const handleEvent = e => {
			const extraParams = {};

			if ( e.currentTarget?.href && '#' !== e.currentTarget?.href ) {
				extraParams.action_value = e.currentTarget.getAttribute( 'href' );
			}

			const payload = getEventPayload( 'clicked', getRawId( prompt.getAttribute( 'id' ) ), extraParams );
			sendEvent( payload );
		};

		anchorLinks.forEach( link => link.addEventListener( 'click', handleEvent ) );
	} );
};
