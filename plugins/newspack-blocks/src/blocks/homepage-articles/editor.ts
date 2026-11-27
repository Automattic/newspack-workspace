/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { settings, name } from '.';
import { name as carouselBlockName } from '../carousel';
import { registerQueryStore } from './store';

const BLOCK_NAME = `newspack-blocks/${ name }`;

// Widen to the generic module shape before asserting the WP types, mirroring
// newspack-plugin's block registration (src/blocks/contribution-meter/index.ts).
// The (string, settings) overload of registerBlockType requires the full (not
// Partial<...>) BlockConfiguration shape for its second argument.
const blockSettings: Record< string, unknown > = settings;
registerBlockType( BLOCK_NAME, blockSettings as BlockConfiguration );
registerQueryStore( [ BLOCK_NAME, `newspack-blocks/${ carouselBlockName }` ] );
