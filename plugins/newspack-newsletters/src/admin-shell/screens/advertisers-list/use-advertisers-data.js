import { __ } from '@wordpress/i18n';

import useCollectionData from '../../hooks/use-collection-data';
import { buildQueryParams, toQueryString } from '../../utils/build-query';

const TAXONOMY_PATH = '/wp/v2/newspack_nl_advertiser';

/**
 * @param {Object} view          DataViews view state.
 * @param {number} [mutationKey] Bump from the parent (Modal save / Delete) to force a refetch.
 *                               Shared with `useAllAdvertisers` so both datasets refetch in lockstep.
 * @return {ReturnType<typeof useCollectionData>} Hook state.
 */
export default function useAdvertisersData( view, mutationKey = 0 ) {
	return useCollectionData( {
		path: `${ TAXONOMY_PATH }${ toQueryString( buildQueryParams( view ) ) }`,
		mutationKey,
		errorMessage: __( 'Failed to load advertisers. Please refresh the page.', 'newspack-newsletters' ),
		errorNoticeId: 'newspack-newsletters-advertisers-list-fetch-error',
	} );
}
