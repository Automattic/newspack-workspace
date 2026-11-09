import { check, search } from '@wordpress/icons';

import { statusGlyph } from 'newspack-components/src/status-indicator';

import { getStatusIndicatorProps, getWpStatusIndicatorProps } from './statuses';

const glyphOf = icon => {
	const props = getStatusIndicatorProps( icon );
	return props.icon || statusGlyph( props.status );
};

describe( 'getStatusIndicatorProps', () => {
	it( 'keeps the glyph each default status has always had', () => {
		expect( getStatusIndicatorProps( 'draft' ) ).toEqual( { status: 'draft' } );
		expect( getStatusIndicatorProps( 'pending' ) ).toEqual( { status: 'pending' } );
		expect( getStatusIndicatorProps( 'search' ) ).toEqual( { icon: search } );
		expect( getStatusIndicatorProps( 'check' ) ).toEqual( { icon: check } );
		expect( getStatusIndicatorProps( 'active' ) ).toEqual( { status: 'active' } );
	} );

	it( 'draws each named glyph differently', () => {
		const glyphs = [ 'draft', 'pending', 'scheduled', 'search', 'check', 'active' ].map( glyphOf );
		expect( new Set( glyphs ).size ).toBe( glyphs.length );
	} );

	it( 'draws a custom status that names a glyph with it', () => {
		expect( getStatusIndicatorProps( 'scheduled' ) ).toEqual( { status: 'scheduled' } );
	} );

	it( 'draws a status without a glyph, or with an unknown one, neutrally', () => {
		const neutral = { status: 'pending', tone: 'neutral' };
		expect( getStatusIndicatorProps( undefined ) ).toEqual( neutral );
		expect( getStatusIndicatorProps( '' ) ).toEqual( neutral );
		expect( getStatusIndicatorProps( 'rocket' ) ).toEqual( neutral );
		expect( glyphOf( 'rocket' ) ).not.toBe( glyphOf( 'active' ) );
	} );
} );

describe( 'getWpStatusIndicatorProps', () => {
	it( 'draws each post status a story can have with its own publishing glyph', () => {
		const glyphs = [ 'publish', 'future', 'draft', 'pending', 'private' ].map( slug => statusGlyph( getWpStatusIndicatorProps( slug ).status ) );
		expect( new Set( glyphs ).size ).toBe( glyphs.length );
		expect( getWpStatusIndicatorProps( 'publish' ) ).toEqual( { status: 'active' } );
		expect( getWpStatusIndicatorProps( 'future' ) ).toEqual( { status: 'scheduled' } );
	} );

	it( 'leaves a status without a glyph as text', () => {
		expect( getWpStatusIndicatorProps( 'custom-status' ) ).toBeUndefined();
	} );
} );
