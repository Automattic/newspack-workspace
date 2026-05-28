/* globals newspack_my_account */
/**
 * Internal dependencies.
 */
import { domReady, registerElementActivity, registerCheckoutActivity } from '../utils';

/**
 * Get the subscription ID from the element's href attribute.
 *
 * @param {Element} element The element to get the subscription ID from.
 *
 * @return {string|null} The subscription ID or null if no subscription ID is found.
 */
const getSubscriptionIdFromHref = element => {
	const match = element.getAttribute( 'href' ).match( /subscription_id=(\d+)/ );
	return match ? match[ 1 ] : null;
};

domReady( function () {
	// Add "name" attribute to My Account forms for analytics purposes.
	const url = new URL( window.location.href );
	if ( url.pathname.includes( 'edit-address' ) ) {
		const form = document.querySelector( '.woocommerce-MyAccount-content form' );
		if ( form && ! form.name ) {
			form.setAttribute( 'name', url.pathname.includes( 'billing' ) ? 'billing_address' : 'shipping_address' );
		}
	}

	// Track when the user cancels a subscription.
	registerElementActivity( '.subscription_details .button.cancel', 'subscription_cancelled', element => ( {
		subscription_id: getSubscriptionIdFromHref( element ),
	} ) );

	// Track when the user reactivates a subscription.
	registerElementActivity( '.subscription_details .button.reactivate', 'subscription_reactivated', element => ( {
		subscription_id: getSubscriptionIdFromHref( element ),
	} ) );

	// Track when a user switches a subscription (upgrade or downgrade).
	if ( newspack_my_account.is_switch_subscription_checkout_page ) {
		registerCheckoutActivity( 'subscription_switched', () => ( {
			subscription_id: newspack_my_account.cart_switch_subscriptions_summary?.subscription_id,
			upgraded_or_downgraded: newspack_my_account.cart_switch_subscriptions_summary?.upgraded_or_downgraded,
		} ) );
	}

	// Track when a payment method is deleted.
	registerElementActivity( '.payment-method .button.delete', 'payment_method_deleted' );

	// Track when a payment method is added.
	registerElementActivity( 'form#add_payment_method', 'payment_method_added', element => ( {
		payment_method: element.querySelector( 'input[name="payment_method"]' )?.value,
	} ) );

	// Track when the user changes the payment method for a subscription via the checkout page.
	const orderReviewForm = document.querySelector( '#order_review' );
	const changePaymentInput = orderReviewForm?.querySelector( 'input[name="woocommerce_change_payment"]' );
	if ( orderReviewForm && changePaymentInput ) {
		registerElementActivity( orderReviewForm, 'payment_method_changed', () => ( {
			subscription_id: changePaymentInput.value,
			update_all_subscriptions: orderReviewForm.querySelector( '#update_all_subscriptions_payment_method' )?.checked,
		} ) );
	}

	// Track when a user orders a product again.
	if ( newspack_my_account.is_reorder_checkout_page ) {
		if ( newspack_my_account.cart_reorder_summary?.early_renewal ) {
			registerCheckoutActivity( 'subscription_renewal_early', () => ( {
				subscription_id: newspack_my_account.cart_reorder_summary?.early_renewal?.subscription_id,
			} ) );
		} else {
			registerCheckoutActivity( 'product_reordered', () => ( {
				order_id: newspack_my_account.cart_reorder_summary?.order_id,
				product_id: newspack_my_account.cart_reorder_summary?.product_id,
			} ) );
		}
	}
} );
