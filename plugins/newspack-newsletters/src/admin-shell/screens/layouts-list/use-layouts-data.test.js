import { buildPath } from './use-layouts-data';

const statusesFor = view => new URLSearchParams( buildPath( view ).split( '?' )[ 1 ] ).get( 'status' ).split( ',' );

describe( 'layouts buildPath', () => {
	it( 'returns an empty path when the view is not ready', () => {
		expect( buildPath( null ) ).toBe( '' );
	} );

	it( 'excludes auto-draft so an abandoned "Add new" never reaches the list', () => {
		expect( statusesFor( {} ) ).not.toContain( 'auto-draft' );
	} );

	it( 'lists the writable statuses layouts support', () => {
		expect( statusesFor( {} ).sort() ).toEqual( [ 'draft', 'pending', 'private', 'publish' ] );
	} );
} );
