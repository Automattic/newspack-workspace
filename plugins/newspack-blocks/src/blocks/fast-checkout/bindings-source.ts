/**
 * Fast Checkout block bindings source — editor side.
 *
 * Registers a bindings source that resolves fields (title, image_url, etc.)
 * from the product whose ID is provided by Fast Checkout block context.
 */

import { registerBlockBindingsSource } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import type { StoreApiProduct, ProductField, Binding, BindingsContext } from './types';

const SOURCE_NAME = 'newspack-blocks/fast-checkout-product';
const PRODUCT_CONTEXT_KEY = 'newspack-blocks/fastCheckoutProductId';
const VARIATION_CONTEXT_KEY = 'newspack-blocks/fastCheckoutVariationId';

const productCache = new Map< number, StoreApiProduct | null >();
const pendingFetches = new Map< number, Promise< StoreApiProduct | null > >();

/**
 * Fetch a product from the WooCommerce Store API and cache it.
 *
 * @param productId Product or variation ID.
 * @return Cached or fetched product record.
 */
export function fetchProduct( productId: number | string ): Promise< StoreApiProduct | null > {
	const id = parseInt( String( productId ), 10 );
	if ( ! id ) {
		return Promise.resolve( null );
	}
	if ( productCache.has( id ) ) {
		return Promise.resolve( productCache.get( id ) ?? null );
	}
	if ( pendingFetches.has( id ) ) {
		return pendingFetches.get( id )!;
	}
	const promise = apiFetch< StoreApiProduct >( { path: `/wc/store/v1/products/${ id }` } )
		.then( product => {
			productCache.set( id, product );
			pendingFetches.delete( id );
			return product;
		} )
		.catch( () => {
			productCache.set( id, null );
			pendingFetches.delete( id );
			return null;
		} );
	pendingFetches.set( id, promise );
	return promise;
}

/**
 * Pull a single field from a cached Store API product record.
 *
 * @param product Store API product record.
 * @param field   Field name.
 * @return Resolved field value.
 */
function readField( product: StoreApiProduct | null | undefined, field: ProductField ): string {
	if ( ! product ) {
		return '';
	}
	switch ( field ) {
		case 'title':
			return product.name || '';
		case 'short_description':
			return product.short_description || '';
		case 'price':
			return product.price_html || '';
		case 'price_raw':
			return product.prices?.price || '';
		case 'image_url':
			return product.images?.[ 0 ]?.src || '';
		case 'url':
			return product.permalink || '';
		default:
			return '';
	}
}

registerBlockBindingsSource( {
	name: SOURCE_NAME,
	label: __( 'Fast Checkout Product', 'newspack-blocks' ),
	usesContext: [ PRODUCT_CONTEXT_KEY, VARIATION_CONTEXT_KEY ],
	getValues( { bindings, context }: { bindings: Record< string, Binding >; context: BindingsContext } ) {
		const variationId = parseInt( String( context?.[ VARIATION_CONTEXT_KEY ] ?? 0 ), 10 ) || 0;
		const productId = parseInt( String( context?.[ PRODUCT_CONTEXT_KEY ] ?? 0 ), 10 ) || 0;
		const resolvedId = variationId || productId;
		const product = resolvedId ? productCache.get( resolvedId ) : null;

		// Trigger a fetch if we have an ID but no cache entry yet.
		// The next render cycle picks up the cached value once resolved.
		if ( resolvedId && ! productCache.has( resolvedId ) ) {
			fetchProduct( resolvedId );
		}

		const result: Record< string, string > = {};
		for ( const [ attr, binding ] of Object.entries( bindings ) ) {
			const field = binding?.args?.field;
			result[ attr ] = field ? readField( product, field ) : '';
		}
		return result;
	},
} );
