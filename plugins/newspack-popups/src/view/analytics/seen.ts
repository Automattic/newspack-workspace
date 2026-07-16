import { getEventPayload, sendEvent } from '../utils';

/**
 * Event fired when a prompt becomes visible in the viewport.
 */
export const manageSeenEvents = (): void => {
	window.newspackRAS = window.newspackRAS || [];
	window.newspackRAS.push( ras => {
		ras.on( 'activity', ( { detail: { action, data } } ) => {
			if ( action === 'prompt_seen' ) {
				// `data` is `Record<string, unknown>` by design (RAS activity payloads are
				// untyped across the event bus); narrow at this boundary.
				const { prompt_id: promptId } = data as { prompt_id: number };
				const payload = getEventPayload( 'seen', promptId );
				sendEvent( payload );
			}
		} );
	} );
};
