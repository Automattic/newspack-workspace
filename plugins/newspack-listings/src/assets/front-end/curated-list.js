import './curated-list.scss';
import './listing.scss';

/**
 * VIEW
 * JavaScript used on front of site.
 */

const fetchRetryCount = 3;

/**
 * Resolves a candidate URL the same way the browser resolves it when the
 * value is actually used as a request URL (XMLHttpRequest.open() resolves a
 * relative string against the current document, not just its origin) -
 * checking one resolution and fetching another is itself a way for a value
 * to pass validation while requesting something else.
 *
 * @param {string} url candidate URL
 */
function resolveUrl( url ) {
	try {
		return new URL( url, window.location.href );
	} catch ( e ) {
		return null;
	}
}

/**
 * This site's REST API root, read from the link WordPress core renders in
 * `<head>` on every front-end page. `<head>` is never built from post
 * content, so - unlike a `data-next` or `data-url` attribute - this value
 * can't be spoofed by anything kses lets through.
 */
function getTrustedRestRoot() {
	const link = document.querySelector( 'link[rel="https://api.w.org/"]' );

	return link ? resolveUrl( link.getAttribute( 'href' ) ) : null;
}

/**
 * A query parameter name this plugin's own server-generated URLs can ever
 * carry: plain identifier characters, followed by zero or more `[...]`
 * groups for an array- or object-valued block attribute. `add_query_arg()`
 * renders every such attribute this way, and this block's own `query[sortBy]`
 * / `query[order]` sort params add another level. PHP folds characters
 * outside this set (a dot, a space, a null byte, ...) into a query key in
 * ways that don't match how `URLSearchParams` reads them, so rather than
 * mirroring that folding, a key carrying any of them is treated as
 * untrustworthy on sight, whatever it would normalize to. A bracket-suffixed
 * `rest_route` is no exception to reject specially: PHP builds it into an
 * array value, which WordPress's REST router can't use as a route and
 * always answers with an error.
 */
const SAFE_QUERY_KEY = /^[A-Za-z0-9_-]+(\[[A-Za-z0-9_-]*\])*$/;

/**
 * Checks whether a URL resolves to this site's own listings REST route - not
 * just whether its path looks right, since a query parameter can make
 * WordPress route an otherwise-correct-looking URL somewhere else.
 *
 * @param {URL|null} parsed a URL already resolved with resolveUrl()
 */
// PHP's `max_input_vars` (default 1000) silently stops populating $_GET once
// this many input variables have been seen, so a `rest_route` occurrence
// placed after enough padding params never reaches $_GET at all - "PHP keeps
// the LAST value for a repeated key" (below) only holds below that ceiling.
// Padding a URL past it to make an EARLIER, attacker-chosen rest_route the
// one PHP actually uses - while this check still inspects every occurrence,
// including a decoy final one chosen to read as trusted - is a real,
// reachable bypass of the check below, not just this plugin's own query
// shape, so it's rejected on shape alone. 300 is well clear of both sides:
// measured against add_query_arg() for a manually-curated (non-query-mode)
// list of 200 individually-picked listings - an unusually large one - this
// plugin's own URLs carry 210 query variables, and 300 is still far short of
// the padding an attacker would need to reach the 1000-variable ceiling.
const MAX_SAFE_QUERY_PARAMS = 300;

function isNextUrlTrusted( parsed ) {
	if ( ! parsed || parsed.origin !== window.location.origin ) {
		return false;
	}

	const keys = Array.from( parsed.searchParams.keys() );

	if ( keys.length > MAX_SAFE_QUERY_PARAMS ) {
		return false;
	}

	if ( keys.some( key => ! SAFE_QUERY_KEY.test( key ) ) ) {
		return false;
	}

	const route = 'newspack-listings/v1/listings';

	// Pretty permalinks, WordPress's default REST prefix: exact match, not a
	// suffix check - an ordinary same-origin permalink (any Author/Editor can
	// title a page so its slug ends this way) could also end in this string,
	// and only the real `/wp-json/` prefix is ever rewritten to a REST
	// request. Independent of the <head> discovery link below, so a site
	// that strips that link doesn't lose load-more on the common case.
	if ( ! keys.includes( 'rest_route' ) && parsed.pathname === '/wp-json/' + route ) {
		return true;
	}

	// A non-default `rest_url_prefix`, or plain permalinks: anchor to the
	// REST root this site's own <head> advertises, since neither shape is
	// derivable from the route string alone.
	const restRoot = getTrustedRestRoot();

	if ( ! restRoot ) {
		return false;
	}

	if ( ! restRoot.search ) {
		// Pretty permalinks, non-default prefix.
		return ! keys.includes( 'rest_route' ) && parsed.pathname === restRoot.pathname + route;
	}

	// Plain permalinks: everything goes through the REST root's own path,
	// naming the route via rest_route. PHP keeps the LAST value for a
	// repeated key, which URLSearchParams.get() does not.
	if ( parsed.pathname !== restRoot.pathname ) {
		return false;
	}

	const routeValues = parsed.searchParams.getAll( 'rest_route' );

	return routeValues[ routeValues.length - 1 ] === '/' + route;
}

/**
 * Load More Button Handling
 *
 * Calls Array.prototype.forEach for IE11 compatibility.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/NodeList
 */
Array.prototype.forEach.call( document.querySelectorAll( '.newspack-listings__curated-list.has-more-button' ), buildLoadMoreHandler );

Array.prototype.forEach.call( document.querySelectorAll( '.newspack-listings__curated-list.show-sort-ui' ), buildSortHandler );

/**
 * Builds a function to handle clicks on the load more button.
 * Creates internal state via closure to ensure all state is
 * isolated to a single Block + button instance.
 *
 * @param {HTMLElement} blockWrapperEl the button that was clicked
 */
function buildLoadMoreHandler( blockWrapperEl ) {
	// Scoped to the wrapper's own direct children, since the button is one of
	// them in the server-rendered markup. Written as a manual filter rather
	// than `:scope > [data-next]` to match this file's existing idiom.
	const btnEl = Array.prototype.filter.call( blockWrapperEl.children, el => el.hasAttribute( 'data-next' ) )[ 0 ];
	if ( ! btnEl ) {
		return;
	}
	const postsContainerEl = blockWrapperEl.querySelector( '.newspack-listings__list-container' );
	const btnText = btnEl.textContent.trim();
	const loadingText = blockWrapperEl.querySelector( '.loading' ).textContent;

	// Set initial state flags.
	let isFetching = false;

	btnEl.addEventListener( 'click', () => {
		// Early return if still fetching or no more posts to render.
		if ( isFetching ) {
			return false;
		}

		isFetching = true;

		blockWrapperEl.classList.remove( 'is-error' );
		blockWrapperEl.classList.add( 'is-loading' );

		if ( loadingText ) {
			btnEl.textContent = loadingText;
		}

		// The wrapper is matched by CSS class alone, which content elsewhere on
		// the page can also carry, so the URL has to be checked before it's
		// fetched - checking which element it came from isn't enough. Resolved
		// once, the same way the browser resolves it when the request is
		// actually made, and reused below: checking one string and fetching
		// another is itself a way to drift past the check.
		const resolvedNextUrl = resolveUrl( btnEl.getAttribute( 'data-next' ) );

		if ( ! isNextUrlTrusted( resolvedNextUrl ) ) {
			isFetching = false;
			blockWrapperEl.classList.remove( 'is-loading' );
			blockWrapperEl.classList.add( 'is-error' );
			btnEl.textContent = btnText;
			return false;
		}

		fetchWithRetry( { url: resolvedNextUrl.href, onSuccess, onError }, fetchRetryCount );

		/**
		 * @param {Object} data Post data
		 * @param {string} next URL to fetch next batch of posts
		 */
		function onSuccess( data, next ) {
			// Validate received data.
			if ( ! isPostsDataValid( data ) ) {
				return onError();
			}

			if ( data.length ) {
				// Render posts' HTML from string.
				const postsHTML = data.map( item => item.html ).join( '' );
				postsContainerEl.insertAdjacentHTML( 'beforeend', postsHTML );
			}

			const hasTrustedNext = Boolean( next ) && isNextUrlTrusted( resolveUrl( next ) );

			if ( hasTrustedNext ) {
				// Save next URL as button's attribute.
				btnEl.setAttribute( 'data-next', next );
			}

			// Remove next button if we're done.
			if ( ! data.length || ! hasTrustedNext ) {
				blockWrapperEl.classList.remove( 'has-more-button' );
			}

			isFetching = false;

			blockWrapperEl.classList.remove( 'is-loading' );
			btnEl.textContent = btnText;
		}

		/**
		 * Handle fetching error
		 */
		function onError() {
			isFetching = false;

			blockWrapperEl.classList.remove( 'is-loading' );
			blockWrapperEl.classList.add( 'is-error' );
			btnEl.textContent = btnText;
		}
	} );
}

/**
 * Builds a function to handle sorting of listing items.
 * Creates internal state via closure to ensure all state is
 * isolated to a single Block + button instance.
 *
 * @param {HTMLElement} blockWrapperEl the button that was clicked
 */
function buildSortHandler( blockWrapperEl ) {
	const sortUi = blockWrapperEl.querySelector( '.newspack-listings__sort-ui' );
	const sortBy = blockWrapperEl.querySelector( '.newspack-listings__sort-select-control' );
	const sortOrder = blockWrapperEl.querySelectorAll( '[name="newspack-listings__sort-order"]' );
	const sortOrderContainer = blockWrapperEl.querySelector( '.newspack-listings__sort-order-container' );

	if ( ! sortUi || ! sortBy || ! sortOrder.length || ! sortOrderContainer ) {
		return;
	}

	// Same wrapper-is-matched-by-class-alone reasoning as buildLoadMoreHandler:
	// resolved and checked once, since every request this handler makes is
	// built from it. An untrusted root means this handler is never wired up.
	const resolvedRestUrl = resolveUrl( sortUi.getAttribute( 'data-url' ) );
	if ( ! isNextUrlTrusted( resolvedRestUrl ) ) {
		return;
	}

	// Scoped to direct children for the same reason as buildLoadMoreHandler.
	const btnEl = Array.prototype.filter.call( blockWrapperEl.children, el => el.hasAttribute( 'data-next' ) )[ 0 ];
	const triggers = Array.prototype.concat.call( Array.prototype.slice.call( sortOrder ), [ sortBy ] );

	const postsContainerEl = blockWrapperEl.querySelector( '.newspack-listings__list-container' );
	const hasMoreButton = blockWrapperEl.classList.contains( 'has-more-button' );

	// Set initial state flags and data.
	let isFetching = false;
	let _sortBy = sortUi.querySelector( '[selected]' ).value;
	let _order = sortUi.querySelector( '[checked]' ).value;

	const sortHandler = e => {
		// Early return if still fetching or no more posts to render.
		if ( isFetching ) {
			return false;
		}

		isFetching = true;

		blockWrapperEl.classList.remove( 'is-error' );
		blockWrapperEl.classList.add( 'is-loading' );

		if ( e.target.tagName.toLowerCase() === 'select' ) {
			_sortBy = e.target.value;
		} else {
			_order = e.target.value;
		}

		// Enable disabled sort order radio buttons.
		if ( 'post__in' === e.target.value ) {
			sortOrderContainer.classList.add( 'is-hidden' );
		} else {
			sortOrderContainer.classList.remove( 'is-hidden' );
		}

		// Built from the already-trusted REST URL via the URL API rather than
		// string concatenation, so sortBy/order can only ever land as opaque
		// query values, never add a new top-level parameter.
		const requestURL = new URL( resolvedRestUrl.href );
		requestURL.searchParams.set( 'query[sortBy]', _sortBy );
		requestURL.searchParams.set( 'query[order]', _order );

		if ( hasMoreButton && btnEl ) {
			blockWrapperEl.classList.add( 'has-more-button' );
			btnEl.setAttribute( 'data-next', requestURL.href );
		}

		fetchWithRetry( { url: requestURL.href, onSuccess, onError }, fetchRetryCount );

		/**
		 * @param {Object} data Post data
		 * @param {string} next URL to fetch next batch of posts
		 */
		function onSuccess( data, next ) {
			// Validate received data.
			if ( ! isPostsDataValid( data ) ) {
				return onError();
			}

			if ( data.length ) {
				// Clear all existing list items.
				postsContainerEl.textContent = '';

				// Render posts' HTML from string.
				const postsHTML = data.map( item => item.html ).join( '' );
				postsContainerEl.insertAdjacentHTML( 'beforeend', postsHTML );
			}

			if ( next && btnEl && isNextUrlTrusted( resolveUrl( next ) ) ) {
				// Save next URL as button's attribute.
				btnEl.setAttribute( 'data-next', next );
			}

			isFetching = false;
			blockWrapperEl.classList.remove( 'is-loading' );
		}

		/**
		 * Handle fetching error
		 */
		function onError() {
			isFetching = false;

			blockWrapperEl.classList.remove( 'is-loading' );
			blockWrapperEl.classList.add( 'is-error' );
		}
	};

	triggers.forEach( trigger => trigger.addEventListener( 'change', sortHandler ) );
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
			const next = xhr.getResponseHeader( 'next-url' );

			return options.onSuccess( data, next );
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
 * 	"type": "array",
 * 	"items": {
 * 		"type": "object",
 * 		"properties": {
 * 			"html": {
 * 				"type": "string"
 * 			}
 * 		},
 * 		"required": ["html"]
 * 	},
 * }
 *
 * @param {Object} data posts endpoint payload
 */
function isPostsDataValid( data ) {
	let isValid = false;

	if ( data && Array.isArray( data ) ) {
		isValid = true;

		if ( data.length && ! ( hasOwnProp( data[ 0 ], 'html' ) && typeof data[ 0 ].html === 'string' ) ) {
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
