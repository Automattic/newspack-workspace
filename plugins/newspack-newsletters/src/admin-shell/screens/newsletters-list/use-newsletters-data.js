import { __ } from '@wordpress/i18n';

import useCollectionData from '../../hooks/use-collection-data';
import { buildQueryParams, toQueryString } from './build-query';

const POSTS_PATH = '/wp/v2/newspack_nl_cpt';
const TRASH_COUNT_PATH = `${ POSTS_PATH }?status=trash&per_page=1&context=edit`;

export default function useNewslettersData( view ) {
	return useCollectionData( {
		path: `${ POSTS_PATH }${ toQueryString( buildQueryParams( view ) ) }`,
		trashCountPath: TRASH_COUNT_PATH,
		errorMessage: __( 'Failed to load newsletters. Please refresh the page.', 'newspack-newsletters' ),
		errorNoticeId: 'newspack-newsletters-list-fetch-error',
	} );
}
