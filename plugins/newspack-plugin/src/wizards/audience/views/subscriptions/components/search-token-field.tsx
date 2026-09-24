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
import { useInstanceId } from '@wordpress/compose';
import { decodeEntities } from '@wordpress/html-entities';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { WIZARD_ENDPOINT } from '../constants';

import './search-token-field.scss';

type Item = { id: number; name: string };

interface SearchTokenFieldProps {
	/** Endpoint name under the wizard namespace, e.g. 'products-search'. */
	endpoint: string;
	label: string;
	help?: string;
	value: number[];
	onChange: ( ids: number[] ) => void;
	disabled?: boolean;
}

const debounce = < T extends ( ...args: never[] ) => void >( func: T, wait: number ) => {
	let timeout: ReturnType< typeof setTimeout >;
	return ( ...args: Parameters< T > ) => {
		clearTimeout( timeout );
		timeout = setTimeout( () => func( ...args ), wait );
	};
};

export default function SearchTokenField( { endpoint, label, help, value, onChange, disabled }: SearchTokenFieldProps ) {
	const instanceId = useInstanceId( SearchTokenField );
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
	// which would re-run it on every resolution. Assigned in an effect rather
	// than during render, which isn't safe under concurrent rendering.
	const resolvedRef = useRef( resolved );
	useEffect( () => {
		resolvedRef.current = resolved;
	}, [ resolved ] );

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
	//
	// Trimmed here, at the one place labels are made, so the rendered token and
	// the lookup key below can never disagree. FormTokenField trims only the
	// token it is adding: `addNewTokens` splices the trimmed newcomer into an
	// untouched copy of `value`, and `deleteToken` filters `value` directly, so
	// every other token comes back exactly as it was rendered. A product whose
	// name ends in a non-breaking space — what pasting out of a word processor
	// produces, and which survives the save that strips a plain trailing space —
	// would otherwise render untrimmed, miss a key trimmed by JS, and be dropped
	// from the rule on the next edit with nothing shown to the publisher.
	const toLabel = useCallback(
		( item: Item ) => decodeEntities( `${ item.id }: ${ item.name || __( '(no name)', 'newspack-plugin' ) }` ).trim(),
		[]
	);

	const suggestionLabels = useMemo( () => suggestions.map( toLabel ), [ suggestions, toLabel ] );

	const tokens = useMemo(
		() => value.map( id => ( known.has( id ) ? toLabel( known.get( id ) as Item ) : `${ id }` ) ),
		[ value, known, toLabel ]
	);

	// Labels resolve back to the item they came from. Free text is dropped rather
	// than parsed: "2024 Calendar" would otherwise become product ID 2024 and
	// silently point the rule at whatever that is.
	const labelToId = useMemo( () => {
		const byLabel = new Map< string, number >();
		known.forEach( item => byLabel.set( toLabel( item ), item.id ) );
		return byLabel;
	}, [ known, toLabel ] );

	const handleChange = ( nextTokens: ( string | { value: string } )[] ) => {
		const ids = nextTokens
			.map( token => {
				const tokenLabel = typeof token === 'string' ? token : token.value;
				if ( labelToId.has( tokenLabel ) ) {
					return labelToId.get( tokenLabel ) as number;
				}
				// A value still resolving to a name renders as its bare ID, so keep
				// those; anything else the reader typed is not a product.
				const id = Number( tokenLabel );
				return Number.isInteger( id ) && value.includes( id ) ? id : null;
			} )
			.filter( ( id ): id is number => null !== id );
		onChange( [ ...new Set( ids ) ] );
	};

	// The help text explains what the rule actually does, so it has to reach
	// screen readers rather than only sighted users. FormTokenField doesn't
	// forward arbitrary props to its input and sets its own `aria-describedby`
	// for the "how to" hint, so the id is appended to that attribute directly
	// rather than passed as a prop, which the component would silently drop.
	const helpId = `newspack-subscriptions-token-help-${ instanceId }`;
	const wrapperRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		const input = wrapperRef.current?.querySelector( 'input' );
		if ( ! input || ! help ) {
			return;
		}
		const describedBy = ( input.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( Boolean );
		if ( ! describedBy.includes( helpId ) ) {
			input.setAttribute( 'aria-describedby', [ ...describedBy, helpId ].join( ' ' ) );
		}
	}, [ help, helpId ] );

	return (
		<div className="newspack-subscriptions-search-token-field" ref={ wrapperRef }>
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
			{ help && (
				<p className="components-base-control__help" id={ helpId }>
					{ help }
				</p>
			) }
		</div>
	);
}
