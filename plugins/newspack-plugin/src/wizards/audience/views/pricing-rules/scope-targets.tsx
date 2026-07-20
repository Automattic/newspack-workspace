/**
 * Scope target picker — selects WHICH products or categories a rule targets, for
 * the scope types that require ids. Reads generic lists from core WP REST
 * (/wp/v2/product, /wp/v2/product_cat); the rule still owns and persists the
 * resulting scope_ids. Scope types without targets (all_products /
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

// A WP REST item is either a post (products: title.rendered) or a term
// (categories: name) — accept both shapes.
interface WpEntity {
	id: number;
	name?: string;
	title?: { rendered?: string };
}

// Scope type → the core WP REST base whose entities it targets.
const REST_BASE: Record< string, string > = {
	product_ids: 'product',
	category: 'product_cat',
};

const FIELD_LABELS: Record< string, { label: string; placeholder: string } > = {
	product_ids: {
		label: __( 'Products', 'newspack-plugin' ),
		placeholder: __( 'Search products…', 'newspack-plugin' ),
	},
	category: {
		label: __( 'Product categories', 'newspack-plugin' ),
		placeholder: __( 'Search categories…', 'newspack-plugin' ),
	},
};

interface ScopeTargetsProps {
	scopeType: string;
	value: number[];
	onChange: ( ids: number[] ) => void;
}

export default function ScopeTargets( { scopeType, value, onChange }: ScopeTargetsProps ) {
	const base = REST_BASE[ scopeType ];
	if ( ! base ) {
		return null;
	}

	const toOptions = ( items: WpEntity[] ) =>
		items.map( item => ( {
			value: item.id,
			label: decodeEntities( item.name ?? item.title?.rendered ?? `#${ item.id }` ),
		} ) );

	// `_fields=id,name,title` is safe for both endpoints — each omits the field it
	// doesn't have, so the same request shape serves products and categories.
	const fetchSuggestions = ( search: string ) =>
		apiFetch< WpEntity[] >( {
			path: addQueryArgs( `/wp/v2/${ base }`, { search, per_page: 20, _fields: 'id,name,title' } ),
		} ).then( toOptions );

	const fetchSavedInfo = ( ids: number[] ) =>
		ids.length
			? apiFetch< WpEntity[] >( {
					path: addQueryArgs( `/wp/v2/${ base }`, {
						include: ids.join( ',' ),
						per_page: Math.min( ids.length, 100 ),
						_fields: 'id,name,title',
					} ),
			  } ).then( toOptions )
			: Promise.resolve( [] );

	const { label, placeholder } = FIELD_LABELS[ scopeType ];

	return (
		<AutocompleteTokenField
			// Remount when the scope type changes so saved-info fetch re-runs for the new base.
			key={ scopeType }
			tokens={ value }
			onChange={ onChange }
			fetchSuggestions={ fetchSuggestions }
			fetchSavedInfo={ fetchSavedInfo }
			label={ label }
			placeholder={ placeholder }
			__next40pxDefaultSize
		/>
	);
}
