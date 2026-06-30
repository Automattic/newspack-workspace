/**
 * Shared billing-history helpers.
 *
 * Both the person profile (subscription/group cards) and the group detail
 * drawer derive "last payment" / "cancelled on" facts from the same order
 * stream, so the logic lives here to stay consistent.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { fmtDate } from '../format';

// Latest order date of a given type for a subscription/group id, scanning the
// orders that power billing history (literal, untranslated type strings — the
// same ones mock-subscribers.js derives nextBillingDate from).
export const latestOrderDate = ( orders, subscriptionId, type ) =>
	( orders || [] )
		.filter( o => o.subscriptionId === subscriptionId && o.type === type )
		.map( o => o.date )
		.sort()
		.pop() || null;

// "Last payment" value for a card row: the latest payment date, or "Free access"
// when none is on record (a comp in this prototype). Pending payment links never
// reach this row — they render a dedicated call-to-action card instead.
export const lastPaidValue = ( orders, subscriptionId ) => {
	const paid = latestOrderDate( orders, subscriptionId, 'Subscription payment' );
	return paid ? fmtDate( paid ) : __( 'Free access', 'newspack-plugin' );
};
