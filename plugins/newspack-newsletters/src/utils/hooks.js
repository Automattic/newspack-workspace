/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { LAYOUT_CPT_SLUG } from './consts';

/**
 * A React hook that provides tha layouts list,
 * both default and user-defined.
 *
 * @return {Array} Array of layouts
 */
export const useLayoutsState = () => {
	const [ isFetching, setIsFetching ] = useState( true );
	const [ layouts, setLayouts ] = useState( [] );

	useEffect( () => {
		apiFetch( {
			path: `/newspack-newsletters/v1/layouts`,
		} ).then( response => {
			setLayouts( response );
			setIsFetching( false );
		} );
	}, [] );

	const deleteLayoutPost = id => {
		// Optimistic update
		setLayouts( layouts.filter( ( { ID } ) => ID !== id ) );
		apiFetch( {
			path: `/wp/v2/${ LAYOUT_CPT_SLUG }/${ id }`,
			method: 'DELETE',
		} );
	};

	return { layouts, isFetchingLayouts: isFetching, deleteLayoutPost };
};
