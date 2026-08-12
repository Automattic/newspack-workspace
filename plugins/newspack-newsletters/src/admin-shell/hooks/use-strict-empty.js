/**
 * Whether to show the empty state rather than the DataView.
 *
 * Strict-empty only: true when the *unfiltered* collection is empty. A search
 * or filter matching nothing keeps the DataView's built-in "no results"
 * treatment, which tells the reader their query was too narrow.
 *
 * @param {Object}  options
 * @param {boolean} options.hasLoadedOnce  Whether a first fetch has resolved.
 * @param {boolean} options.isLoading      Whether a fetch is in flight.
 * @param {Object}  options.paginationInfo DataView pagination, for `totalItems`.
 * @param {?number} options.trashCount     Trashed items. Null on collections with no
 *                                         trash, such as the advertisers taxonomy, which
 *                                         never passes `trashCountPath` to
 *                                         `useCollectionData`. Absent means "no trash
 *                                         concept", not "trash is non-empty".
 * @param {Object}  options.view           The DataView view, for `search` and `filters`.
 * @return {boolean} Whether the collection is strictly empty.
 */
export default function useStrictEmpty( { hasLoadedOnce, isLoading, paginationInfo, trashCount, view } ) {
	return (
		hasLoadedOnce &&
		! isLoading &&
		paginationInfo.totalItems === 0 &&
		( trashCount ?? 0 ) === 0 &&
		! view.search &&
		( ! view.filters || view.filters.length === 0 )
	);
}
