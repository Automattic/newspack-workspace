/**
 * The payment-action write calls behind the person profile's subscription
 * cards: re-point a subscription at another saved card, refund and/or cancel,
 * change the plan, and set a customer's default card.
 *
 * These follow the wizard's write conventions (see the note on use-group.js):
 * the nonce rides apiFetch's middleware, server errors surface verbatim, and
 * every mutation is awaited and followed by a profile refetch rather than
 * rendered optimistically — the server recalculates totals, refuses expired
 * cards, and can decline a refund, so the response is the truth.
 */

/**
 * WordPress dependencies.
 */
import { useCallback, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const BASE = '/newspack/v1/wizard/newspack-subscribers';

/**
 * The payment write calls for one subscriber's profile.
 *
 * Every write carries the profile's own user ID as `customer_id`: the server
 * cross-checks it against the target's real owner, so a stale tab or a copied
 * ID cannot move a different reader's money.
 *
 * @param {number} subscriberId The profile's user ID.
 * @return {Object} The calls, each returning a promise that rejects with the
 *                  server's own error message.
 */
export function usePaymentActions( subscriberId ) {
	const customerId = Number( subscriberId );
	const changePaymentMethod = useCallback(
		( subscriptionId, tokenId ) =>
			apiFetch( {
				path: `${ BASE }/subscriptions/${ subscriptionId }/payment-method`,
				method: 'POST',
				data: { customer_id: customerId, token_id: tokenId },
			} ),
		[ customerId ]
	);
	const refund = useCallback(
		( subscriptionId, { refund: doRefund = false, cancel = false, expectedAmount = null } ) =>
			apiFetch( {
				path: `${ BASE }/subscriptions/${ subscriptionId }/refund`,
				method: 'POST',
				data: {
					customer_id: customerId,
					refund: doRefund,
					cancel,
					// The amount the admin was promised; the server refuses with a 409
					// if the real balance drifted since the profile loaded.
					...( doRefund && null !== expectedAmount ? { expected_amount: expectedAmount } : {} ),
				},
			} ),
		[ customerId ]
	);
	const changePlan = useCallback(
		( subscriptionId, productId ) =>
			apiFetch( {
				path: `${ BASE }/subscriptions/${ subscriptionId }/plan`,
				method: 'POST',
				data: { customer_id: customerId, product_id: productId },
			} ),
		[ customerId ]
	);
	const fetchPlanOptions = useCallback( subscriptionId => apiFetch( { path: `${ BASE }/subscriptions/${ subscriptionId }/plan-options` } ), [] );
	const setDefaultPaymentMethod = useCallback(
		tokenId => apiFetch( { path: `${ BASE }/payment-methods/${ tokenId }/default`, method: 'POST', data: { customer_id: customerId } } ),
		[ customerId ]
	);
	return useMemo(
		() => ( { changePaymentMethod, refund, changePlan, fetchPlanOptions, setDefaultPaymentMethod } ),
		[ changePaymentMethod, refund, changePlan, fetchPlanOptions, setDefaultPaymentMethod ]
	);
}

/**
 * The cards a subscription could be re-pointed to: saved on the same gateway
 * the subscription charges through, and not expired. The current card is
 * included (the picker shows it selected); an expired current card is not — it
 * exists to be switched away from, not to.
 *
 * @param {Object} subscription   A profile subscription entry.
 * @param {Array}  paymentMethods The subscriber's saved payment methods.
 * @return {Array} The usable cards.
 */
export const usableCardsFor = ( subscription, paymentMethods ) =>
	( paymentMethods || [] ).filter( pm => pm.gatewayId === subscription.paymentGatewayId && ! pm.isExpired );

/**
 * Whether the change-payment action is offerable on a subscription: the
 * subscription charges a resolvable saved card, and there is another usable
 * card to switch to.
 *
 * @param {Object} subscription   A profile subscription entry.
 * @param {Array}  paymentMethods The subscriber's saved payment methods.
 * @return {boolean} Whether to offer the action.
 */
export const canChangePaymentMethod = ( subscription, paymentMethods ) =>
	!! subscription.paymentTokenId &&
	! subscription.isManual &&
	usableCardsFor( subscription, paymentMethods ).some( pm => pm.id !== subscription.paymentTokenId );

/**
 * What a refund-flow submission actually does — the one derivation that alone
 * decides whether money moves, extracted so it can be pinned by tests.
 *
 * `cancelOnly` (no live refundable payment) always means cancel and never
 * refund, whatever `choice` holds; otherwise the three-way choice maps
 * directly.
 *
 * @param {string}  choice     'refund-only' | 'cancel-only' | 'refund-cancel'.
 * @param {boolean} cancelOnly Whether the flow collapsed to a plain cancel.
 * @return {{ willRefund: boolean, willCancel: boolean }} The intent.
 */
export const refundIntent = ( choice, cancelOnly ) => ( {
	willRefund: ! cancelOnly && 'cancel-only' !== choice,
	willCancel: cancelOnly || 'refund-only' !== choice,
} );
