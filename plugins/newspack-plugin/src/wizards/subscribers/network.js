/**
 * Network helpers for the Subscribers wizard.
 *
 * A reader on a Newspack Network can hold subscriptions that other sites of the
 * network manage. The profile endpoint returns them in the same `subscriptions`
 * list as this site's own, and tells them apart by a `managedOn` entry that only
 * network items carry. They are shown read-only: their IDs belong to the other
 * site, and the write endpoints resolve an ID against this site's subscriptions.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Whether a subscription is managed on another site of the network.
 *
 * @param {Object} subscription A profile subscription entry.
 * @return {boolean} True for a subscription another site manages.
 */
export const isNetworkSubscription = subscription => !! subscription?.managedOn;

/**
 * Whether the profile has anything to say in its Payment methods section.
 *
 * The saved cards are this site's. A reader who holds subscriptions only on
 * other sites, with no card here and no group, would see an empty "No saved
 * payment methods" for something this site was never asked to hold, so the
 * section is dropped. Any local subscription, group, or saved card keeps it, and
 * so does a reader with nothing anywhere: there, the empty state is accurate.
 *
 * @param {Object} subscriber The profile payload.
 * @return {boolean} Whether to show the section.
 */
export const shouldShowPaymentMethods = subscriber => {
	const subscriptions = subscriber?.subscriptions || [];
	const hasNetworkSubscription = subscriptions.some( isNetworkSubscription );
	const hasLocalSubscription = subscriptions.some( subscription => ! isNetworkSubscription( subscription ) );
	const hasGroup = ( subscriber?.groups || [] ).length > 0;
	const hasPaymentMethod = ( subscriber?.paymentMethods || [] ).length > 0;
	return ! ( hasNetworkSubscription && ! hasLocalSubscription && ! hasGroup && ! hasPaymentMethod );
};

// The header badge for a reader whose account the network created. Informational
// rather than a status colour: it describes where the account came from, not a
// state that needs attention.
export const networkReaderBadge = { label: __( 'Network', 'newspack-plugin' ), intent: 'informational' };
