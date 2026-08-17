import { propagateGatePreviewParams } from './preview-links';

const setSearch = search => window.history.replaceState( {}, '', '/post/' + search );
const setLinks = html => {
	document.body.innerHTML = html;
};
const hrefs = () => [ ...document.querySelectorAll( 'a' ) ].map( anchor => anchor.getAttribute( 'href' ) );

// Propagation only runs inside the preview iframe, so every positive case has to
// look framed. jsdom's `top` points at the window itself.
const setFramed = framed => {
	Object.defineProperty( window, 'top', { value: framed ? {} : window, configurable: true } );
};

// Pins the contract that the early returns bail before touching the DOM at all,
// which is what keeps this off the critical path of an ordinary page load. An
// assertion on hrefs alone would still pass if the function walked every anchor
// and happened to rewrite nothing.
const expectNoTraversal = run => {
	const spy = jest.spyOn( document, 'querySelectorAll' );
	run();
	expect( spy ).not.toHaveBeenCalled();
	spy.mockRestore();
};

describe( 'propagateGatePreviewParams', () => {
	beforeEach( () => {
		global.newspack_content_gate = { preview_param_names: [ 'ngp_id', 'ngp_st' ] };
		setFramed( true );
		setSearch( '' );
		setLinks( '' );
	} );

	it( 'does nothing top-level, so preview mode cannot follow an editor around the site', () => {
		setFramed( false );
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="/other/">x</a>' );

		expectNoTraversal( propagateGatePreviewParams );

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'does nothing outside a preview, where the params are not localized', () => {
		global.newspack_content_gate = { metadata: {} };
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="/other/">x</a>' );

		expectNoTraversal( propagateGatePreviewParams );

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

		expectNoTraversal( propagateGatePreviewParams );

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'carries the preview params onto a same-origin link', () => {
		setSearch( '?ngp_id=7&ngp_st=locked' );
		setLinks( '<a href="/other/">x</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/?ngp_id=7&ngp_st=locked' ] );
	} );

	it( 'leaves off-site links, in-page anchors and non-http schemes alone', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="https://elsewhere.test/page/">a</a><a href="#section">b</a><a href="mailto:hi@example.com">c</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ 'https://elsewhere.test/page/', '#section', 'mailto:hi@example.com' ] );
	} );

	it( 'leaves an unparseable href alone rather than throwing', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="http://[">x</a><a href="/other/">y</a>' );

		expect( () => propagateGatePreviewParams() ).not.toThrow();
		// The bad href is skipped and the pass carries on to the next anchor.
		expect( hrefs() ).toEqual( [ 'http://[', '/other/?ngp_id=7' ] );
	} );

	it( 'rewrites an SVG anchor correctly rather than corrupting it', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<svg xmlns="http://www.w3.org/2000/svg"><a href="/chart/"><text>x</text></a></svg>' );

		propagateGatePreviewParams();

		// An SVGAElement's href *property* is an SVGAnimatedString; resolving it
		// yields a same-origin garbage path that silently replaces the link.
		expect( document.querySelector( 'svg a' ).getAttribute( 'href' ) ).toBe( '/chart/?ngp_id=7' );
	} );

	describe( 'href shape', () => {
		// A preview should differ from production in what it shows, not in the form
		// of its markup: theme code keyed on href shape has to behave the same here.
		it( 'keeps a root-relative href root-relative', () => {
			setSearch( '?ngp_id=7' );
			setLinks( '<a href="/other/">x</a>' );

			propagateGatePreviewParams();

			expect( hrefs() ).toEqual( [ '/other/?ngp_id=7' ] );
		} );

		it( 'keeps an absolute href absolute', () => {
			setSearch( '?ngp_id=7' );
			setLinks( `<a href="${ window.location.origin }/other/">x</a>` );

			propagateGatePreviewParams();

			expect( hrefs() ).toEqual( [ `${ window.location.origin }/other/?ngp_id=7` ] );
		} );

		it( 'leaves an opaque-path URL alone rather than flattening it to a page URL', () => {
			setSearch( '?ngp_id=7' );
			// A blob URL reports its inner origin, so it passes the origin test; the
			// shape logic would turn it into an ordinary page URL and kill the link.
			setLinks( `<a href="blob:${ window.location.origin }/abc-123">x</a>` );

			propagateGatePreviewParams();

			expect( hrefs() ).toEqual( [ `blob:${ window.location.origin }/abc-123` ] );
		} );

		it( 'promotes a same-origin protocol-relative href, the one shape it cannot keep', () => {
			setSearch( '?ngp_id=7' );
			setLinks( `<a href="//${ window.location.host }/other/">x</a>` );

			propagateGatePreviewParams();

			expect( hrefs() ).toEqual( [ `${ window.location.origin }/other/?ngp_id=7` ] );
		} );

		it( 'resolves a path-relative href against the document base', () => {
			setSearch( '?ngp_id=7' );
			setLinks( '<a href="sub/page/">x</a>' );

			propagateGatePreviewParams();

			// Root-relative rather than absolute: resolving it is what told us where
			// it points, so this is as close to the original shape as we can get.
			expect( hrefs() ).toEqual( [ '/post/sub/page/?ngp_id=7' ] );
		} );
	} );

	it( 'keeps the fragment and unrelated params, and overwrites a stale one', () => {
		setSearch( '?ngp_id=7' );
		setLinks( '<a href="/other/?utm_source=nl&ngp_id=1#top">x</a>' );

		propagateGatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/?utm_source=nl&ngp_id=7#top' ] );
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
