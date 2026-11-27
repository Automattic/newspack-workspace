/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import AutocompleteTokenField from '../../../../../packages/components/src/autocomplete-tokenfield';
import type { Suggestion, TokenValue } from '../../../../../packages/components/src/autocomplete-tokenfield';

/**
 * An item (subscription list, product, membership plan, …) as returned by the
 * REST endpoint at `path`.
 */
type ListItem = {
	id: string | number | Suggestion;
	title?: string;
	name?: string;
};

type ListsControlProps = {
	label?: string;
	hideLabelFromVision?: boolean;
	help?: ReactNode;
	placeholder?: string;
	/** The selected item ids. */
	value?: TokenValue[];
	onChange: ( values: TokenValue[] ) => void;
	/** REST path listing the selectable items. */
	path: string;
	/** Label used for a saved ID the lookup can't find. */
	deletedItemLabel: string;
	/** Given the saved IDs, returns a REST path that looks them up. */
	savedInfoPath?: ( ids: TokenValue[] ) => string;
	/** Append " (#<id>)" to every label. */
	labelWithId?: boolean;
};

/**
 * Token field for picking items from a REST list.
 *
 * @param {Object}   props
 * @param {string}   props.label               Field label.
 * @param {boolean}  props.hideLabelFromVision Optional. Keep the label for assistive tech only.
 * @param {string}   props.help                Help text.
 * @param {string}   props.placeholder         Input placeholder.
 * @param {Array}    props.value               Saved item IDs.
 * @param {Function} props.onChange            Called with the new item IDs.
 * @param {string}   props.path                REST path listing the selectable items.
 * @param {string}   props.deletedItemLabel    Label for a saved ID the lookup can't find.
 * @param {Function} props.savedInfoPath       Optional. Given the saved IDs, returns a REST
 *                                             path that looks them up. Use when saved items
 *                                             can fall out of the selectable list but still
 *                                             need their names.
 * @param {boolean}  props.labelWithId         Optional. Append " (#<id>)" to every label. The
 *                                             token field maps a chosen label back to an ID by
 *                                             text, so items sharing a name (a legacy and a
 *                                             current "Annual", say) collapse into one ID
 *                                             unless their labels differ.
 */
export default function ListsControl( {
	label,
	hideLabelFromVision,
	help,
	placeholder,
	value,
	onChange,
	path,
	deletedItemLabel,
	savedInfoPath,
	labelWithId,
}: ListsControlProps ) {
	const getSuggestions = ( item: ListItem ): Suggestion => {
		const itemValue = /^\d+$/.test( item.id.toString() ) ? parseInt( item.id.toString() ) : item.id.toString();
		const itemLabel = item.title || item.name || deletedItemLabel;
		return {
			value: itemValue,
			label: labelWithId ? `${ itemLabel } (#${ itemValue })` : itemLabel,
		};
	};

	return (
		<AutocompleteTokenField
			label={ label }
			hideLabelFromVision={ hideLabelFromVision }
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
					path: savedInfoPath ? savedInfoPath( ids ) : path,
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
			// A typed token that matches no item resolves to `undefined`. Saved, it would
			// become a `null` in the segment, so drop it here.
			onChange={ values => onChange( values.filter( itemValue => itemValue !== undefined && itemValue !== null ) ) }
		/>
	);
}
