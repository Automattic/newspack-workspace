import { propagatePreviewParams } from './preview-links';

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

describe( 'propagatePreviewParams', () => {
	beforeEach( () => {
		global.newspack_popups_view = { preview_param_names: [ 'pid', 'n_bc' ] };
		setFramed( true );
		setSearch( '' );
		setLinks( '' );
	} );

	it( 'does nothing top-level, so preview mode cannot follow an admin around the site', () => {
		setFramed( false );
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/">x</a>' );

		expectNoTraversal( propagatePreviewParams );

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'does nothing outside a preview, where the params are not localized', () => {
		global.newspack_popups_view = {};
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/">x</a>' );

		expectNoTraversal( propagatePreviewParams );

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'does nothing when no preview param is present in the URL', () => {
		setLinks( '<a href="/other/">x</a>' );

		expectNoTraversal( propagatePreviewParams );

		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'carries the preview params onto a same-origin link', () => {
		setSearch( '?pid=42&n_bc=blue' );
		setLinks( '<a href="/other/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/?pid=42&n_bc=blue' ] );
	} );

	it( 'only carries the preview params actually present in the URL', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/?pid=42' ] );
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

	it( 'leaves an unparseable href alone rather than throwing', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="http://[">x</a><a href="/other/">y</a>' );

		expect( () => propagatePreviewParams() ).not.toThrow();
		// The bad href is skipped and the pass carries on to the next anchor.
		expect( hrefs() ).toEqual( [ 'http://[', '/other/?pid=42' ] );
	} );

	it( 'keeps unrelated query params and overwrites a stale preview param', () => {
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/?utm_source=nl&pid=7">x</a>' );

		propagatePreviewParams();

		expect( hrefs() ).toEqual( [ '/other/?utm_source=nl&pid=42' ] );
	} );

	it( 'survives the global being absent entirely', () => {
		delete global.newspack_popups_view;
		setSearch( '?pid=42' );
		setLinks( '<a href="/other/">x</a>' );

		expect( () => propagatePreviewParams() ).not.toThrow();
		expectNoTraversal( propagatePreviewParams );
		expect( hrefs() ).toEqual( [ '/other/' ] );
	} );

	it( 'rewrites an SVG anchor correctly rather than corrupting it', () => {
		setSearch( '?pid=42' );
		setLinks( '<svg xmlns="http://www.w3.org/2000/svg"><a href="/chart/"><text>x</text></a></svg>' );

		propagatePreviewParams();

		// The selector matches SVG <a> too, and an SVGAElement's href *property* is
		// an SVGAnimatedString — resolving it yields a same-origin garbage path that
		// silently replaces the link. Reading the attribute keeps it intact.
		expect( document.querySelector( 'svg a' ).getAttribute( 'href' ) ).toBe( '/chart/?pid=42' );
	} );

	describe( 'href shape', () => {
		// A preview should differ from production in what it shows, not in the form
		// of its markup: theme code keyed on href shape has to behave the same here.
		it( 'keeps a root-relative href root-relative', () => {
			setSearch( '?pid=42' );
			setLinks( '<a href="/other/">x</a>' );

			propagatePreviewParams();

			expect( hrefs() ).toEqual( [ '/other/?pid=42' ] );
		} );

		it( 'keeps an absolute href absolute', () => {
			setSearch( '?pid=42' );
			setLinks( `<a href="${ window.location.origin }/other/">x</a>` );

			propagatePreviewParams();

			expect( hrefs() ).toEqual( [ `${ window.location.origin }/other/?pid=42` ] );
		} );

		it( 'leaves an opaque-path URL alone rather than flattening it to a page URL', () => {
			setSearch( '?pid=42' );
			// A blob URL reports its inner origin, so it passes the origin test; the
			// shape logic would turn it into an ordinary page URL and kill the link.
			setLinks( `<a href="blob:${ window.location.origin }/abc-123">x</a>` );

			propagatePreviewParams();

			expect( hrefs() ).toEqual( [ `blob:${ window.location.origin }/abc-123` ] );
		} );

		it( 'promotes a same-origin protocol-relative href, the one shape it cannot keep', () => {
			setSearch( '?pid=42' );
			setLinks( `<a href="//${ window.location.host }/other/">x</a>` );

			propagatePreviewParams();

			// Documented in the module: telling this apart needs a third branch. Pinned
			// so the behaviour is a decision rather than a surprise.
			expect( hrefs() ).toEqual( [ `${ window.location.origin }/other/?pid=42` ] );
		} );

		it( 'resolves a path-relative href against the document base', () => {
			setSearch( '?pid=42' );
			setLinks( '<a href="sub/page/">x</a>' );

			propagatePreviewParams();

			// Root-relative rather than absolute: resolving it is what told us where
			// it points, so this is as close to the original shape as we can get.
			expect( hrefs() ).toEqual( [ '/post/sub/page/?pid=42' ] );
		} );

		it( 'keeps the fragment on a same-origin link', () => {
			setSearch( '?pid=42' );
			setLinks( '<a href="/other/#top">x</a>' );

			propagatePreviewParams();

			expect( hrefs() ).toEqual( [ '/other/?pid=42#top' ] );
		} );
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
