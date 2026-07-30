/**
 * The person profile's subscription write calls (on-hold recovery).
 *
 * These follow the wizard's write conventions (see data/use-group.js on the
 * group-detail slice, which documents them): the nonce rides apiFetch's own
 * middleware, WP_Error messages come back written for a publisher and are
 * surfaced verbatim, and every mutation is awaited — the caller refetches the
 * subscriber rather than rendering the request optimistically, because the
 * server decides the outcome (a charge can fail, WCS recalculates the next
 * billing date).
 */

/**
 * WordPress dependencies.
 */
import { useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PATH = '/newspack/v1/wizard/newspack-subscribers/subscriptions';

/**
 * The on-hold recovery write calls, each returning a promise.
 *
 * @return {Object} The write calls.
 */
export function useSubscriptionActions() {
	return useMemo(
		() => ( {
			/**
			 * Reactivate an on-hold subscription.
			 *
			 * @param {number} subscriptionId The subscription ID.
			 * @param {string} mode           'free' (no payment) or 'charge' (renew
			 *                                against the saved payment method now).
			 * @return {Promise<{status: string, nextBillingDate: ?string}>} The outcome.
			 */
			reactivate: ( subscriptionId, mode ) => apiFetch( { path: `${ PATH }/${ subscriptionId }/reactivate`, method: 'POST', data: { mode } } ),
			/**
			 * Email the customer a link to pay the unpaid renewal order.
			 *
			 * @param {number} subscriptionId The subscription ID.
			 * @return {Promise<{paymentUrl: string, emailSent: boolean}>} The link.
			 */
			sendPaymentLink: subscriptionId => apiFetch( { path: `${ PATH }/${ subscriptionId }/payment-link`, method: 'POST' } ),
		} ),
		[]
	);
}
