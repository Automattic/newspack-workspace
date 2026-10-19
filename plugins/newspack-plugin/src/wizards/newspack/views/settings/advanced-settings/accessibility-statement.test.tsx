// @jest-environment jsdom

/**
 * Internal dependencies
 */
import { isPage } from './accessibility-statement';

type Response = Parameters< typeof isPage >[ 0 ];

// The guard decides whether the wizard renders the page card or an empty state.
// Throwing here rejects into the fetch hook's catch, which leaves the fetching
// flag set and the Create button disabled with nothing on screen to explain it,
// so it has to stay total for anything the endpoint could hand back.
describe( 'isPage', () => {
	it( 'accepts a page payload', () => {
		expect(
			isPage( {
				editUrl: 'https://example.com/wp-admin/post.php?post=1&action=edit',
				pageUrl: 'https://example.com/accessibility-statement/',
				status: 'publish',
				title: 'Accessibility Statement',
			} )
		).toBe( true );
	} );

	it( 'rejects a reason payload', () => {
		expect( isPage( { reason: 'none' } ) ).toBe( false );
		expect( isPage( { reason: 'missing' } ) ).toBe( false );
	} );

	it( 'accepts a page whose status happens to read like a reason', () => {
		expect( isPage( { editUrl: null, pageUrl: false, status: 'none', title: 'Draft' } ) ).toBe( true );
	} );

	it( 'returns false rather than throwing on values the types rule out', () => {
		const notResponses = [ undefined, null, '', 'missing', 0, 42, true ];

		notResponses.forEach( value => {
			expect( () => isPage( value as unknown as Response ) ).not.toThrow();
			expect( isPage( value as unknown as Response ) ).toBe( false );
		} );
	} );
} );
