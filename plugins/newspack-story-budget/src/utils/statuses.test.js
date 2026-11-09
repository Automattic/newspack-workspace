import { statusGlyph } from 'newspack-components/src/status-indicator';

import { getStatusIndicatorProps } from './statuses';

const glyphOf = slug => {
	const props = getStatusIndicatorProps( slug );
	return props.icon || statusGlyph( props.status );
};

describe( 'getStatusIndicatorProps', () => {
	it( 'draws each default status, and a custom one, with its own glyph', () => {
		const glyphs = [ 'writing', 'editing', 'factcheck', 'approved', 'published', 'custom-status' ].map( glyphOf );
		expect( new Set( glyphs ).size ).toBe( glyphs.length );
	} );

	it( 'draws a status added through the filter as a stage in progress', () => {
		expect( getStatusIndicatorProps( 'legal-review' ) ).toEqual( { status: 'progress' } );
	} );
} );
