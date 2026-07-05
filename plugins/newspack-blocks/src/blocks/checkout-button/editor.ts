/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { name, settings } from '.';

// Widen to the generic module shape before asserting the WP types, mirroring
// newspack-plugin's block registration (src/blocks/contribution-meter/index.ts).
// The (string, settings) overload of registerBlockType requires the full (not
// Partial<...>) BlockConfiguration shape for its second argument.
const blockSettings: Record< string, unknown > = settings;
registerBlockType( name, blockSettings as BlockConfiguration );

addFilter(
	'blockEditor.useSetting.before',
	'newspack-blocks/add-border-radius-support',
	( value: unknown, path: string, clientId: string, blockName: string ) => {
		if ( path === 'border.radius' && blockName === 'newspack-blocks/checkout-button' ) {
			return true;
		}
		return value;
	}
);
