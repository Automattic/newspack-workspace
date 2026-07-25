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
import { __ } from '@wordpress/i18n';

/**
 * How many more people a group can take.
 *
 * `seatsReserved` comes from the endpoint and already counts everyone holding a
 * seat plus every outstanding invitation, so an invitation cannot be sent into a
 * seat another invitation has already claimed.
 *
 * A seat limit of 0 means unlimited — not zero seats — so it yields Infinity,
 * which is safe to slice an array by.
 *
 * @param {Object} group The group as returned by /groups/<id>.
 * @return {number} Seats available, or Infinity when the group is uncapped.
 */
export const seatsRemaining = group => {
	const limit = Number( group?.seatLimit ) || 0;
	if ( limit <= 0 ) {
		return Infinity;
	}
	return Math.max( 0, limit - ( Number( group?.seatsReserved ) || 0 ) );
};

/**
 * Human text for a group's seat usage — "5 / 8", or "5 / Unlimited".
 *
 * "Used" counts committed seats — members plus outstanding invitations
 * (`seatsReserved`), the SAME basis as seatsRemaining(). Counting members only
 * would let the header read "3 / 5" (apparent room) while Add and Invite are
 * disabled because two pending invites already claim the last seats. Falls back to
 * the plain member count on the L0 list, which has no `seatsReserved` field.
 *
 * @param {Object} group The group as returned by /groups/<id>.
 * @return {string} The seat count.
 */
export const seatCountText = group => {
	const limit = Number( group?.seatLimit ) || 0;
	const used = Number( group?.seatsReserved ?? group?.members ) || 0;
	if ( limit <= 0 ) {
		return `${ used } / ${ __( 'Unlimited', 'newspack-plugin' ) }`;
	}
	return `${ used } / ${ limit }`;
};

/**
 * Trim, de-duplicate and drop blanks from a FormTokenField's tokens.
 *
 * @param {Array} tokens The raw tokens.
 * @return {string[]} Cleaned, unique entries.
 */
export const normalizeEmails = tokens => [ ...new Set( ( tokens || [] ).map( token => String( token ).trim() ).filter( Boolean ) ) ];
