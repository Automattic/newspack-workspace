/**
 * Regression tests (NPPM-3193): the load-more handler must only ever fetch
 * and inject content from this site's own articles endpoint - never a URL
 * taken from an element matched purely by CSS class, or rendered inside the
 * post content area.
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
		// The block is matched by CSS class alone, and content can carry
		// whatever class and attributes it wants, so a second, self-contained
		// copy of the markup can appear inside the content area and be read
		// as its own block instance.
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
} );
