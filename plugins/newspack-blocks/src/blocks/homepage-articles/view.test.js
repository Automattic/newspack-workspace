/**
 * Regression tests (NPPM-3193): the load-more handler must only ever fetch
 * and inject content from this site's own articles endpoint - never a URL
 * taken from an element matched purely by CSS class, rendered inside the
 * post content area, or one that merely looks right.
 */

describe( 'homepage-articles load more', () => {
	const BUTTON_NEXT_URL = '/wp-json/newspack-blocks/v1/articles?page=2';
	const OTHER_ORIGIN_NEXT_URL = 'https://example.test/other-origin';
	// Matches what add_query_arg() actually renders for this block's default
	// attributes (postType and includedPostStatuses both default to a
	// one-item array), captured from a real rendered page.
	const SERVER_RENDERED_NEXT_URL = '/wp-json/newspack-blocks/v1/articles?postType%5B0%5D=post&includedPostStatuses%5B0%5D=publish&page=2';

	let requestedUrls;

	class FakeXMLHttpRequest {
		open( method, url ) {
			requestedUrls.push( url );
		}
		send() {}
	}

	beforeEach( () => {
		jest.resetModules();
		requestedUrls = [];
		window.newspackBlocksIsFetching = false;
		window.newspackBlocksFetchQueue = [];
		global.XMLHttpRequest = FakeXMLHttpRequest;

		// WordPress core renders this link in <head> on every front-end page;
		// it's the non-spoofable anchor the handler checks candidate URLs
		// against.
		document.head.innerHTML = `<link rel="https://api.w.org/" href="${ window.location.origin }/wp-json/" />`;
	} );

	it( "binds the click handler to the block's own button, not an element rendered earlier in the content area", () => {
		// An element carrying `data-next` can be rendered inside the content
		// area, ahead of the real button in document order.
		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1">
					<div data-next="${ OTHER_ORIGIN_NEXT_URL }"></div>
				</div>
				<button type="button" class="wp-block-button__link" data-next="${ BUTTON_NEXT_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 1 );
		expect( requestedUrls[ 0 ] ).toContain( BUTTON_NEXT_URL );
	} );

	it( 'refuses to fetch a next URL that is not this site, whatever element it came from', () => {
		// The block is matched by CSS class, and a page can contain more than
		// one self-contained copy of that markup.
		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1">
					<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
						<div data-posts data-current-post-id="2"></div>
						<button type="button" class="wp-block-button__link" data-next="${ OTHER_ORIGIN_NEXT_URL }">
							<span class="label">Load more posts</span>
						</button>
					</div>
				</div>
				<button type="button" class="wp-block-button__link" data-next="${ BUTTON_NEXT_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( `button[data-next="${ OTHER_ORIGIN_NEXT_URL }"]` ).click();

		expect( requestedUrls ).toHaveLength( 0 );
	} );

	it( 'refuses a same-origin URL whose path is not the REST root, even with a route-naming query parameter', () => {
		const SAME_ORIGIN_OTHER_PATH_URL = `${ window.location.origin }/wp-content/uploads/payload.txt?rest_route=/newspack-blocks/v1/articles`;

		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1"></div>
				<button type="button" class="wp-block-button__link" data-next="${ SAME_ORIGIN_OTHER_PATH_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 0 );
	} );

	it( 'refuses a URL whose path names the articles endpoint but whose query string names a different route', () => {
		const OVERRIDDEN_ROUTE_URL = `${ window.location.origin }/wp-json/newspack-blocks/v1/articles?rest_route=/wp/v2/types/post`;

		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1"></div>
				<button type="button" class="wp-block-button__link" data-next="${ OVERRIDDEN_ROUTE_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 0 );
	} );

	it( 'refuses a route override under an unusual query-key spelling', () => {
		const UNUSUAL_SPELLING_URL = `${ window.location.origin }/wp-json/newspack-blocks/v1/articles?${ encodeURIComponent(
			' rest_route'
		) }=/wp/v2/types/post`;

		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1"></div>
				<button type="button" class="wp-block-button__link" data-next="${ UNUSUAL_SPELLING_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 0 );
	} );

	it( 'does not let a stray data-post-id value smuggle an extra query parameter into the request', () => {
		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-article-ids">
				<span data-post-id="1&amp;rest_route=/wp/v2/types/post"></span>
			</div>
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="2"></div>
				<button type="button" class="wp-block-button__link" data-next="${ BUTTON_NEXT_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 1 );
		expect( requestedUrls[ 0 ] ).not.toContain( 'rest_route' );
	} );

	it( 'accepts a real server-rendered next URL carrying the array-valued block attributes WordPress always encodes', () => {
		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1"></div>
				<button type="button" class="wp-block-button__link" data-next="${ SERVER_RENDERED_NEXT_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 1 );
		expect( requestedUrls[ 0 ] ).toContain( 'postType' );
	} );

	it( 'accepts a server-rendered next URL for a block with a custom-taxonomy filter configured', () => {
		// add_query_arg() nests an array-of-objects attribute (this block's
		// customTaxonomies filter) two levels deep: name[0][slug] and
		// name[0][terms][0].
		const CUSTOM_TAXONOMY_NEXT_URL =
			'/wp-json/newspack-blocks/v1/articles?customTaxonomies%5B0%5D%5Bslug%5D=format&customTaxonomies%5B0%5D%5Bterms%5D%5B0%5D=5&page=2';

		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1"></div>
				<button type="button" class="wp-block-button__link" data-next="${ CUSTOM_TAXONOMY_NEXT_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;

		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 1 );
		expect( requestedUrls[ 0 ] ).toContain( 'customTaxonomies' );
	} );
	describe( 'URL shapes', () => {
		const clickLoadMore = nextUrl => {
			document.body.innerHTML = `
				<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
					<div data-posts data-current-post-id="1"></div>
					<button type="button" class="wp-block-button__link" data-next="${ nextUrl }">
						<span class="label">Load more posts</span>
					</button>
				</div>
			`;

			require( './view.js' );

			document.querySelector( 'button[data-next]' ).click();
		};

		const usePlainPermalinks = () => {
			document.head.innerHTML = `<link rel="https://api.w.org/" href="${ window.location.origin }/index.php?rest_route=/" />`;
		};

		it( 'accepts a plain-permalink next URL that names the articles route', () => {
			usePlainPermalinks();

			clickLoadMore( '/index.php?rest_route=/newspack-blocks/v1/articles&amp;postType%5B0%5D=post&amp;page=2' );

			expect( requestedUrls ).toHaveLength( 1 );
			expect( requestedUrls[ 0 ] ).toContain( 'rest_route' );
		} );

		it( 'refuses a plain-permalink next URL padded with enough parameters to change which route PHP reads', () => {
			usePlainPermalinks();

			const padding = Array.from( { length: 1000 }, () => 'p=1' ).join( '&amp;' );

			clickLoadMore( `/index.php?rest_route=/wp/v2/types/post&amp;${ padding }&amp;rest_route=/newspack-blocks/v1/articles` );

			expect( requestedUrls ).toHaveLength( 0 );
		} );

		it( 'accepts a next URL for a block with a large selection of terms', () => {
			const categories = Array.from( { length: 200 }, ( _, i ) => `categories%5B${ i }%5D=${ i + 1 }` ).join( '&amp;' );

			clickLoadMore( `/wp-json/newspack-blocks/v1/articles?${ categories }&amp;page=2` );

			expect( requestedUrls ).toHaveLength( 1 );
		} );

		it( 'refuses a same-origin page whose permalink merely ends in the articles route', () => {
			clickLoadMore( '/some-page/newspack-blocks/v1/articles' );

			expect( requestedUrls ).toHaveLength( 0 );
		} );

		it( 'accepts the articles route under a non-default REST prefix advertised in <head>', () => {
			document.head.innerHTML = `<link rel="https://api.w.org/" href="${ window.location.origin }/api/" />`;

			clickLoadMore( '/api/newspack-blocks/v1/articles?page=2' );

			expect( requestedUrls ).toHaveLength( 1 );
		} );

		it( 'refuses the default REST path when <head> advertises a different REST root', () => {
			document.head.innerHTML = `<link rel="https://api.w.org/" href="${ window.location.origin }/api/" />`;

			clickLoadMore( '/wp-json/newspack-blocks/v1/articles?page=2' );

			expect( requestedUrls ).toHaveLength( 0 );
		} );

		it( 'accepts the default REST path on a page whose <head> has no REST link', () => {
			document.head.innerHTML = '';

			clickLoadMore( '/wp-json/newspack-blocks/v1/articles?page=2' );

			expect( requestedUrls ).toHaveLength( 1 );
		} );
	} );
} );
