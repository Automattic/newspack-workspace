/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import AutocompleteTokenField from '../../../../../packages/components/src/autocomplete-tokenfield';
import type { Suggestion, TokenValue } from '../../../../../packages/components/src/autocomplete-tokenfield';

/**
 * External dependencies
 */
import type { ComponentProps, ReactNode } from 'react';

/**
 * An item (subscription list, product, membership plan, …) as returned by the
 * REST endpoint at `path`.
 */
type ListItem = {
	id: string | number;
	title?: string;
	name?: string;
};

type ListsControlProps = {
	label?: string;
	help?: ReactNode;
	placeholder?: string;
	/** The selected item ids. */
	value?: TokenValue[];
	onChange: ComponentProps< typeof AutocompleteTokenField >[ 'onChange' ];
	/** REST path returning the available items. */
	path: string;
	/** Label used for saved items no longer present in the endpoint's response. */
	deletedItemLabel: string;
};

export default function ListsControl( { label, help, placeholder, value, onChange, path, deletedItemLabel }: ListsControlProps ) {
	const getSuggestions = ( item: { id: string | number | Suggestion; title?: string; name?: string } ): Suggestion => {
		const idString = item.id.toString();
		return {
			value: /^\d+$/.test( idString ) ? parseInt( idString ) : idString,
			label: item.title || item.name || deletedItemLabel,
		};
	};

	return (
		<AutocompleteTokenField
			label={ label }
			help={ help }
			placeholder={ placeholder }
			tokens={ value || [] }
			fetchSuggestions={ async () => {
				const lists = await apiFetch< ListItem[] | Record< string, ListItem > >( {
					path,
				} );
				const values = Array.isArray( lists ) ? lists : Object.values( lists );
				return values.map( getSuggestions );
			} }
			fetchSavedInfo={ async ( ids: TokenValue[] ) => {
				const lists = await apiFetch< ListItem[] | Record< string, ListItem > >( {
					path,
				} );
				const values = Array.isArray( lists ) ? lists : Object.values( lists );
				return ids
					.map( id => {
						const item = values.find( it => {
							const itId = /^\d+$/.test( it.id.toString() ) ? parseInt( it.id.toString() ) : it.id.toString();
							return itId === id;
						} );
						if ( item ) {
							return getSuggestions( item );
						}
						return deletedItemLabel && id ? getSuggestions( { id } ) : false;
					} )
					.filter( ( suggestion ): suggestion is Suggestion => Boolean( suggestion ) );
			} }
			onChange={ onChange }
		/>
	);
}
