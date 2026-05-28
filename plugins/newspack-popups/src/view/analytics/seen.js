import { getEventPayload, sendEvent } from '../utils';

/**
 * Event fired when a prompt becomes visible in the viewport.
 */
export const manageSeenEvents = () => {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( ras => {
		ras.on( 'activity', ( { detail: { action, data } } ) => {
			if ( action === 'prompt_seen' ) {
				const { prompt_id: promptId } = data;
				const payload = getEventPayload( 'seen', promptId );
				sendEvent( payload );
			}
		} );
	} );
};
