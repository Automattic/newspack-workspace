/**
 * Regression test (NPPM-3193): the load-more button lookup must match only
 * the block's own button, never an element carrying the same `data-next`
 * attribute rendered inside the post content area above it.
 */

describe( 'homepage-articles load more button lookup', () => {
	const BUTTON_NEXT_URL = '/wp-json/newspack-blocks/v1/articles?page=2';
	const CONTENT_AREA_NEXT_URL = 'https://example.test/other-origin-payload';

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

		// A `data-next` carrying element rendered inside the content area,
		// ahead of the real button in document order, matches the shape kses
		// allows through the post `data-*` global attribute allowlist.
		document.body.innerHTML = `
			<div class="wp-block-newspack-blocks-homepage-articles has-more-button">
				<div data-posts data-current-post-id="1">
					<div data-next="${ CONTENT_AREA_NEXT_URL }"></div>
				</div>
				<button type="button" class="wp-block-button__link" data-next="${ BUTTON_NEXT_URL }">
					<span class="label">Load more posts</span>
				</button>
			</div>
		`;
	} );

	it( "binds the click handler to the block's own button, not an element rendered earlier in the content area", () => {
		require( './view.js' );

		document.querySelector( 'button[data-next]' ).click();

		expect( requestedUrls ).toHaveLength( 1 );
		expect( requestedUrls[ 0 ] ).toContain( BUTTON_NEXT_URL );
	} );
} );
