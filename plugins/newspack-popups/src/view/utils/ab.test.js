import { computeBucket, getAbOverride, getAssignedBucket, getReaderId, hashString } from './index.js';

const makePrompt = ( testId = null, variant = null ) => {
	const prompt = document.createElement( 'div' );
	prompt.setAttribute( 'id', 'id_123' );
	if ( testId ) {
		prompt.setAttribute( 'data-ab-test-id', testId );
		prompt.setAttribute( 'data-ab-variant', variant );
	}
	return prompt;
};

const AB_CONFIG = {
	variants: [ 'a', 'b' ],
	control_share: 50,
};

describe( 'hashString', () => {
	it( 'is deterministic and unsigned', () => {
		expect( hashString( 'reader|test' ) ).toBe( hashString( 'reader|test' ) );
		expect( hashString( 'reader|test' ) ).toBeGreaterThanOrEqual( 0 );
		expect( hashString( 'reader-a|test' ) ).not.toBe( hashString( 'reader-b|test' ) );
	} );
	it( 'matches the server-side hash bit for bit', () => {
		// Vector pinned in tests/test-ab-tests.php (Newspack_Popups_AB_Tests::hash_djb2)
		// — if either implementation changes, both parity tests fail together.
		expect( hashString( 'abc|test-x' ) ).toBe( 809016026 );
		expect( computeBucket( 'abc', 'test-x', AB_CONFIG ) ).toBe( 'a' );
	} );
} );

describe( 'getReaderId', () => {
	beforeEach( () => {
		global.newspack_popups_view = {};
	} );
	it( 'reads the default client ID cookie', () => {
		expect( getReaderId( 'foo=bar; newspack-cid=abc123' ) ).toBe( 'abc123' );
	} );
	it( 'uses the localized cookie name when provided', () => {
		global.newspack_popups_view = { cid_cookie: 'custom-cid' };
		expect( getReaderId( 'custom-cid=xyz; newspack-cid=abc123' ) ).toBe( 'xyz' );
	} );
	it( 'returns null when the cookie is absent', () => {
		expect( getReaderId( 'foo=bar' ) ).toBeNull();
		expect( getReaderId( '' ) ).toBeNull();
	} );
	it( 'returns null instead of throwing on an undecodable cookie value', () => {
		// A stray percent makes decodeURIComponent throw URIError. Uncaught, that
		// propagates out of prompt processing and no prompts render on the page, so
		// the failure has to degrade to "no reader id", not to "no prompts".
		expect( () => getReaderId( 'newspack-cid=100%' ) ).not.toThrow();
		expect( getReaderId( 'newspack-cid=100%' ) ).toBeNull();
	} );
} );

describe( 'computeBucket', () => {
	it( 'is deterministic', () => {
		expect( computeBucket( 'reader-1', 'test-x', AB_CONFIG ) ).toBe( computeBucket( 'reader-1', 'test-x', AB_CONFIG ) );
	} );
	it( 'respects the control share weighting', () => {
		const config = { variants: [ 'a', 'b' ], control_share: 80 };
		let aCount = 0;
		for ( let i = 0; i < 500; i++ ) {
			if ( 'a' === computeBucket( `reader-${ i }`, 'test-x', config ) ) {
				aCount++;
			}
		}
		expect( aCount ).toBeGreaterThan( 350 );
		expect( aCount ).toBeLessThan( 470 );
	} );
	it( 'assigns different variants across readers', () => {
		const buckets = new Set();
		for ( let i = 0; i < 50; i++ ) {
			buckets.add( computeBucket( `reader-${ i }`, 'test-x', AB_CONFIG ) );
		}
		expect( buckets.has( 'a' ) ).toBe( true );
		expect( buckets.has( 'b' ) ).toBe( true );
	} );
	it( 'falls back to control for a degenerate config', () => {
		expect( computeBucket( 'reader-1', 'test-x', { variants: [ 'a' ], control_share: 50 } ) ).toBe( 'a' );
	} );
} );

describe( 'getAssignedBucket', () => {
	beforeEach( () => {
		global.newspack_popups_view = {};
	} );
	it( 'prefers the server-echoed variant preview', () => {
		global.newspack_popups_view = { ab_view_as: 'b', ab_buckets: { 'test-x': 'a' } };
		expect( getAssignedBucket( 'test-x', AB_CONFIG, 'newspack-cid=abc' ) ).toBe( 'b' );
	} );
	it( 'ignores a previewed variant that is not part of the test', () => {
		global.newspack_popups_view = { ab_view_as: 'd' };
		expect( getAssignedBucket( 'test-x', AB_CONFIG, 'newspack-cid=abc' ) ).toBe( computeBucket( 'abc', 'test-x', AB_CONFIG ) );
	} );
	it( 'prefers the server-computed bucket over the client hash', () => {
		global.newspack_popups_view = { ab_buckets: { 'test-x': 'b' } };
		expect( getAssignedBucket( 'test-x', AB_CONFIG, 'newspack-cid=abc' ) ).toBe( 'b' );
	} );
	it( 'hashes the client ID when no server bucket exists', () => {
		expect( getAssignedBucket( 'test-x', AB_CONFIG, 'newspack-cid=abc' ) ).toBe( computeBucket( 'abc', 'test-x', AB_CONFIG ) );
	} );
	it( 'falls back to control without a stable identity', () => {
		expect( getAssignedBucket( 'test-x', AB_CONFIG, '' ) ).toBe( 'a' );
	} );
} );

describe( 'getAbOverride', () => {
	beforeEach( () => {
		global.newspack_popups_view = { ab_tests: { 'test-x': AB_CONFIG } };
	} );
	it( 'returns null for prompts that are not part of a test', () => {
		expect( getAbOverride( makePrompt(), 'newspack-cid=abc' ) ).toBeNull();
	} );
	it( 'returns null when the test is not in the localized config', () => {
		expect( getAbOverride( makePrompt( 'unknown-test', 'a' ), 'newspack-cid=abc' ) ).toBeNull();
	} );
	it( 'suppresses the non-assigned variant and passes the assigned one through', () => {
		const bucket = computeBucket( 'abc', 'test-x', AB_CONFIG );
		const loser = 'a' === bucket ? 'b' : 'a';
		expect( getAbOverride( makePrompt( 'test-x', loser ), 'newspack-cid=abc' ) ).toBe( false );
		// Never true: the assigned variant must still pass frequency/segmentation.
		expect( getAbOverride( makePrompt( 'test-x', bucket ), 'newspack-cid=abc' ) ).toBeNull();
	} );
	it( 'respects a server-echoed variant preview', () => {
		global.newspack_popups_view.ab_view_as = 'b';
		expect( getAbOverride( makePrompt( 'test-x', 'b' ), 'newspack-cid=abc' ) ).toBeNull();
		expect( getAbOverride( makePrompt( 'test-x', 'a' ), 'newspack-cid=abc' ) ).toBe( false );
	} );
	it( 'fails to control without a stable identity (one-variant invariant)', () => {
		expect( getAbOverride( makePrompt( 'test-x', 'a' ), '' ) ).toBeNull();
		expect( getAbOverride( makePrompt( 'test-x', 'b' ), '' ) ).toBe( false );
	} );
} );
