/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { LAYOUT_CPT_SLUG } from './consts';
import type { Layout } from './index';

/**
 * A React hook that provides the layouts list, both default and user-defined.
 */
export const useLayoutsState = () => {
	const [ isFetching, setIsFetching ] = useState( true );
	const [ layouts, setLayouts ] = useState< Layout[] >( [] );

	useEffect( () => {
		apiFetch< Layout[] >( {
			path: `/newspack-newsletters/v1/layouts`,
		} ).then( response => {
			setLayouts( response );
			setIsFetching( false );
		} );
	}, [] );

	const deleteLayoutPost = ( id: number ) => {
		// Optimistic update
		setLayouts( layouts.filter( ( { ID } ) => ID !== id ) );
		apiFetch( {
			path: `/wp/v2/${ LAYOUT_CPT_SLUG }/${ id }`,
			method: 'DELETE',
		} );
	};

	return { layouts, isFetchingLayouts: isFetching, deleteLayoutPost };
};
