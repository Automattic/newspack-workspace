/* global newspackMyAccountV1 */
/**
 * Implement modal checkout for My Account buttons.
 */

/**
 * Internal dependencies.
 */
import { domReady } from '../../utils';
import { registerModalCheckoutButton } from './utils';

window.newspackRAS = window.newspackRAS || [];

domReady( () => {
	/**
	 * Resubscribe.
	 */
	const resubscribe = document.querySelectorAll( '.resubscribe' );
	resubscribe.forEach( button => {
		registerModalCheckoutButton( button, newspackMyAccountV1.labels.resubscribe_title, 'resubscribe', data => {
			// Track the subscription reactivation.
			window.newspackRAS.push( [
				'subscription_reactivated',
				{
					subscription_id: data.subscription_ids?.[ 0 ],
				},
			] );
		} );
	} );

	/**
	 * Renewal early.
	 */
	const renewalEarly = document.querySelectorAll( '.subscription_renewal_early' );
	renewalEarly.forEach( button => {
		registerModalCheckoutButton( button, newspackMyAccountV1.labels.renewal_early_title, 'renewal_early', data => {
			// Track the renewal early.
			window.newspackRAS.push( [ 'renewal_early', { subscription_id: data.subscription_renewal } ] );
		} );
	} );

	/**
	 * Change payment method.
	 */
	const changePaymentMethod = document.querySelectorAll( '.change_payment_method' );
	changePaymentMethod.forEach( button => {
		registerModalCheckoutButton( button, newspackMyAccountV1.labels.change_payment_method_title, 'change_payment_method', data => {
			// Track the change payment method.
			window.newspackRAS.push( [ 'change_payment_method', { subscription_id: data.subscription_ids?.[ 0 ] } ] );
		} );
	} );

	/**
	 * Order again.
	 */
	const orderAgain = document.querySelectorAll( 'p.order-again a' );
	orderAgain.forEach( button => {
		registerModalCheckoutButton(
			button,
			null,
			'order_again',
			data => {
				// Track the reorder.
				window.newspackRAS.push( [ 'product_reordered', { order_id: data.order_id, product_id: data.product_id } ] );
			},
			null,
			false
		);
	} );

	/**
	 * Order pay.
	 */
	const orderPay = document.querySelectorAll( '.pay' );
	orderPay.forEach( button => {
		const subscriptionId = button.dataset?.subscriptionId;
		const action = button.dataset?.action || 'pay_order';
		registerModalCheckoutButton( button, button.dataset?.title || null, action, data => {
			// Track the action.
			window.newspackRAS.push( [
				action,
				{
					subscription_id: subscriptionId || data.subscription_renewal ? data.subscription_renewal : data.subscription_ids?.[ 0 ],
				},
			] );
		} );
	} );
} );
