import { __ } from '@wordpress/i18n';

import { LAYOUT_CPT_SLUG } from '../../../utils/consts';
import useCollectionData from '../../hooks/use-collection-data';
import { buildQueryParams, toQueryString } from '../../utils/build-query';
import { isFetchAllPerPage } from '../../utils/per-page';

const COLLECTION_PATH = `/wp/v2/${ LAYOUT_CPT_SLUG }`;
// `future` is excluded: layouts don't surface scheduling.
const DEFAULT_STATUSES = 'publish,private,draft,pending';

export function buildPath( view ) {
	if ( ! view ) {
		return '';
	}
	const params = buildQueryParams( view, {
		defaultPerPage: 12,
		defaultStatuses: DEFAULT_STATUSES,
		// `offset` overrides `page` so page 1 can reserve slots for prebuilts.
		supportsOffset: true,
		// `content.raw` (not `.rendered`) — previews parse blocks client-side,
		// so skip the whole `the_content` chain. Term embeds aren't consumed.
		// `_links` stays in the list — `_embed` only expands links that
		// survive the `_fields` filter.
		extraParams: {
			_embed: 'author',
			_fields: 'id,status,title,date,modified,author,content.raw,meta,_links',
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
export default function useLayoutsData( view, mutationKey = 0 ) {
	return useCollectionData( {
		path: buildPath( view ),
		mutationKey,
		fetchAll: isFetchAllPerPage( view?.perPage ),
		errorMessage: __( 'Failed to load layouts. Please refresh the page.', 'newspack-newsletters' ),
		errorNoticeId: 'newspack-newsletters-layouts-list-fetch-error',
	} );
}
