/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import * as subscribe from './subscribe';

export const blocks = [ subscribe ];

/**
 * Function to register an individual block.
 *
 * @param block The block to be registered.
 */
const registerBlock = ( block: ( typeof blocks )[ number ] ) => {
	if ( ! block ) {
		return;
	}

	const { metadata, settings, name } = block;

	// `registerBlockType` is generic over a single block's attribute types, but this
	// registrar is generic over every block, so the attribute type collapses to
	// `Record< string, unknown >` and rejects each block's precisely-typed `edit`
	// component. Cross the WP API boundary through `unknown`.
	const registerSettings: unknown = settings;
	registerBlockType( { ...metadata, name }, registerSettings as Parameters< typeof registerBlockType >[ 1 ] );
};

for ( const block of blocks ) {
	registerBlock( block );
}
