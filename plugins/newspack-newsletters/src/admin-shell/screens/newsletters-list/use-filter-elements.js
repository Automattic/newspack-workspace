/**
 * Fetch the option lists for the Newsletters list filter dropdowns.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

const PATH = '/newspack-newsletters/v1/newsletters-list/filter-options';

const EMPTY = { authors: [], categories: [], tags: [], sendLists: [] };

export default function useFilterElements() {
	const [ state, setState ] = useState( EMPTY );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: PATH } )
			.then( payload => {
				if ( cancelled || ! payload ) {
					return;
				}
				setState( {
					authors: Array.isArray( payload.authors ) ? payload.authors : [],
					categories: Array.isArray( payload.categories ) ? payload.categories : [],
					tags: Array.isArray( payload.tags ) ? payload.tags : [],
					sendLists: Array.isArray( payload.send_lists ) ? payload.send_lists : [],
				} );
			} )
			.catch( () => {} );

		return () => {
			cancelled = true;
		};
	}, [] );

	return state;
}
