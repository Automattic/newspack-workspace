/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import AutocompleteTokenField from '../../../../../packages/components/src/autocomplete-tokenfield';

/**
 * Token field for picking items from a REST list.
 *
 * @param {Object}   props
 * @param {string}   props.label            Field label.
 * @param {string}   props.help             Help text.
 * @param {string}   props.placeholder      Input placeholder.
 * @param {Array}    props.value            Saved item IDs.
 * @param {Function} props.onChange         Called with the new item IDs.
 * @param {string}   props.path             REST path listing the selectable items.
 * @param {string}   props.deletedItemLabel Label for a saved ID the lookup can't find.
 * @param {Function} props.savedInfoPath    Optional. Given the saved IDs, returns a REST
 *                                          path that looks them up. Use when saved items
 *                                          can fall out of the selectable list but still
 *                                          need their names.
 */
export default function ListsControl( { label, help, placeholder, value, onChange, path, deletedItemLabel, savedInfoPath } ) {
	const getSuggestions = item => ( {
		value: /^\d+$/.test( item.id.toString() ) ? parseInt( item.id ) : item.id.toString(),
		label: item.title || item.name || deletedItemLabel,
	} );

	return (
		<AutocompleteTokenField
			label={ label }
			help={ help }
			placeholder={ placeholder }
			tokens={ value || [] }
			fetchSuggestions={ async () => {
				const lists = await apiFetch( {
					path,
				} );
				const values = Array.isArray( lists ) ? lists : Object.values( lists );
				return values.map( getSuggestions );
			} }
			fetchSavedInfo={ async ids => {
				const lists = await apiFetch( {
					path: savedInfoPath ? savedInfoPath( ids ) : path,
				} );
				const values = Array.isArray( lists ) ? lists : Object.values( lists );
				return ids
					.map( id => {
						const item = values.find( it => {
							const itId = /^\d+$/.test( it.id.toString() ) ? parseInt( it.id ) : it.id.toString();
							return itId === id;
						} );
						if ( item ) {
							return getSuggestions( item );
						}
						return deletedItemLabel && id ? getSuggestions( { id } ) : false;
					} )
					.filter( Boolean );
			} }
			onChange={ onChange }
		/>
	);
}
