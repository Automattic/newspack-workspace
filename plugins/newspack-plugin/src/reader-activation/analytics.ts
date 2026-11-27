/**
 * A GA4 event payload under construction. Values are normalized to strings
 * before dispatch by sendEvent().
 */
type EventPayload = Record< string, unknown >;

/**
 * Callback building a GA4 event payload from a dispatched activity's data.
 */
type ActivityEventCallback = ( data: Record< string, unknown > ) => EventPayload;

/**
 * Get a GA4 event payload.
 *
 * @param payload Event payload.
 * @param data    Data from the dispatched reader data activity.
 *
 * @return Event payload.
 */
export const getEventPayload = ( payload: EventPayload = {}, data: Record< string, unknown > = {} ): EventPayload => {
	const eventPayload = { ...payload };
	if ( data?.newspack_popup_id ) {
		eventPayload.newspack_popup_id = data.newspack_popup_id;
	}
	if ( data?.gate_post_id ) {
		eventPayload.gate_post_id = data.gate_post_id;
	}
	if ( data?.sso ) {
		eventPayload.sso = data.sso;
	}
	return eventPayload;
};

/**
 * Send an event to GA4.
 *
 * @param payload   Event payload.
 * @param eventName Name of the event. Defaults to `np_reader_activation_interaction` but can be overriden if necessary.
 */

export const sendEvent = ( payload: EventPayload, eventName = 'np_reader_activation_interaction' ): void => {
	if ( 'function' === typeof window.gtag && payload ) {
		// Normalize boolean values to 'yes' or 'no'.
		for ( const key of Object.keys( payload ) ) {
			const value = payload[ key ];
			if ( typeof value === 'boolean' ) {
				payload[ key ] = value ? 'yes' : 'no';
			} else if ( value === 'true' ) {
				payload[ key ] = 'yes';
			} else if ( value === 'false' ) {
				payload[ key ] = 'no';
			}
			// Values are stringified via their own toString(), preserving the
			// pre-TS behavior (a null/undefined value throws here).
			payload[ key ] = ( payload[ key ] as { toString: () => string } ).toString();
		}
		window.gtag( 'event', eventName, payload );
	}
};

/**
 * Events to be sent to GA4 based on reader data activity dispatch.
 */
const activityEvents: Record< string, { cb: ActivityEventCallback; eventName: string } > = {};

/**
 * Register an event to be sent to GA4 based on a reader data activity dispatch.
 *
 * @param action    Name of the reader data action to register an event for.
 * @param cb        Callback function that returns the event payload.
 * @param eventName Name of the event to send. Defaults to `np_{action}`.
 */
export const registerActivityEvent = ( action: string, cb?: ActivityEventCallback, eventName?: string ): void => {
	if ( ! eventName ) {
		eventName = `np_${ action }`;
	}
	// If no callback is provided, use the activity data as the payload.
	if ( ! cb ) {
		cb = data => data;
	}
	activityEvents[ action ] = { cb, eventName };
};

/**
 * Register default events to be sent to GA4 based on reader data activity dispatch.
 */
const registerActivityEvents = (): void => {
	registerActivityEvent( 'reader_registered', data => ( {
		registration_method: data?.registration_method || 'unknown',
	} ) );
	registerActivityEvent( 'reader_logged_in', data => ( {
		login_method: data?.login_method || 'unknown',
	} ) );
	registerActivityEvent(
		'newsletter_signup',
		data => ( {
			newsletters_subscription_method: data?.newsletters_subscription_method || 'unknown',
			lists: data?.lists || [],
		} ),
		'np_newsletter_subscribed'
	);
	registerActivityEvent( 'subscription_cancelled' );
	registerActivityEvent( 'subscription_reactivated' );
	registerActivityEvent( 'subscription_switched' );
	registerActivityEvent( 'payment_method_deleted' );
	registerActivityEvent( 'payment_method_added' );
	registerActivityEvent( 'payment_method_changed' );
	registerActivityEvent( 'address_updated' );
	registerActivityEvent( 'product_reordered' );
	registerActivityEvent( 'subscription_renewal_early' );
};

/**
 * Initialize analytics listeners.
 *
 * @param ras Reader Activation Library.
 */
export default function init( ras: NewspackReaderActivation ): void {
	registerActivityEvents();

	ras.on( 'activity', function ( ev ) {
		const { action, data } = ev.detail;
		const activityEvent = activityEvents[ action ];
		if ( ! activityEvent ) {
			return;
		}
		const { cb, eventName } = activityEvent;
		const payload = cb( data );
		sendEvent( getEventPayload( payload, data ), eventName );
	} );
}
