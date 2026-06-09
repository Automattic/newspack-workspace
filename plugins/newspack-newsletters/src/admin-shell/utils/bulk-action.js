import { notifyError, notifySuccess } from '../notices';

/**
 * Run `op( item )` in parallel against each item, swallow per-item
 * rejections, then dispatch a single aggregated success/failure notice.
 * Callers must pre-filter against any `isEligible` predicate.
 *
 * @param {Array<Object>}                        items
 * @param {( item: Object ) => Promise<unknown>} op
 * @param {Object}                               options
 * @param {() => void}                           options.refresh       Invoked after all ops settle.
 * @param {( successCount: number ) => string}   options.successPlural Pre-rendered success notice.
 * @param {( failedCount: number ) => string}    options.failurePlural Pre-rendered failure notice.
 * @return {Promise<{ failed: Array<Object>, succeeded: number }>} Outcome counts.
 */
export async function runBulk( items, op, { refresh, successPlural, failurePlural } ) {
	const failed = [];
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
