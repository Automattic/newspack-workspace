import { notifyError, notifySuccess } from '../notices';

interface RunBulkOptions {
	/** Invoked after all ops settle. */
	refresh?: () => void;
	/** Pre-rendered success notice. */
	successPlural: ( successCount: number ) => string;
	/** Pre-rendered failure notice. */
	failurePlural: ( failedCount: number ) => string;
}

/**
 * Run `op( item )` in parallel against each item, swallow per-item
 * rejections, then dispatch a single aggregated success/failure notice.
 * Callers must pre-filter against any `isEligible` predicate.
 *
 * `options` is documented on `RunBulkOptions` above.
 *
 * @param items
 * @param op
 * @return Outcome counts.
 */
export async function runBulk< Item >(
	items: Item[],
	op: ( item: Item ) => Promise< unknown >,
	{ refresh, successPlural, failurePlural }: RunBulkOptions
): Promise< { failed: Item[]; succeeded: number } > {
	const failed: Item[] = [];
	await Promise.all(
		// `Promise.resolve().then(() => op(item))` adapts a possibly-sync
		// throw or non-Promise return into a rejection so .catch() always sees it.
		items.map( item =>
			Promise.resolve()
				.then( () => op( item ) )
				.catch( () => {
					failed.push( item );
				} )
		)
	);
	if ( typeof refresh === 'function' ) {
		refresh();
	}
	const succeeded = items.length - failed.length;
	if ( failed.length === 0 ) {
		notifySuccess( successPlural( succeeded ) );
	} else {
		notifyError( failurePlural( failed.length ) );
	}
	return { failed, succeeded };
}
