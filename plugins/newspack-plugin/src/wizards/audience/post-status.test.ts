/**
 * Internal dependencies
 */
import { postStatus } from './post-status';
import { statusGlyph } from '../../../packages/components/src/status-indicator';

/**
 * Pricing rules, Plans and Emails offer these statuses as separate filters, so two
 * of them drawing the same mark leaves the reader unable to tell apart the results
 * of two different filters. Two vocabulary names can share a glyph, so the rule is
 * asserted on the glyphs rather than on the names.
 */
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

	// The one place the distinctness rule is knowingly relaxed: an unrecognised
	// status draws the draft mark, alongside Draft itself.
	it( 'falls back to draft for a status it does not know', () => {
		expect( postStatus( 'wc-on-hold' ) ).toBe( 'draft' );
		expect( postStatus( '' ) ).toBe( 'draft' );
	} );
} );
