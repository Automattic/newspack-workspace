import { __ } from '@wordpress/i18n';

import useCollectionData from '../../hooks/use-collection-data';
import { buildQueryParams, toQueryString } from '../../utils/build-query';

import type { CollectionData } from '../../hooks/use-collection-data';
import type { QueryView, Term } from '../../types';

const TAXONOMY_PATH = '/wp/v2/newspack_nl_advertiser';

/**
 * @param view        DataViews view state.
 * @param mutationKey Bump from the parent (Modal save / Delete) to force a refetch.
 *                    Shared with `useAllAdvertisers` so both datasets refetch in lockstep.
 * @return Hook state.
 */
export default function useAdvertisersData( view: QueryView, mutationKey = 0 ): CollectionData< Term > {
	return useCollectionData< Term >( {
		path: `${ TAXONOMY_PATH }${ toQueryString( buildQueryParams( view ) ) }`,
		mutationKey,
		errorMessage: __( 'Failed to load advertisers. Please refresh the page.', 'newspack-newsletters' ),
		errorNoticeId: 'newspack-newsletters-advertisers-list-fetch-error',
	} );
}
