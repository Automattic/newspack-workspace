/**
 * Fetch the option lists for the Newsletters list filter dropdowns.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

import type { FilterOption } from '../../types';

const PATH = '/newspack-newsletters/v1/newsletters-list/filter-options';

export interface FilterElements {
	authors: FilterOption[];
	categories: FilterOption[];
	tags: FilterOption[];
	sendLists: FilterOption[];
}

interface FilterOptionsResponse {
	authors?: FilterOption[];
	categories?: FilterOption[];
	tags?: FilterOption[];
	send_lists?: FilterOption[];
}

const EMPTY: FilterElements = { authors: [], categories: [], tags: [], sendLists: [] };

export default function useFilterElements(): FilterElements {
	const [ state, setState ] = useState< FilterElements >( EMPTY );

	useEffect( () => {
		let cancelled = false;
		apiFetch< FilterOptionsResponse >( { path: PATH } )
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
