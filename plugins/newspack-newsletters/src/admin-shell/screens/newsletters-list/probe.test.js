import { statusGlyph, STATUS_NAMES } from 'newspack-components';

it( 'probe', () => {
	// eslint-disable-next-line no-console
	console.log( 'NAMES', JSON.stringify( STATUS_NAMES ) );
	// eslint-disable-next-line no-console
	console.log( 'active', typeof statusGlyph( 'active' ), 'draft', typeof statusGlyph( 'draft' ) );
	// eslint-disable-next-line no-console
	console.log( 'same?', statusGlyph( 'active' ) === statusGlyph( 'draft' ) );
} );
