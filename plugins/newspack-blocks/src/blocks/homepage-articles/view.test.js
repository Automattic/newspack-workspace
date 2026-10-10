/**
 * Regression tests (NPPM-3193): the load-more handler must only ever fetch
 * and inject content from this site's own articles endpoint - never a URL
 * taken from an element matched purely by CSS class, rendered inside the
 * post content area, or one that merely looks right.
 */

describe( 'homepage-articles load more', () => {
	const BUTTON_NEXT_URL = '/wp-json/newspack-blocks/v1/articles?page=2';
	const OTHER_ORIGIN_NEXT_URL = 'https://example.test/other-origin';

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
} );
