import { getEventPayload, sendEvent } from './utils';
import { getCheckoutData } from '../../utils';

/**
 * Event fired when switching between steps of the multi-step checkout flow.
 *
 * @param {string} action Action name for the event: 'continue' or 'back'.
 */

export const manageCheckoutAttempt = () => {
	if ( 'function' !== typeof window.gtag ) {
		return;
	}

	const params = getCheckoutData( 'modal-checkout-product-details' );
	const payload = getEventPayload( 'form_submission', params );
	sendEvent( payload );
};

/**
 * Filter that WooCommerce Stripe Gateway's express checkout applies once per
 * approval, just before it sends the order, whichever of its buttons the reader
 * used (Apple Pay, Google Pay, Link, Amazon Pay). Those buttons are drawn in
 * Stripe's own frame and never click #place_order, so this is where the modal
 * can see the submission. It exists from gateway 9.2.0; if Stripe renames it,
 * express checkout purchases quietly stop sending the event.
 */
const EXPRESS_CHECKOUT_ORDER_FILTER = 'wcstripe.express-checkout.cart-place-order-extension-data';

/**
 * Send `form_submission` for express checkout payments, the way the
 * #place_order click does for the checkout form.
 *
 * Listens on window.wp.hooks, the registry Stripe's script shares with this
 * one. Stripe builds the order request from the filter's return value, so the
 * handler returns its input untouched even if sending the event fails, and a
 * missing or broken registry never stops the rest of the checkout script.
 * That is also why the registry is read here at call time: this script
 * declares no wp-hooks dependency, so importing @wordpress/hooks would turn
 * into a lookup when the bundle loads, outside this guard.
 */
export const registerExpressCheckoutAttempt = () => {
	const hooks = window.wp?.hooks;
	if ( 'function' !== typeof hooks?.addFilter ) {
		return;
	}
	try {
		hooks.addFilter( EXPRESS_CHECKOUT_ORDER_FILTER, 'newspack-blocks/modal-checkout', extensions => {
			try {
				manageCheckoutAttempt();
			} catch {
				// Analytics must never block the payment.
			}
			return extensions;
		} );
	} catch {
		// Losing the event is acceptable; losing the checkout script is not.
	}
};
