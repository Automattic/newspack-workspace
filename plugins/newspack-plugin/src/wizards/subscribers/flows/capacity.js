/**
 * Seat-capacity helpers shared by the group flows that consume seats.
 *
 * Both the add and invite flows have to answer the same question — how many more
 * people can this group take — and the seat-limit flow has to answer its mirror
 * image: how far the limit can be pulled back. Keeping the arithmetic here means
 * the three cannot disagree with each other or with the server.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * How many more people a group can take.
 *
 * `seatsReserved` comes from the endpoint and already counts everyone holding a
 * seat plus every outstanding invitation, so an invitation cannot be sent into a
 * seat another invitation has already claimed.
 *
 * A seat limit of 0 means unlimited — not zero seats — so it yields Infinity,
 * which is safe to slice an array by. No group at all is the opposite case: with
 * nothing loaded there is nothing to know the capacity of, so it reports no room
 * and the callers withhold Add and Invite rather than offering them.
 *
 * @param {Object} group The group as returned by /groups/<id>.
 * @return {number} Seats available, Infinity when the group is uncapped, 0 when there is no group.
 */
export const seatsRemaining = group => {
	if ( ! group ) {
		return 0;
	}
	const limit = Number( group.seatLimit ) || 0;
	if ( limit <= 0 ) {
		return Infinity;
	}
	return Math.max( 0, limit - ( Number( group.seatsReserved ) || 0 ) );
};

/**
 * How many outstanding invitations are holding a seat.
 *
 * The endpoint's `seatsReserved` is members plus unexpired invitations, the count
 * the server enforces, so the difference is exactly the invitations it holds seats
 * for. The L0 list has no `seatsReserved`, and no invitations to report.
 *
 * @param {Object} group The group as returned by /groups/<id>.
 * @return {number} Pending invitations.
 */
export const pendingInvites = group => {
	if ( group?.seatsReserved === undefined ) {
		return 0;
	}
	return Math.max( 0, ( Number( group.seatsReserved ) || 0 ) - ( Number( group.members ) || 0 ) );
};

/**
 * Short seat count, "6 / 8" or "6 / Unlimited", for compact places such as the
 * subscription drawer. Counts members only: an invitation holds a seat for its
 * invitee but is not a member until it is accepted.
 *
 * @param {Object} group The group as returned by /groups/<id>.
 * @return {string} The seat count.
 */
export const seatCountText = group => {
	const limit = Number( group?.seatLimit ) || 0;
	const used = Number( group?.members ) || 0;
	if ( limit <= 0 ) {
		return `${ used } / ${ __( 'Unlimited', 'newspack-plugin' ) }`;
	}
	return `${ used } / ${ limit }`;
};

/**
 * The seats members use, "6 of 8 seats used", or "6 seats used, no limit" for an
 * uncapped group. Members only, like seatCountText().
 *
 * @param {Object} group The group as returned by /groups/<id>.
 * @return {string} The seat usage.
 */
export const seatUsageText = group => {
	const limit = Number( group?.seatLimit ) || 0;
	const used = Number( group?.members ) || 0;
	if ( limit <= 0 ) {
		return sprintf(
			/* translators: %s: number of members in a group with no seat limit. */
			_n( '%s seat used, no limit', '%s seats used, no limit', used, 'newspack-plugin' ),
			used
		);
	}
	return sprintf(
		/* translators: 1: number of members in the group, 2: the group's seat limit. */
		_n( '%1$s of %2$s seat used', '%1$s of %2$s seats used', limit, 'newspack-plugin' ),
		used,
		limit
	);
};

/**
 * The line under the seat usage about invitations: how many are pending, and,
 * when they hold every free seat, why no one else can be added or invited.
 * Empty when nothing is pending.
 *
 * @param {Object} group The group as returned by /groups/<id>.
 * @return {string} The invitation note, or ''.
 */
export const pendingInvitesText = group => {
	const pending = pendingInvites( group );
	if ( ! pending ) {
		return '';
	}
	if ( 0 === seatsRemaining( group ) ) {
		return sprintf(
			/* translators: %s: number of pending invitations holding the group's last free seats. */
			_n(
				'%s invitation is pending. Cancel it to free a seat.',
				'%s invitations are pending. Cancel one to free a seat.',
				pending,
				'newspack-plugin'
			),
			pending
		);
	}
	return sprintf(
		/* translators: %s: number of pending invitations. */
		_n( '%s invitation pending', '%s invitations pending', pending, 'newspack-plugin' ),
		pending
	);
};

/**
 * Trim, de-duplicate and drop blanks from a FormTokenField's tokens.
 *
 * @param {Array} tokens The raw tokens.
 * @return {string[]} Cleaned, unique entries.
 */
export const normalizeEmails = tokens => [ ...new Set( ( tokens || [] ).map( token => String( token ).trim() ).filter( Boolean ) ) ];
