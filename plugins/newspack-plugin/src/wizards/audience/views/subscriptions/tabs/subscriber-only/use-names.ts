/**
 * Resolves product, product category and subscription IDs to names for the
 * restriction list, which stores only IDs.
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { WIZARD_ENDPOINT } from '../../constants';

type NameMap = Record< number, string >;

/**
 * Look up names for a set of IDs on one of the wizard's search endpoints.
 *
 * @param endpoint Endpoint name, e.g. 'products-search'.
 * @param ids      The IDs to resolve.
 */
export function useNames( endpoint: string, ids: number[] ): NameMap {
	const [ names, setNames ] = useState< NameMap >( {} );
	// Depend on the ID set's content rather than the array identity, which is
	// new on every render.
	const key = [ ...new Set( ids ) ].sort( ( a, b ) => a - b ).join( ',' );

	useEffect( () => {
		if ( ! key ) {
			setNames( {} );
			return;
		}
		let cancelled = false;
		apiFetch< { id: number; name: string }[] >( {
			path: addQueryArgs( `${ WIZARD_ENDPOINT }/${ endpoint }`, { include: key, per_page: 100 } ),
		} )
			.then( items => {
				if ( cancelled ) {
					return;
				}
				const map: NameMap = {};
				( items || [] ).forEach( item => {
					map[ item.id ] = decodeEntities( item.name );
				} );
				setNames( map );
			} )
			.catch( error => {
				console.warn( 'Error resolving names for ' + endpoint, error ); // eslint-disable-line no-console
			} );
		return () => {
			cancelled = true;
		};
	}, [ key, endpoint ] );

	return names;
}
