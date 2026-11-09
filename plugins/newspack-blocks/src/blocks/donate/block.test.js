/**
 * WordPress dependencies
 */
import { getBlockType, parse, registerBlockType, unregisterBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from './block.json';

// Registered the way editor.js does it, with only what parsing needs.
const BLOCK_NAME = `newspack-blocks/${ metadata.name }`;

describe( 'Donate block minimum donation attribute', () => {
	beforeAll( () => {
		registerBlockType( BLOCK_NAME, {
			title: 'Donate',
			category: 'widgets',
			attributes: metadata.attributes,
			edit: () => null,
			save: () => null,
		} );
	} );

	afterAll( () => {
		if ( getBlockType( BLOCK_NAME ) ) {
			unregisterBlockType( BLOCK_NAME );
		}
	} );

	// Blocks saved before minimums were stored as numbers carry the value as a
	// string. The editor must still load it, or it falls back to the site-wide
	// minimum and the next save overwrites the block's own.
	it.each( [
		[ 'a number', '1200', 1200 ],
		[ 'a string', '"1200"', '1200' ],
	] )( 'keeps a minimum saved as %s', ( _label, serialized, expected ) => {
		const [ block ] = parse( `<!-- wp:${ BLOCK_NAME } {"minimumDonation":${ serialized }} /-->` );
		expect( block.attributes.minimumDonation ).toBe( expected );
	} );
} );
