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

	it( 'draws a status without a glyph, or with an unknown one, as a plain dot', () => {
		const neutral = getStatusIndicatorProps( undefined );
		expect( neutral.status ).toBeUndefined();
		expect( neutral.icon ).toBeTruthy();
		expect( getStatusIndicatorProps( '' ) ).toBe( neutral );
		expect( getStatusIndicatorProps( 'rocket' ) ).toBe( neutral );
		[ 'draft', 'pending', 'scheduled', 'search', 'check', 'active' ].forEach( icon => {
			expect( glyphOf( icon ) ).not.toBe( neutral.icon );
		} );
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
