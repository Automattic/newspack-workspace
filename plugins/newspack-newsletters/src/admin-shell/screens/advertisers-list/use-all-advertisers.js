/**
 * Lightweight fetch of every advertiser term for the Modal's parent
 * picker — the DataView's paginated fetch would silently truncate the
 * picker on sites with more than one page of advertisers.
 */

import { useEffect, useState } from '@wordpress/element';

import { fetchAllTerms } from '../../utils/terms';

const TAXONOMY_PATH = '/wp/v2/newspack_nl_advertiser';

/**
 * @param {number} refreshKey Bump to refetch — wire to the screen's save trigger.
 * @return {Array} Flat term list `[{ id, name, parent }, …]`.
 */
export default function useAllAdvertisers( refreshKey = 0 ) {
	const [ advertisers, setAdvertisers ] = useState( [] );

	useEffect( () => {
		let cancelled = false;
		fetchAllTerms( TAXONOMY_PATH, { fields: [ 'id', 'name', 'parent' ] } ).then( list => {
			if ( ! cancelled ) {
				setAdvertisers( list );
			}
		} );
		return () => {
			cancelled = true;
		};
	}, [ refreshKey ] );

	return advertisers;
}
