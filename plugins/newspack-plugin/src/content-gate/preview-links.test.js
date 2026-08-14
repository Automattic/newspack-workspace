import { propagateGatePreviewParams } from './preview-links';

// The jsdom origin, whatever the runner sets it to. Expectations are built from
// it independently of the code under test.
const abs = path => new URL( path, window.location.origin ).toString();

const setSearch = search => window.history.replaceState( {}, '', '/post/' + search );
const setLinks = html => {
	document.body.innerHTML = html;
};
const hrefs = () => [ ...document.querySelectorAll( 'a' ) ].map( anchor => anchor.getAttribute( 'href' ) );

describe( 'propagateGatePreviewParams', () => {
	beforeEach( () => {
		global.newspack_content_gate = { preview_query_params: [ 'ngp_id', 'ngp_st' ] };
		setSearch( '' );
		setLinks( '' );
	} );

	it( 'does nothing outside a preview, where the params are not localized', () => {
		global.newspack_content_gate = { metadata: {} };
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="/other/">x</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'survives the global being absent entirely', () => {
		delete global.newspack_content_gate;
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="/other/">x</a>' );

		expect( () => propagateGatePreviewParams() ).not.toThrow();
		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'does nothing when no preview param is present in the URL', () => {
		setLinks( '<a href="/other/">x</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'carries the preview params onto a same-origin link', () => {
		setSearch( '?ngp_id=7&ngp_st=locked' );
		setLinks( '<a href="/other/">x</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/other/?ngp_id=7&ngp_st=locked' ) ] );
	} );

	it( 'leaves off-site links, in-page anchors and non-http schemes alone', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="https://elsewhere.test/page/">a</a><a href="#section">b</a><a href="mailto:hi@example.com">c</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ 'https://elsewhere.test/page/', '#section', 'mailto:hi@example.com' ] );
	} );

	it( 'rewrites an SVG anchor correctly rather than corrupting it', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<svg xmlns="http://www.w3.org/2000/svg"><a href="/chart/"><text>x</text></a></svg>' );

		propagateGatePreviewParams();

		// An SVGAElement's href *property* is an SVGAnimatedString; resolving it
		// yields a same-origin garbage path that silently replaces the link.
		expect( document.querySelector( 'svg a' ).getAttribute( 'href' ) ).toBe( abs( '/chart/?ngp_id=7' ) );
	} );

	it( 'resolves a relative href against the document base', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="sub/page/">x</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/post/sub/page/?ngp_id=7' ) ] );
	} );

	it( 'keeps the fragment and unrelated params, and overwrites a stale one', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="/other/?utm_source=nl&ngp_id=1#top">x</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/other/?utm_source=nl&ngp_id=7#top' ) ] );
	} );

	it( 'is idempotent, so a second pass does not duplicate params', () => {
		setSearch( '?ngp_id=7&ngp_st=locked' );
		setLinks( '<a href="/other/">x</a>' );

		propagateGatePreviewParams();
		const first = hrefs();
		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( first );
	} );
} );
