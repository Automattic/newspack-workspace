/**
 * Scope target picker — selects WHICH products or categories a rule targets, for
 * the scope types that require ids. Products come from the engine's own route
 * (/wc-dynamic-pricing/v1/products) because it serves parents AND variations —
 * labeled "Parent — attributes" and grouped under their parent — so a rule can
 * target an individual variation; core WP REST does not expose variations. On an
 * engine build without that route, product fetches fall back to core
 * /wp/v2/product: parents only, the pre-engine capability. The engine route
 * requires edit_products (like /rules and /impact-preview; WooCommerce grants it
 * to administrators), and a 403 degrades the same way as a missing route.
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
	// Core WP REST equivalents, used when the primary route rejects.
	fallbackSuggestionsPath?: ( search: string ) => string;
	fallbackSavedPath?: ( ids: number[] ) => string;
}

// Exported for tests — the path builders are the behavior worth locking.
export const SOURCES: Record< string, ScopeSource > = {
	product_ids: {
		label: __( 'Products', 'newspack-plugin' ),
		placeholder: __( 'Search products and variations…', 'newspack-plugin' ),
		// per_page 50 is the engine route's cap — with variations included in the
		// results, one variable product can fill a smaller page and hide every
		// other match.
		suggestionsPath: search => addQueryArgs( '/wc-dynamic-pricing/v1/products', { search, per_page: 50 } ),
		savedPath: ids => addQueryArgs( '/wc-dynamic-pricing/v1/products', { include: ids.join( ',' ) } ),
		fallbackSuggestionsPath: search => addQueryArgs( '/wp/v2/product', { search, per_page: 20, _fields: 'id,title' } ),
		fallbackSavedPath: ids =>
			addQueryArgs( '/wp/v2/product', {
				include: ids.join( ',' ),
				per_page: Math.min( ids.length, 100 ),
				_fields: 'id,title',
			} ),
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

	// A rejecting primary route falls back to the source's core WP REST path, if
	// any, and the result always resolves: AutocompleteTokenField does not catch
	// rejections, so a rejecting fetch would strand the field in its loading
	// state.
	const fetchWithFallback = ( path: string, fallbackPath?: string ) =>
		apiFetch< PickerEntity[] >( { path } )
			.catch( () => ( fallbackPath ? apiFetch< PickerEntity[] >( { path: fallbackPath } ) : [] ) )
			.catch( () => [] as PickerEntity[] );

	const fetchSuggestions = ( search: string ) =>
		fetchWithFallback( source.suggestionsPath( search ), source.fallbackSuggestionsPath?.( search ) ).then( toOptions );

	// Ids no route resolves keep a placeholder "#id" token. The placeholder is
	// what keeps them selected: the token field renders only ids it can label
	// and re-derives the value from the rendered tokens on change, so an id
	// dropped here would be dropped from the rule's scope_ids by the next
	// edit-and-save.
	const fetchSavedInfo = ( ids: number[] ) =>
		ids.length
			? fetchWithFallback( source.savedPath( ids ), source.fallbackSavedPath?.( ids ) )
					.then( toOptions )
					.then( options => {
						const resolved = new Set( options.map( option => option.value ) );
						return [ ...options, ...ids.filter( id => ! resolved.has( id ) ).map( id => ( { value: id, label: `#${ id }` } ) ) ];
					} )
			: Promise.resolve( [] );

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
