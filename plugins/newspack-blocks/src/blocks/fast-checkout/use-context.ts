/**
 * Extend core/heading, core/image, and core/paragraph to consume the
 * Fast Checkout product context in the editor.
 */

import { addFilter } from '@wordpress/hooks';

const PRODUCT_CONTEXT_KEY = 'newspack-blocks/fastCheckoutProductId';
const VARIATION_CONTEXT_KEY = 'newspack-blocks/fastCheckoutVariationId';
const TARGETS = [ 'core/heading', 'core/image', 'core/paragraph' ];

interface BlockSettings {
	usesContext?: string[];
	[ key: string ]: unknown;
}

addFilter( 'blocks.registerBlockType', 'newspack-blocks/fast-checkout/use-context', ( settings: BlockSettings, name: string ) => {
	if ( ! TARGETS.includes( name ) ) {
		return settings;
	}
	const existing = Array.isArray( settings.usesContext ) ? settings.usesContext : [];
	if ( existing.includes( PRODUCT_CONTEXT_KEY ) ) {
		return settings;
	}
	return {
		...settings,
		usesContext: [ ...existing, PRODUCT_CONTEXT_KEY, VARIATION_CONTEXT_KEY ],
	};
} );
