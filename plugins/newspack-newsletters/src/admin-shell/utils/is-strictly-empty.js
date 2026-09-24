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
 * @param {?number} [options.trashCount=0] Trashed items. Omit the key entirely on a
 *                                         collection with no trash, such as the advertisers
 *                                         taxonomy, so the default makes the term inert. An
 *                                         explicit `null` means *unknown* and blocks a
 *                                         strict-empty result: collapsing it to zero flashes
 *                                         the empty state over a freshly trashed last item.
 * @param {Object}  options.view           The DataView view, for `search` and `filters`.
 * @return {boolean} Whether the collection is strictly empty.
 */
export default function isStrictlyEmpty( { hasLoadedOnce, isLoading, paginationInfo, trashCount = 0, view } ) {
	return !! (
		hasLoadedOnce &&
		! isLoading &&
		paginationInfo.totalItems === 0 &&
		trashCount === 0 &&
		! view.search &&
		( ! view.filters || view.filters.length === 0 )
	);
}
