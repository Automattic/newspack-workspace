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

/** The search endpoints cap `per_page` at 100, so larger ID sets go in batches. */
const BATCH_SIZE = 100;

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
		// One request per batch rather than one truncated request: an ID the
		// endpoint never returns renders as a blank name, so silently dropping the
		// overflow would leave the list lying about what a rule covers.
		const batches: string[][] = [];
		const uniqueIds = key.split( ',' );
		for ( let index = 0; index < uniqueIds.length; index += BATCH_SIZE ) {
			batches.push( uniqueIds.slice( index, index + BATCH_SIZE ) );
		}
		Promise.all(
			batches.map( batch =>
				apiFetch< { id: number; name: string }[] >( {
					path: addQueryArgs( `${ WIZARD_ENDPOINT }/${ endpoint }`, { include: batch.join( ',' ), per_page: BATCH_SIZE } ),
				} )
			)
		)
			.then( responses => {
				if ( cancelled ) {
					return;
				}
				const map: NameMap = {};
				responses.forEach( items => {
					( items || [] ).forEach( item => {
						map[ item.id ] = decodeEntities( item.name );
					} );
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
