/**
 * Shared outcome builders for subscription/group billing actions.
 *
 * Reactivation, free reactivation and payment-link sending behave identically
 * whether the target is an individual subscription or a group (both are billed
 * the same way); only WHERE the host applies the result differs — the person
 * profile mutates its subscriber/stored-group, the group detail mutates its
 * group and the owner's stored orders. Keeping the field/order/message shapes
 * here means the two screens cannot drift.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

// Days until the next renewal after a reactivation or restore.
const RENEWAL_DAYS = 30;
export const nextBillingIso = () => new Date( Date.now() + RENEWAL_DAYS * 86400000 ).toISOString().slice( 0, 10 );
const todayIso = () => new Date().toISOString().slice( 0, 10 );

// "<plan> reactivated." / "<plan> resubscribed." (matching the action's verb),
// with an optional confirmation-email suffix.
export const reactivatedMessage = ( plan, notify, verb = 'reactivate' ) => {
	const base =
		verb === 'resubscribe'
			? // translators: %s is the plan name.
			  sprintf( __( '%s resubscribed.', 'newspack-plugin' ), plan )
			: // translators: %s is the plan name.
			  sprintf( __( '%s reactivated.', 'newspack-plugin' ), plan );
	// translators: %s is the preceding confirmation sentence.
	return notify ? sprintf( __( '%s A confirmation email was sent.', 'newspack-plugin' ), base ) : base;
};

// Fresh status/billing fields + a billing-history order for resuming an on-hold
// target. `charged` records a real payment; a no-charge resume logs a
// non-financial "Reactivated" event instead.
export const buildReactivation = ( target, charged ) => ( {
	fields: { status: 'active', nextBillingDate: nextBillingIso() },
	order: {
		id: `ord_${ target.id }_${ Date.now() }`,
		date: todayIso(),
		amount: charged ? target.amount : null,
		type: charged ? __( 'Subscription payment', 'newspack-plugin' ) : __( 'Reactivated', 'newspack-plugin' ),
		subscriptionId: target.id,
	},
} );

// Fields + order for a free reactivation: indefinitely (a zero-amount comp with
// no future billing) or free for a number of cycles, then it bills at the
// catalog price. No payment is taken now.
export const buildFreeReactivation = ( target, freeCyclesRemaining ) => {
	const indefinite = freeCyclesRemaining === null;
	return {
		fields: {
			status: 'active',
			nextBillingDate: indefinite ? null : nextBillingIso(),
			amount: indefinite ? 0 : target.amount,
			freeCyclesRemaining,
		},
		order: {
			id: `ord_${ target.id }_${ Date.now() }`,
			date: todayIso(),
			amount: null,
			type: __( 'Reactivated', 'newspack-plugin' ),
			subscriptionId: target.id,
		},
	};
};

// A "Payment link sent" billing-history order (no amount) for the target.
export const buildPaymentLinkOrder = target => ( {
	id: `ord_link_${ Date.now() }`,
	date: todayIso(),
	amount: null,
	type: __( 'Payment link sent', 'newspack-plugin' ),
	subscriptionId: target.id,
} );

// A "Seat increase" billing-history order (free grant, no amount) for the group.
export const buildSeatIncreaseOrder = group => ( {
	id: `ord_seat_${ Date.now() }`,
	date: todayIso(),
	amount: null,
	type: __( 'Seat increase', 'newspack-plugin' ),
	subscriptionId: group.id,
} );

// A "Seat upgrade payment" order for a paid seat increase. `manual` marks an
// offline settlement (cheque, bank transfer) the admin recorded by hand.
export const buildSeatUpgradePaymentOrder = ( group, amount, { manual = false } = {} ) => ( {
	id: `ord_seatpay_${ Date.now() }`,
	date: todayIso(),
	amount,
	type: manual ? __( 'Seat upgrade payment (offline)', 'newspack-plugin' ) : __( 'Seat upgrade payment', 'newspack-plugin' ),
	subscriptionId: group.id,
} );
