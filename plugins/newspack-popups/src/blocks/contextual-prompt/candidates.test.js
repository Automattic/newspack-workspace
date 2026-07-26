import apiFetch from '@wordpress/api-fetch';

import { framingForPosition, generateCandidates } from './candidates';

jest.mock( '@wordpress/api-fetch' );

describe( 'framingForPosition', () => {
	// Boundary cases mirroring get_placement() in
	// class-newspack-popups-contextual-prompt-block.php: the two must agree.
	it.each( [
		[ 0, 1, 'top' ],
		[ 0, 4, 'top' ],
		[ 1, 4, 'top' ], // ratio exactly 1/3.
		[ 2, 4, 'end' ], // ratio exactly 2/3.
		[ 3, 4, 'end' ],
		[ 2, 6, 'mid' ],
		[ 0, 10, 'top' ],
		[ 3, 10, 'top' ], // 3/9 = 1/3.
		[ 4, 10, 'mid' ],
		[ 6, 10, 'end' ], // 6/9 = 2/3.
		[ 9, 10, 'end' ],
	] )( 'index %i of %i → %s', ( index, total, expected ) => {
		expect( framingForPosition( index, total ) ).toEqual( expected );
	} );
} );

describe( 'generateCandidates', () => {
	const args = { postId: 1, content: '' };

	it( 'returns the candidate list from a valid response', async () => {
		const candidates = [ { body: 'Support us.', framing: 'top' } ];
		apiFetch.mockResolvedValueOnce( { candidates } );
		await expect( generateCandidates( args ) ).resolves.toEqual( candidates );
	} );

	it( 'unwraps a data-enveloped response', async () => {
		const candidates = [ { body: 'Support us.', framing: 'end' } ];
		apiFetch.mockResolvedValueOnce( { data: { candidates } } );
		await expect( generateCandidates( args ) ).resolves.toEqual( candidates );
	} );

	it.each( [
		[ 'candidates is an object', { candidates: {} } ],
		[ 'candidates is a string', { candidates: 'nope' } ],
		[ 'candidates is missing', {} ],
		[ 'the response is a string', 'nope' ],
		[ 'the response is null', null ],
	] )( 'rejects when %s', async ( label, response ) => {
		apiFetch.mockResolvedValueOnce( response );
		await expect( generateCandidates( args ) ).rejects.toThrow();
	} );

	it( 'drops entries the UI cannot render', async () => {
		apiFetch.mockResolvedValueOnce( {
			candidates: [
				null,
				'just a string',
				[ 'an', 'array' ],
				{ framing: 'top' }, // Missing body.
				{ body: '   ', framing: 'top' }, // Blank body.
				{ body: 42, framing: 'top' }, // Non-string body.
				{ body: 'ok', framing: { nested: true } }, // Non-string framing.
				{ body: 'ok', framing: 'sideways' }, // Unknown framing.
				{ body: 'kept', framing: 'mid' },
				{ body: 'kept too' }, // Framing is optional.
			],
		} );
		await expect( generateCandidates( args ) ).resolves.toEqual( [ { body: 'kept', framing: 'mid' }, { body: 'kept too' } ] );
	} );
} );
