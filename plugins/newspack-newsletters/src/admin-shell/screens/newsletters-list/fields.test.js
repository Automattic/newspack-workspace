// The `newspack-components` stub only intercepts the bare package specifier, so the
// vocabulary itself is still reachable and the rule can be asserted on marks rather
// than on a copy of the pair list kept here.

import { STATUS_KIND_STATUSES } from './fields';
import { statusGlyph } from 'newspack-components/src/status-indicator';

describe( 'STATUS_KIND_STATUSES', () => {
	it( 'names every kind the list can report', () => {
		expect( STATUS_KIND_STATUSES ).toEqual( { sent: 'done', scheduled: 'scheduled', draft: 'draft', trash: 'trash' } );
	} );

	it( 'gives no two kinds the same mark', () => {
		const glyphs = Object.values( STATUS_KIND_STATUSES ).map( name => statusGlyph( name ) );
		expect( new Set( glyphs ).size ).toBe( glyphs.length );
	} );

	it( 'reads a sent newsletter as finished', () => {
		expect( STATUS_KIND_STATUSES.sent ).toBe( 'done' );
	} );
} );
