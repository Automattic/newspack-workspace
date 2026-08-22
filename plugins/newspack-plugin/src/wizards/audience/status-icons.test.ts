/**
 * WordPress dependencies
 */
import { drafts } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { postStatusIcon } from './status-icons';

/**
 * Pricing rules, Plans and Emails offer these statuses as separate filters, so two
 * of them sharing a glyph leaves the reader unable to tell apart the results of two
 * different filters. Pinned through the helper rather than the map behind it, since
 * that is the whole of what the three screens call.
 */
describe( 'postStatusIcon', () => {
	const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ];

	it( 'draws every post status the columns surface', () => {
		STATUSES.forEach( status => expect( postStatusIcon( status ) ).toBeTruthy() );
	} );

	it( 'gives no two statuses the same glyph', () => {
		const icons = STATUSES.map( postStatusIcon );
		expect( new Set( icons ).size ).toBe( icons.length );
	} );

	it( 'separates a live rule from a scheduled one and a binned one', () => {
		expect( postStatusIcon( 'publish' ) ).not.toBe( postStatusIcon( 'future' ) );
		expect( postStatusIcon( 'publish' ) ).not.toBe( postStatusIcon( 'trash' ) );
	} );

	// Pricing rules reads its statuses from a plugin outside this repo and builds its
	// filter elements from the rows themselves, so an unrecognised status can reach the
	// column. It draws the draft glyph, which is how an unrecognised status is treated
	// everywhere else, and is the one place the distinctness rule is knowingly relaxed.
	it( 'falls back to the draft glyph for a status it does not know', () => {
		expect( postStatusIcon( 'wc-on-hold' ) ).toBe( drafts );
		expect( postStatusIcon( '' ) ).toBe( drafts );
	} );
} );
