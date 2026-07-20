const EVENT_PREFIX = 'newspack-ras';

export const EVENTS = {
	reader: 'reader' /* This event can soon be depecrated to use only 'data'. */,
	data: 'data',
	activity: 'activity',
	overlay: 'overlay',
	segment: 'segment',
	session: 'session',
} as const;

/**
 * Local names of the reader-activation events. Detail payloads are declared
 * in NewspackReaderActivationEventMap (newspack-scripts/types/newspack-globals.d.ts).
 */
export type ReaderActivationEvent = keyof NewspackReaderActivationEventMap;

const eventList: string[] = Object.values( EVENTS );

/**
 * Get the full event name given its local name.
 *
 * @param localEventName Local event name.
 *
 * @return Full event name or empty string if event name is not valid.
 */
function getEventName( localEventName: string ): string {
	if ( ! eventList.includes( localEventName ) ) {
		return '';
	}
	return `${ EVENT_PREFIX }-${ localEventName }`;
}

/**
 * Emit a reader activation event.
 *
 * @param event Local event name.
 * @param data  Data to be emitted.
 */
export function emit< K extends ReaderActivationEvent >( event: K, data: NewspackReaderActivationEventMap[ K ] ): void {
	const eventName = getEventName( event );
	if ( ! eventName ) {
		throw new Error( 'Invalid event' );
	}
	window.dispatchEvent( new CustomEvent( eventName, { detail: data } ) );
}

/**
 * Attach an event listener given a local event name.
 *
 * @param event    Local event name.
 * @param callback Callback.
 */
export function on< K extends ReaderActivationEvent >(
	event: K,
	callback: ( event: CustomEvent< NewspackReaderActivationEventMap[ K ] > ) => void
): void {
	const eventName = getEventName( event );
	if ( ! eventName ) {
		throw new Error( 'Invalid event' );
	}
	window.addEventListener( eventName, callback as EventListener );
}

/**
 * Detach an event listener given a local event name.
 *
 * @param event    Local event name.
 * @param callback Callback.
 */
export function off< K extends ReaderActivationEvent >(
	event: K,
	callback: ( event: CustomEvent< NewspackReaderActivationEventMap[ K ] > ) => void
): void {
	const eventName = getEventName( event );
	if ( ! eventName ) {
		throw new Error( 'Invalid event' );
	}
	window.removeEventListener( eventName, callback as EventListener );
}
