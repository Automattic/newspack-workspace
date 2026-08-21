/**
 * Scope target picker — selects WHICH products or categories a rule targets, for
 * the scope types that require ids. Products come from the engine's own route
 * (/wc-dynamic-pricing/v1/products) because it serves parents AND variations —
 * labeled "Parent — attributes" and grouped under their parent — so a rule can
 * target an individual variation; core WP REST does not expose variations.
 * Categories read core WP REST (/wp/v2/product_cat). The rule still owns and
 * persists the resulting scope_ids. Scope types without targets (all_products /
 * all_subscriptions) render nothing.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import { AutocompleteTokenField } from '../../../../../packages/components/src';

// An item is an engine product ({ id, name }), a WP post (title.rendered), or a
// WP term (name) — accept all three shapes.
interface PickerEntity {
	id: number;
	name?: string;
	title?: { rendered?: string };
}

interface ScopeSource {
	label: string;
	placeholder: string;
	suggestionsPath: ( search: string ) => string;
	savedPath: ( ids: number[] ) => string;
}

const SOURCES: Record< string, ScopeSource > = {
	product_ids: {
		label: __( 'Products', 'newspack-plugin' ),
		placeholder: __( 'Search products and variations…', 'newspack-plugin' ),
		suggestionsPath: search => addQueryArgs( '/wc-dynamic-pricing/v1/products', { search, per_page: 20 } ),
		savedPath: ids => addQueryArgs( '/wc-dynamic-pricing/v1/products', { include: ids.join( ',' ) } ),
	},
	category: {
		label: __( 'Product categories', 'newspack-plugin' ),
		placeholder: __( 'Search categories…', 'newspack-plugin' ),
		suggestionsPath: search => addQueryArgs( '/wp/v2/product_cat', { search, per_page: 20, _fields: 'id,name' } ),
		savedPath: ids =>
			addQueryArgs( '/wp/v2/product_cat', {
				include: ids.join( ',' ),
				per_page: Math.min( ids.length, 100 ),
				_fields: 'id,name',
			} ),
	},
};

interface ScopeTargetsProps {
	scopeType: string;
	value: number[];
	onChange: ( ids: number[] ) => void;
}

export default function ScopeTargets( { scopeType, value, onChange }: ScopeTargetsProps ) {
	const source = SOURCES[ scopeType ];
	if ( ! source ) {
		return null;
	}

	const toOptions = ( items: PickerEntity[] ) =>
		items.map( item => ( {
			value: item.id,
			label: decodeEntities( item.name ?? item.title?.rendered ?? `#${ item.id }` ),
		} ) );

	const fetchSuggestions = ( search: string ) => apiFetch< PickerEntity[] >( { path: source.suggestionsPath( search ) } ).then( toOptions );

	const fetchSavedInfo = ( ids: number[] ) =>
		ids.length ? apiFetch< PickerEntity[] >( { path: source.savedPath( ids ) } ).then( toOptions ) : Promise.resolve( [] );

	return (
		<AutocompleteTokenField
			// Remount when the scope type changes so saved-info fetch re-runs for the new source.
			key={ scopeType }
			tokens={ value }
			onChange={ onChange }
			fetchSuggestions={ fetchSuggestions }
			fetchSavedInfo={ fetchSavedInfo }
			label={ source.label }
			placeholder={ source.placeholder }
			__next40pxDefaultSize
		/>
	);
}
