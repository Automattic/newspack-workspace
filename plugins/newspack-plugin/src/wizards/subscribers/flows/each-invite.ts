/**
 * Run one invitation write per address, one after another, and report how many landed.
 *
 * Each invite endpoint reads the subscription's whole invitation list, changes it and
 * saves it back, so two writes in flight at once overwrite each other: the writes
 * must not overlap. A batch that lands in part still counts as done; only a batch
 * where nothing landed rejects, with the first error, so the confirm modal can show it.
 *
 * @param invites The invitations, each carrying `email`.
 * @param write   The write for one address, returning a promise.
 * @return How many succeeded and failed, and the first failure.
 */
export default async function eachInvite(
	invites: Array< { email: string } >,
	write: ( email: string ) => Promise< unknown >
): Promise< { done: number; failed: number; firstError: Error | null } > {
	let done = 0;
	let firstError: Error | null = null;
	for ( const invite of invites ) {
		try {
			await write( invite.email );
			done++;
		} catch ( error ) {
			firstError = firstError || ( error as Error );
		}
	}
	if ( 0 === done && firstError ) {
		throw firstError;
	}
	return { done, failed: invites.length - done, firstError };
}
