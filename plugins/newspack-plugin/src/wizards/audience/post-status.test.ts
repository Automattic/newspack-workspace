/**
 * Internal dependencies
 */
import { postStatus } from './post-status';
import { statusGlyph } from '../../../packages/components/src/status-indicator';

// These statuses are offered as separate filters, so two drawing the same mark
// leaves the reader unable to tell apart the results of two of them.
describe( 'postStatus', () => {
	const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ];

	it( 'names every post status the columns surface', () => {
		STATUSES.forEach( status => expect( postStatus( status ) ).toBeTruthy() );
	} );

	it( 'gives no two statuses the same mark', () => {
		const glyphs = STATUSES.map( status => statusGlyph( postStatus( status ) ) );
		expect( new Set( glyphs ).size ).toBe( STATUSES.length );
	} );

	it( 'separates a live rule from a scheduled one and a binned one', () => {
		expect( postStatus( 'publish' ) ).toBe( 'active' );
		expect( postStatus( 'future' ) ).toBe( 'scheduled' );
		expect( postStatus( 'trash' ) ).toBe( 'trash' );
	} );

	// The one place the rule is knowingly relaxed.
	it( 'falls back to draft for a status it does not know', () => {
		expect( postStatus( 'wc-on-hold' ) ).toBe( 'draft' );
		expect( postStatus( '' ) ).toBe( 'draft' );
	} );
} );
