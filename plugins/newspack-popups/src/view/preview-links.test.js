import { propagatePreviewParams } from './preview-links';

// The jsdom origin, whatever the runner sets it to. Expectations are built from
// it independently of the code under test.
const origin = () => window.location.origin;
const abs = path => new URL( path, origin() ).toString();

const setSearch = search => window.history.replaceState( {}, '', '/post/' + search );
const setLinks = html => {
	document.body.innerHTML = html;
};
const hrefs = () => [ ...document.querySelectorAll( 'a' ) ].map( anchor => anchor.getAttribute( 'href' ) );

describe( 'propagatePreviewParams', () => {
	beforeEach( () => {
		global.newspack_popups_view = { preview_query_params: [ 'pid', 'n_bc' ] };
		setSearch( '' );
		setLinks( '' );
	} );

	it( 'does nothing outside a preview, where the params are not localized', () => {
		global.newspack_popups_view = {};
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'does nothing when no preview param is present in the URL', () => {
		setLinks( '<a href="/other/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'carries the preview params onto a same-origin link', () => {
		setSearch( '?pid=42&n_bc=blue' );
		setLinks( '<a href="/other/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/other/?pid=42&n_bc=blue' ) ] );
	} );

	it( 'only carries the preview params actually present in the URL', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/other/?pid=42' ) ] );
	} );

	it( 'leaves off-site links alone', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="https://elsewhere.test/page/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ 'https://elsewhere.test/page/' ] );
	} );

	it( 'leaves in-page anchors alone, so they still jump rather than reload', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="#section">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ '#section' ] );
	} );

	it( 'leaves non-http schemes alone', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="mailto:hi@example.com">x</a><a href="tel:+15551234">y</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ 'mailto:hi@example.com', 'tel:+15551234' ] );
	} );

	it( 'keeps unrelated query params and overwrites a stale preview param', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/?utm_source=nl&pid=7">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/other/?utm_source=nl&pid=42' ) ] );
	} );

	it( 'survives the global being absent entirely', () => {
		delete global.newspack_popups_view;
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/">x</a>' );

		expect( () => propagatePreviewParams() ).not.toThrow();
		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'rewrites an SVG anchor correctly rather than corrupting it', () => {
		setSearch( '?pid=42' );
		setLinks( '<svg xmlns="http://www.w3.org/2000/svg"><a href="/chart/"><text>x</text></a></svg>' );

		propagatePreviewParams();

		// The selector matches SVG <a> too, and an SVGAElement's href *property* is
		// an SVGAnimatedString — resolving it yields a same-origin garbage path that
		// silently replaces the link. Reading the attribute keeps it intact.
		expect( document.querySelector( 'svg a' ).getAttribute( 'href' ) ).toBe( abs( '/chart/?pid=42' ) );
	} );

	it( 'resolves a relative href against the document base', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="sub/page/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/post/sub/page/?pid=42' ) ] );
	} );

	it( 'keeps the fragment on a same-origin link', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/#top">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ abs( '/other/?pid=42#top' ) ] );
	} );

	it( 'is idempotent, so a second pass does not duplicate params', () => {
		setSearch( '?pid=42&n_bc=blue' );
		setLinks( '<a href="/other/">x</a>' );

		propagatePreviewParams();
		const first = hrefs();
		propagatePreviewParams();

		expect( hrefs() ).toEqual( first );
	} );
} );
