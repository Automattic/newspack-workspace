/**
 * Run one invitation write per address and report how many landed.
 *
 * The invite endpoints take one address at a time, so a bulk action is several
 * requests. A batch that lands in part still counts as done; only a batch where
 * nothing landed rejects, with the first error, so the confirm modal can show it.
 *
 * @param {Array}    invites The invitations, each carrying `email`.
 * @param {Function} write   The write for one address, returning a promise.
 * @return {Promise<{done: number, failed: number}>} How many succeeded and failed.
 */
export default async function eachInvite( invites, write ) {
	const results = await Promise.allSettled( invites.map( invite => write( invite.email ) ) );
	const done = results.filter( result => 'fulfilled' === result.status ).length;
	if ( 0 === done && results.length ) {
		throw results[ 0 ].reason;
	}
	return { done, failed: results.length - done };
}
