import { __ } from '@wordpress/i18n';

import { LAYOUT_CPT_SLUG } from '../../../utils/consts';
import useCollectionData from '../../hooks/use-collection-data';
import { buildQueryParams, toQueryString } from '../../utils/build-query';
import { isFetchAllPerPage, LAYOUTS_FETCH_ALL_CHUNK_SIZE } from '../../utils/per-page';

import type { CollectionData } from '../../hooks/use-collection-data';
import type { QueryView } from '../../types';
import type { LayoutItem } from './types';

const COLLECTION_PATH = `/wp/v2/${ LAYOUT_CPT_SLUG }`;
// `future` is excluded: layouts don't surface scheduling.
const DEFAULT_STATUSES = 'publish,private,draft,pending';

export function buildPath( view: QueryView | null ): string {
	if ( ! view ) {
		return '';
	}
	const params = buildQueryParams( view, {
		defaultPerPage: 12,
		fetchAllChunkSize: LAYOUTS_FETCH_ALL_CHUNK_SIZE,
		defaultStatuses: DEFAULT_STATUSES,
		// `offset` overrides `page` so page 1 can reserve slots for prebuilts.
		supportsOffset: true,
		// `content.raw` (not `.rendered`) — previews parse blocks client-side,
		// so skip the whole `the_content` chain. No `_links` either: see
		// the newsletters-list note for what it costs.
		extraParams: {
			_fields: 'id,status,title,date,modified,author,content.raw,meta,newspack_newsletters_author',
		},
		arrayParams: [ { viewKey: 'author', param: 'author' } ],
	} );
	return `${ COLLECTION_PATH }${ toQueryString( params ) }`;
}

/**
 * @param {Object|null} view          DataViews view state, or `null` to defer the fetch.
 * @param {number}      [mutationKey] Bump from the parent to force a refetch after a mutation.
 * @return {ReturnType<typeof useCollectionData>} Hook state.
 */
export default function useLayoutsData( view: QueryView | null, mutationKey = 0 ): CollectionData< LayoutItem > {
	return useCollectionData< LayoutItem >( {
		path: buildPath( view ),
		mutationKey,
		fetchAll: isFetchAllPerPage( view?.perPage ),
		errorMessage: __( 'Failed to load layouts. Please refresh the page.', 'newspack-newsletters' ),
		errorNoticeId: 'newspack-newsletters-layouts-list-fetch-error',
	} );
}
