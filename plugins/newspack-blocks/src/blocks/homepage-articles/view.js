/**
 * VIEW
 * JavaScript used on front of site.
 */

/**
 * Style dependencies
 */
import './view.scss';

const fetchRetryCount = 3;

/**
 * Load More Button Handling
 *
 * Calls Array.prototype.forEach for IE11 compatibility.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/NodeList
 */
Array.prototype.forEach.call( document.querySelectorAll( '.wp-block-newspack-blocks-homepage-articles.has-more-button' ), buildLoadMoreHandler );

/**
 * Builds a function to handle clicks on the load more button.
 * Creates internal state via closure to ensure all state is
 * isolated to a single Block + button instance.
 *
 * @param {HTMLElement} blockWrapperEl the block's wrapper element
 */
function buildLoadMoreHandler( blockWrapperEl ) {
	// Scoped to the wrapper's own direct children, since the button is one of
	// them in the server-rendered markup. IE11 has no :scope support in
	// querySelector(), so this is a manual filter rather than `:scope > [data-next]`.
	const btnEl = Array.prototype.filter.call( blockWrapperEl.children, el => el.hasAttribute( 'data-next' ) )[ 0 ];
	if ( ! btnEl ) {
		return;
	}
	const postsContainerEl = blockWrapperEl.querySelector( '[data-posts]' );
	const isInfiniteScroll = btnEl.getAttribute( 'data-infinite-scroll' );

	// Set initial state flags.
	window.newspackBlocksIsFetching = window.newspackBlocksIsFetching || false;
	window.newspackBlocksFetchQueue = window.newspackBlocksFetchQueue || [];
	let isEndOfData = false;
	let isPending = false;

	const maybeLoadMore = () => {
		if ( isPending ) {
			return;
		}
		isPending = true;
		loadMore();
	};

	const loadMore = () => {
		// Early return if no more posts to render.
		if ( isEndOfData ) {
			return false;
		}

		blockWrapperEl.classList.remove( 'is-error' );
		blockWrapperEl.classList.add( 'is-loading' );

		const nextUrl = btnEl.getAttribute( 'data-next' );

		// The wrapper is matched by CSS class alone, which content elsewhere
		// on the page can also carry, so the URL has to be checked before
		// it's fetched - checking which element it came from isn't enough.
		if ( ! isNextUrlTrusted( nextUrl ) ) {
			// This instance never joined the shared fetch queue for this
			// attempt, so failing here must not touch the lock or queue
			// another block's in-progress fetch is relying on.
			blockWrapperEl.classList.remove( 'is-loading' );
			blockWrapperEl.classList.add( 'is-error' );
			isPending = false;
			return false;
		}

		// Set currently rendered posts' IDs as a query param (e.g. exclude_ids=1,2,3)
		const requestURL = nextUrl + '&exclude_ids=' + getRenderedPostsIds().join( ',' );

		// If there's already a fetch in progress, queue this one to run after it ends.
		if ( window.newspackBlocksIsFetching ) {
			window.newspackBlocksFetchQueue.push( loadMore );
			return false;
		}

		window.newspackBlocksIsFetching = true;
		fetchWithRetry( { url: requestURL, onSuccess, onError }, fetchRetryCount );
	};

	/**
	 * @param {Object} data Post data
	 */
	function onSuccess( data ) {
		// Validate received data.
		if ( ! isPostsDataValid( data ) ) {
			return onError();
		}

		if ( data.items.length ) {
			// Render posts' HTML from string.
			const postsHTML = data.items.map( item => item.html ).join( '' );
			postsContainerEl.insertAdjacentHTML( 'beforeend', postsHTML );
		}

		const hasTrustedNext = Boolean( data.next ) && isNextUrlTrusted( data.next );

		if ( hasTrustedNext ) {
			// Save next URL as button's attribute.
			btnEl.setAttribute( 'data-next', data.next );
		}

		if ( ! data.items.length || ! hasTrustedNext ) {
			isEndOfData = true;
			blockWrapperEl.classList.remove( 'has-more-button' );
		}

		onEnd();
	}

	/**
	 * Handle fetching error
	 */
	function onError() {
		blockWrapperEl.classList.add( 'is-error' );
		onEnd();
	}

	/**
	 * Callback to run after a fetch request is completed.
	 */
	function onEnd() {
		window.newspackBlocksIsFetching = false;
		blockWrapperEl.classList.remove( 'is-loading' );

		// If there are queued fetches, run the next one.
		if ( window.newspackBlocksFetchQueue.length ) {
			window.newspackBlocksFetchQueue.shift()();
		}
		isPending = false;
	}

	btnEl.addEventListener( 'click', maybeLoadMore );

	if ( isInfiniteScroll ) {
		// Create an intersection observer instance
		const btnObserver = new IntersectionObserver(
			entries => {
				entries.forEach( entry => {
					if ( entry.isIntersecting ) {
						maybeLoadMore();
					}
				} );
			},
			{
				root: null,
				rootMargin: '0px',
				threshold: 1,
			}
		);
		btnObserver.observe( btnEl );
	}
}

/**
 * Returns unique IDs for posts that are currently in the DOM.
 */
function getRenderedPostsIds() {
	const postEls = document.querySelectorAll( "[class^='wp-block-newspack-blocks'] [data-post-id]" );
	const postIds = Array.from( postEls ).map( el => el.getAttribute( 'data-post-id' ) );

	postIds.push( document.querySelector( 'div[data-current-post-id]' ).getAttribute( 'data-current-post-id' ) );

	return [ ...new Set( postIds ) ]; // Make values unique with Set
}

/**
 * Wrapper for XMLHttpRequest that performs given number of retries when error
 * occurs.
 *
 * @param {Object} options XMLHttpRequest options
 * @param {number} n       retry count before throwing
 */
function fetchWithRetry( options, n ) {
	const xhr = new XMLHttpRequest();

	xhr.onreadystatechange = () => {
		// Return if the request is completed.
		if ( xhr.readyState !== 4 ) {
			return;
		}

		// Call onSuccess with parsed JSON if the request is successful.
		if ( xhr.status >= 200 && xhr.status < 300 ) {
			const data = JSON.parse( xhr.responseText );

			return options.onSuccess( data );
		}

		// Call onError if the request has failed n + 1 times (or if n is undefined).
		if ( ! n ) {
			return options.onError();
		}

		// Retry fetching if request has failed and n > 0.
		return fetchWithRetry( options, n - 1 );
	};

	xhr.open( 'GET', options.url );
	xhr.send();
}

/**
 * Validates the "Load more" posts endpoint schema:
 * {
 * 	"type": "object",
 * 	"properties": {
 * 		"items": {
 * 			"type": "array",
 * 			"items": {
 * 				"type": "object",
 * 				"properties": {
 * 					"html": {
 * 						"type": "string"
 * 					}
 * 				},
 * 				"required": ["html"]
 * 			},
 * 			"required": ["items"]
 * 		},
 * 		"next": {
 * 			"type": ["string", "null"]
 * 		}
 * 	},
 * 	"required": ["items", "next"]
 * }
 *
 * @param {Object} data posts endpoint payload
 */
function isPostsDataValid( data ) {
	let isValid = false;

	if ( data && hasOwnProp( data, 'items' ) && Array.isArray( data.items ) && hasOwnProp( data, 'next' ) && typeof data.next === 'string' ) {
		isValid = true;

		if ( data.items.length && ! ( hasOwnProp( data.items[ 0 ], 'html' ) && typeof data.items[ 0 ].html === 'string' ) ) {
			isValid = false;
		}
	}

	return isValid;
}

/**
 * Checks if object has own property.
 *
 * @param {Object} obj  Object
 * @param {string} prop Property to check
 */
function hasOwnProp( obj, prop ) {
	return Object.prototype.hasOwnProperty.call( obj, prop );
}

/**
 * This site's REST API root, read from the link WordPress core renders in
 * `<head>` on every front-end page. `<head>` is never built from post
 * content, so - unlike a `data-next` attribute - this value can't be
 * spoofed by anything kses lets through.
 */
function getTrustedRestRoot() {
	const link = document.querySelector( 'link[rel="https://api.w.org/"]' );

	if ( ! link ) {
		return null;
	}

	try {
		return new URL( link.getAttribute( 'href' ), window.location.origin );
	} catch ( e ) {
		return null;
	}
}

/**
 * PHP folds `.`, a space, and a bare `[` in a query parameter's name to `_`
 * when building `$_GET`, so `rest.route`, `rest route` and `rest[route` all
 * set the same request var a pretty-permalink URL's rewrite rule also sets -
 * and PHP keeps the LAST occurrence of a repeated key, which
 * `URLSearchParams.get()` does not. Collect every value under any spelling,
 * in order, so the caller can check what WordPress would actually route to.
 *
 * @param {URL} parsed
 */
function getRestRouteOverrides( parsed ) {
	const values = [];

	for ( const [ key, value ] of parsed.searchParams.entries() ) {
		if ( key.replace( /[.[ ]/g, '_' ) === 'rest_route' ) {
			values.push( value );
		}
	}

	return values;
}

/**
 * Checks whether a "next" URL resolves to this site's own articles REST
 * route - not just whether its path looks right, since an explicit
 * `rest_route`-shaped query parameter can make WordPress route an
 * otherwise-correct-looking URL somewhere else.
 *
 * @param {string} url candidate "next" URL
 */
function isNextUrlTrusted( url ) {
	const restRoot = getTrustedRestRoot();

	if ( ! restRoot ) {
		return false;
	}

	let parsed;

	try {
		parsed = new URL( url, window.location.origin );
	} catch ( e ) {
		return false;
	}

	if ( parsed.origin !== restRoot.origin ) {
		return false;
	}

	const route = 'newspack-blocks/v1/articles';
	const overrides = getRestRouteOverrides( parsed );

	if ( ! restRoot.search ) {
		// Pretty permalinks: a request to our own endpoint never needs a
		// rest_route override, under any spelling.
		return overrides.length === 0 && parsed.pathname === restRoot.pathname + route;
	}

	// Plain permalinks: everything goes through the REST root's own path,
	// naming the route via rest_route. Mirror PHP's last-value-wins rule.
	return parsed.pathname === restRoot.pathname && overrides[ overrides.length - 1 ] === '/' + route;
}
