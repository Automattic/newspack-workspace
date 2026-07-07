/**
 * Shared subscription-status helpers for the Subscribers Demo wizard.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

export const STATUS_LABELS = {
	active: __( 'Active', 'newspack-plugin' ),
	pending: __( 'Pending', 'newspack-plugin' ),
	'on-hold': __( 'On hold', 'newspack-plugin' ),
	cancelled: __( 'Cancelled', 'newspack-plugin' ),
};

export const STATUS_BADGE_LEVEL = {
	active: 'success',
	pending: 'info',
	'on-hold': 'warning',
	cancelled: 'error',
};

// Active first, then pending, then on-hold, then cancelled.
export const STATUS_RANK = { active: 0, pending: 1, 'on-hold': 2, cancelled: 3 };

/**
 * Reduce a subscriber's many subscription statuses to the badge(s) we show.
 *
 * Distinct statuses, ordered active-first. A cancelled subscription is hidden
 * whenever the subscriber still has any live (non-cancelled) one, since a past
 * cancellation alongside an active or on-hold plan is just noise; cancelled
 * only shows when every subscription is cancelled. Falls back to the stored
 * status when there are none on file.
 *
 * @param {string[]} statuses Per-subscription statuses (group and individual).
 * @param {string}   fallback Stored status to use when there are none.
 * @return {string[]} Ordered, distinct statuses to display.
 */
export const displayStatuses = ( statuses, fallback ) => {
	const present = ( statuses || [] ).filter( Boolean );
	if ( ! present.length ) {
		return fallback ? [ fallback ] : [];
	}
	let distinct = [ ...new Set( present ) ];
	if ( distinct.some( s => s !== 'cancelled' ) ) {
		distinct = distinct.filter( s => s !== 'cancelled' );
	}
	return distinct.sort( ( a, b ) => STATUS_RANK[ a ] - STATUS_RANK[ b ] );
};
