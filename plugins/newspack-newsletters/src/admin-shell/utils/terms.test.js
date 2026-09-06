// `mergeTerms` and `idsMissingFromOptions` decide whether a Quick Edit field is
// editable, so their edge cases are worth pinning directly rather than only
// through the panels that consume them.
import { idsMissingFromOptions, mergeTerms } from './terms';

describe( 'mergeTerms', () => {
	const NEWS = { id: 5, name: 'News' };
	const SPORT = { id: 6, name: 'Sport' };

	it( 'keeps what it already had when a re-attempt comes back empty', () => {
		// The failure this exists for: `fetchAllTerms` resolves with a partial
		// array, so a blipped re-attempt must not shrink a good list.
		expect( mergeTerms( [ NEWS, SPORT ], [] ) ).toEqual( [ NEWS, SPORT ] );
	} );

	it( 'keeps what it already had when a re-attempt returns a non-array', () => {
		expect( mergeTerms( [ NEWS ], undefined ) ).toEqual( [ NEWS ] );
		expect( mergeTerms( [ NEWS ], null ) ).toEqual( [ NEWS ] );
	} );

	it( 'unions the two lists', () => {
		expect( mergeTerms( [ NEWS ], [ SPORT ] ) ).toEqual( [ NEWS, SPORT ] );
	} );

	it( 'lets the newer name win for a term that was renamed', () => {
		expect( mergeTerms( [ NEWS ], [ { id: 5, name: 'Headlines' } ] ) ).toEqual( [ { id: 5, name: 'Headlines' } ] );
	} );

	it( 'sorts by name, so a recovered term is not appended out of order', () => {
		expect( mergeTerms( [ SPORT ], [ NEWS ] ).map( t => t.name ) ).toEqual( [ 'News', 'Sport' ] );
	} );

	it( 'tolerates a missing current list', () => {
		expect( mergeTerms( undefined, [ NEWS ] ) ).toEqual( [ NEWS ] );
	} );
} );

describe( 'idsMissingFromOptions', () => {
	const OPTIONS = [ { id: 5, name: 'News' } ];

	it( 'reports nothing when there are no stored ids', () => {
		expect( idsMissingFromOptions( [], OPTIONS ) ).toEqual( [] );
		expect( idsMissingFromOptions( undefined, OPTIONS ) ).toEqual( [] );
	} );

	it( 'reports nothing when the options account for every stored id', () => {
		expect( idsMissingFromOptions( [ 5 ], OPTIONS ) ).toEqual( [] );
	} );

	it( 'reports the gap when the options are incomplete', () => {
		expect( idsMissingFromOptions( [ 5, 99 ], OPTIONS ) ).toEqual( [ 99 ] );
	} );

	// The reachable failure: the request died, `fetchAllTerms` settled empty,
	// and the embed can still render the token. Every stored id is a gap, which
	// is what holds the field read-only.
	it( 'reports every stored id when the options settled empty', () => {
		expect( idsMissingFromOptions( [ 5, 99 ], [] ) ).toEqual( [ 5, 99 ] );
		expect( idsMissingFromOptions( [ 5 ], undefined ) ).toEqual( [ 5 ] );
	} );
} );
