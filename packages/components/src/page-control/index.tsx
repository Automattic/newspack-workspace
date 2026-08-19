/**
 * Page Control.
 *
 * A single-page selector backed by an autocomplete search, for settings that point at a
 * page without enumerating every page the site has.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { AutocompleteWithSuggestions } from '../';

/**
 * The saved page, as `{ label, value }`.
 */
export interface PageControlItem {
	label: string;
	value: string | number;
}

interface PageControlProps {
	/** Field label. */
	label?: string;
	/** Help text. */
	help?: string;
	/** Whether the field is disabled (e.g. while saving). */
	disabled?: boolean;
	/** The saved page as `{ label, value }`, if any. */
	selected?: PageControlItem | null;
	/** Called with the selected page ID, or '' when cleared. */
	onChange: ( value: string ) => void;
}

/**
 * Render a single-page selector.
 */
const PageControl = ( { label, help, disabled, selected, onChange }: PageControlProps ) => {
	// Titles arrive HTML-encoded from the server, while the autocomplete decodes the
	// suggestions it fetches. Decode here so the saved page and the search results below
	// it render the same way.
	const normalize = ( item?: PageControlItem | null ): PageControlItem | null => ( item ? { ...item, label: decodeEntities( item.label ) } : null );
	const [ selectedItem, setSelectedItem ] = useState< PageControlItem | null >( normalize( selected ) );
	// Settings are fetched after this control first renders, so adopt the saved page
	// whenever it arrives or changes rather than only on mount.
	useEffect( () => {
		setSelectedItem( normalize( selected ) );
	}, [ selected?.value, selected?.label ] );
	return (
		<fieldset disabled={ disabled } className="newspack-page-control">
			<AutocompleteWithSuggestions
				label={ label }
				help={ help }
				postTypes={ [ { slug: 'page', label: __( 'Page', 'newspack-plugin' ) } ] }
				postTypeLabel={ __( 'page', 'newspack-plugin' ) }
				postTypeLabelPlural={ __( 'pages', 'newspack-plugin' ) }
				selectedItems={ selectedItem ? [ selectedItem ] : [] }
				onChange={ ( items: PageControlItem[] ) => {
					if ( disabled ) {
						return;
					}
					const item = items && items.length ? items[ items.length - 1 ] : null;
					setSelectedItem( normalize( item ) );
					onChange( item ? String( item.value ) : '' );
				} }
			/>
		</fieldset>
	);
};

export default PageControl;
