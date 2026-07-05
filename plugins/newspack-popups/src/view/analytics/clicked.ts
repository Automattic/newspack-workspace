import { getEventPayload, getRawId, sendEvent } from '../utils';

/**
 * Send a GA event when a link inside a prompt is clicked.
 *
 * @param {Array} prompts Array of prompts loaded in the DOM.
 */

export const manageClickedEvents = ( prompts: PromptElement[] ): void => {
	prompts.forEach( prompt => {
		const anchorLinks = [ ...prompt.querySelectorAll< HTMLAnchorElement >( '.newspack-popup-container a' ) ];
		const handleEvent = ( e: MouseEvent ) => {
			const extraParams: { action_value?: string | null } = {};

			const currentTarget = e.currentTarget as HTMLAnchorElement | null;
			if ( currentTarget?.href && '#' !== currentTarget?.href ) {
				extraParams.action_value = currentTarget.getAttribute( 'href' );
			}

			const payload = getEventPayload( 'clicked', getRawId( prompt.getAttribute( 'id' )! ), extraParams );
			sendEvent( payload );
		};

		anchorLinks.forEach( link => link.addEventListener( 'click', handleEvent ) );
	} );
};
