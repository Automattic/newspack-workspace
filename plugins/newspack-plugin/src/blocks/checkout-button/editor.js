/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { name, settings } from '.';

registerBlockType( name, settings );

addFilter( 'blockEditor.useSetting.before', 'newspack-blocks/add-border-radius-support', ( value, path, clientId, blockName ) => {
	if ( path === 'border.radius' && blockName === 'newspack-blocks/checkout-button' ) {
		return true;
	}
	return value;
} );
