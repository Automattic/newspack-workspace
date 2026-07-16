/**
 * Internal dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';
import { name, settings } from '.';

// Widen to the generic module shape before asserting the WP types, mirroring
// newspack-plugin's block registration (src/blocks/contribution-meter/index.ts).
// The (string, settings) overload of registerBlockType requires the full (not
// Partial<...>) BlockConfiguration shape for its second argument.
const blockSettings: Record< string, unknown > = settings;
registerBlockType( `newspack-blocks/${ name }`, blockSettings as BlockConfiguration );
