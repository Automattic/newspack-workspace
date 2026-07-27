/**
 * Search-as-you-type token field over one of the Subscriptions wizard's search
 * endpoints (products, product categories, subscriptions). Values are IDs; the
 * field resolves them to names on its own, so a caller only ever handles IDs.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { FormTokenField } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { WIZARD_ENDPOINT } from '../constants';

type Item = { id: number; name: string };

interface SearchTokenFieldProps {
	/** Endpoint name under the wizard namespace, e.g. 'products-search'. */
	endpoint: string;
	label: string;
	help?: string;
	value: number[];
	onChange: ( ids: number[] ) => void;
	/** Restrict suggestions to these IDs. Used by the exclusion field. */
	limitTo?: number[];
	disabled?: boolean;
}

const debounce = < T extends ( ...args: never[] ) => void >( func: T, wait: number ) => {
	let timeout: ReturnType< typeof setTimeout >;
	return ( ...args: Parameters< T > ) => {
		clearTimeout( timeout );
		timeout = setTimeout( () => func( ...args ), wait );
	};
};

export default function SearchTokenField( { endpoint, label, help, value, onChange, limitTo, disabled }: SearchTokenFieldProps ) {
	const [ suggestions, setSuggestions ] = useState< Item[] >( [] );
	// Names for the current value, so saved IDs render as tokens before (and
	// regardless of) any search.
	const [ resolved, setResolved ] = useState< Item[] >( [] );
	const path = `${ WIZARD_ENDPOINT }/${ endpoint }`;

	const fetchSuggestions = useCallback(
		( search = '' ) => {
			apiFetch< Item[] >( { path: addQueryArgs( path, { search, per_page: 100 } ) } )
				.then( items => setSuggestions( items || [] ) )
				.catch( error => {
					console.warn( 'Error fetching suggestions for ' + path, error ); // eslint-disable-line no-console
				} );
		},
		[ path ]
	);

	// Resolve the current IDs to names. Runs when IDs appear that no fetch has
	// named yet — a saved rule opened in the editor, most of the time.
	const resolvedIds = useMemo( () => resolved.map( item => item.id ), [ resolved ] );
	const unresolved = useMemo( () => value.filter( id => ! resolvedIds.includes( id ) ), [ value, resolvedIds ] );
	// Read the latest resolved list without making the effect depend on it,
	// which would re-run it on every resolution.
	const resolvedRef = useRef( resolved );
	resolvedRef.current = resolved;

	useEffect( () => {
		if ( ! unresolved.length ) {
			return;
		}
		apiFetch< Item[] >( { path: addQueryArgs( path, { include: unresolved.join( ',' ), per_page: 100 } ) } )
			.then( items => {
				if ( items?.length ) {
					setResolved( [ ...resolvedRef.current, ...items ] );
				}
			} )
			.catch( error => {
				console.warn( 'Error resolving saved items for ' + path, error ); // eslint-disable-line no-console
			} );
	}, [ unresolved, path ] );

	useEffect( () => {
		fetchSuggestions();
	}, [ fetchSuggestions ] );

	const debouncedFetch = useMemo( () => debounce( fetchSuggestions, 200 ), [ fetchSuggestions ] );

	// Everything the field knows a name for, deduped by ID.
	const known = useMemo( () => {
		const byId = new Map< number, Item >();
		[ ...resolved, ...suggestions ].forEach( item => byId.set( item.id, item ) );
		return byId;
	}, [ resolved, suggestions ] );

	// Labels carry the ID so two products sharing a name stay distinguishable —
	// FormTokenField matches on the label string.
	const toLabel = useCallback( ( item: Item ) => decodeEntities( `${ item.id }: ${ item.name || __( '(no name)', 'newspack-plugin' ) }` ), [] );

	const suggestionLabels = useMemo( () => {
		const allowed = limitTo ? new Set( limitTo ) : null;
		return suggestions.filter( item => ! allowed || allowed.has( item.id ) ).map( toLabel );
	}, [ suggestions, limitTo, toLabel ] );

	const tokens = useMemo(
		() => value.map( id => ( known.has( id ) ? toLabel( known.get( id ) as Item ) : `${ id }` ) ),
		[ value, known, toLabel ]
	);

	const handleChange = ( nextTokens: ( string | { value: string } )[] ) => {
		const ids = nextTokens
			.map( token => {
				const tokenLabel = typeof token === 'string' ? token : token.value;
				// Labels are "{id}: {name}"; a token typed by hand may be a bare ID.
				const id = parseInt( tokenLabel, 10 );
				return Number.isNaN( id ) ? null : id;
			} )
			.filter( ( id ): id is number => id !== null );
		onChange( [ ...new Set( ids ) ] );
	};

	return (
		<div className="newspack-subscriptions-search-token-field">
			<FormTokenField
				label={ label }
				value={ tokens }
				suggestions={ suggestionLabels }
				onChange={ handleChange }
				onInputChange={ debouncedFetch }
				disabled={ disabled }
				__experimentalExpandOnFocus
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			{ help && <p className="components-base-control__help">{ help }</p> }
		</div>
	);
}
