/**
 * WordPress dependencies
 */
import { useRef } from '@wordpress/element';

/**
 * Posts to show while a changed query loads.
 *
 * With deduplication, every Posts Inserter above this one changes this block's query
 * when its own posts arrive, and core-data has no records for the new query until it
 * resolves. Keeping the posts last loaded in the meantime keeps the preview mounted.
 * Emptying the list instead tears the preview iframe down and builds a new one, once
 * per inserter above, which can exhaust the memory of a phone browser.
 *
 * @param {Object[]} postList  Posts for the current query. Empty while it loads.
 * @param {boolean}  isLoading Whether the current query is still loading.
 * @return {Object[]} Posts to show.
 */
const useSettledPostList = ( postList, isLoading ) => {
	const settledPostList = useRef( postList );
	if ( ! isLoading ) {
		settledPostList.current = postList;
	}
	return settledPostList.current;
};

export default useSettledPostList;
