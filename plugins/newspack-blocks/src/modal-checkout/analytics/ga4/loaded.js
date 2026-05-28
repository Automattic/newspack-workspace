import { getEventPayload, sendEvent } from './utils';
import { domReady, getCheckoutData } from '../../utils';

/**
 * Event fired when the modal checkout content is loaded.
 */
export const manageLoaded = () => {
	domReady( function () {
		if ( 'function' !== typeof window.gtag ) {
			return;
		}
		const params = getCheckoutData( 'modal-checkout-product-details' );
		const payload = getEventPayload( 'loaded', params );

		sendEvent( payload );
	} );
};
