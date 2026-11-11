/* eslint-disable */

// This is a hand-maintained duplicate of curated-list.js's front-end logic
// for the amp-script sandbox, which can't load that file's webpack bundle.
// Its trust check (isNextUrlTrusted below) can't use curated-list.js's
// `getTrustedRestRoot()` (a `<link rel="https://api.w.org/">` read from the
// page's own <head>): amp-script's worker-dom gives this script a virtual
// `document` scoped to the <amp-script> container alone, with no visibility
// into <head>. `window.location` is no substitute either - on a page served
// from an AMP cache, it reflects the cache's origin, not this site's, while
// `data-next`/`data-url` still correctly target this site. view.php instead
// renders this site's real REST root as a server-rendered marker element
// inside the block's own container (`.newspack-listings__rest-root`),
// visible to the worker since it's part of the same subtree - trustworthy
// for the same reason the real data-next attribute already is - and this
// file anchors to that marker instead of to window.location or a hardcoded
// path, so a subdirectory install or a cached page doesn't break it.
const SAFE_QUERY_KEY = /^[A-Za-z0-9_-]+(\[[A-Za-z0-9_-]*\])*$/;
const ROUTE = 'newspack-listings/v1/listings';

function getTrustedRestRoot() {
	const marker = document.querySelector( '.newspack-listings__rest-root' );
	if ( ! marker ) return null;
	try {
		return new URL( marker.getAttribute( 'data-rest-root' ) );
	} catch ( e ) {
		return null;
	}
}

function isNextUrlTrusted( url ) {
	let parsed;
	try {
		parsed = new URL( url );
	} catch ( e ) {
		return false;
	}

	const restRoot = getTrustedRestRoot();

	if ( ! restRoot || parsed.origin !== restRoot.origin ) {
		return false;
	}

	const keys = Array.from( parsed.searchParams.keys() );

	if ( keys.some( key => ! SAFE_QUERY_KEY.test( key ) ) ) {
		return false;
	}

	if ( ! restRoot.searchParams.has( 'rest_route' ) ) {
		// Pretty permalinks: anchor to the real REST root's own path, not a
		// hardcoded one - a subdirectory install's prefix isn't "/wp-json/".
		return ! keys.includes( 'rest_route' ) && parsed.pathname === restRoot.pathname + ROUTE;
	}

	// Plain permalinks: exactly one rest_route, for the reason given in
	// curated-list.js.
	if ( parsed.pathname !== restRoot.pathname ) {
		return false;
	}

	const routeValues = parsed.searchParams.getAll( 'rest_route' );

	return routeValues.length === 1 && routeValues[ 0 ] === '/' + ROUTE;
}

let isFetching = false;
buildLoadMoreHandler( document.querySelector( '.newspack-listings__curated-list' ) );
buildSortHandler( document.querySelector( '.newspack-listings__curated-list' ) );
function buildLoadMoreHandler( blockWrapperEl ) {
	if ( ! blockWrapperEl ) return;
	// Scoped to direct children, same reasoning as curated-list.js.
	const btnEl = Array.prototype.filter.call( blockWrapperEl.children, el => el.hasAttribute( 'data-next' ) )[ 0 ];
	if ( ! btnEl ) return;
	const postsContainerEl = blockWrapperEl.querySelector( '.newspack-listings__list-container' );
	const btnText = btnEl.textContent.trim();
	const loadingText = blockWrapperEl.querySelector( '.loading' ).textContent;
	btnEl.addEventListener( 'click', function() {
		if ( isFetching ) return false;
		isFetching = true;
		blockWrapperEl.classList.remove( 'is-error' );
		blockWrapperEl.classList.add( 'is-loading' );
		if ( loadingText ) btnEl.textContent = loadingText;
		const nextUrl = btnEl.getAttribute( 'data-next' );
		if ( ! isNextUrlTrusted( nextUrl ) ) {
			isFetching = false;
			blockWrapperEl.classList.remove( 'is-loading' );
			blockWrapperEl.classList.add( 'is-error' );
			btnEl.textContent = btnText;
			return false;
		}
		const requestURL = new URL( nextUrl );
		apiFetchWithRetry( { url: requestURL.toString(), onSuccess, onError }, 3 );
		function onSuccess( data, next ) {
			if ( isPostsDataValid( data ) ) {
				data.forEach( item => {
					const tempDIV = document.createElement( 'div' );
					tempDIV.innerHTML = item.html.trim();
					postsContainerEl.appendChild( tempDIV.childNodes[ 0 ] );
				} );
				const hasTrustedNext = Boolean( next ) && isNextUrlTrusted( next );
				if ( hasTrustedNext ) btnEl.setAttribute( 'data-next', next );
				if ( ! data.length || ! hasTrustedNext ) {
					blockWrapperEl.classList.remove( 'has-more-button' );
				}
				isFetching = false;
				blockWrapperEl.classList.remove( 'is-loading' );
				btnEl.textContent = btnText;
			}
		}
		function onError() {
			isFetching = false;
			blockWrapperEl.classList.remove( 'is-loading' );
			blockWrapperEl.classList.add( 'is-error' );
			btnEl.textContent = btnText;
		}
	} );
}
function buildSortHandler( blockWrapperEl ) {
	const sortUi = blockWrapperEl.querySelector( '.newspack-listings__sort-ui' );
	const sortBy = blockWrapperEl.querySelector( '.newspack-listings__sort-select-control' );
	const sortOrder = blockWrapperEl.querySelectorAll( 'input' );
	const sortOrderContainer = blockWrapperEl.querySelector(
		'.newspack-listings__sort-order-container'
	);
	if ( ! sortBy || ! sortOrder.length || ! sortUi || ! sortOrderContainer ) return;
	const restURL = sortUi.getAttribute( 'data-url' );
	if ( ! isNextUrlTrusted( restURL ) ) return;
	// Scoped to direct children, same reasoning as curated-list.js.
	const btnEl = Array.prototype.filter.call( blockWrapperEl.children, el => el.hasAttribute( 'data-next' ) )[ 0 ];
	const triggers = Array.prototype.concat.call( Array.prototype.slice.call( sortOrder ), [
		sortBy,
	] );
	const postsContainerEl = blockWrapperEl.querySelector( '.newspack-listings__list-container' );
	const hasMoreButton = blockWrapperEl.classList.contains( 'has-more-button' );
	let isFetching = false;
	let _sortBy = sortUi.querySelector( '[selected]' ).value;
	let _order = sortUi.querySelector( '[checked]' ).value;
	const sortHandler = e => {
		if ( isFetching ) return false;
		isFetching = true;
		blockWrapperEl.classList.remove( 'is-error' );
		blockWrapperEl.classList.add( 'is-loading' );
		if ( e.target.tagName.toLowerCase() === 'select' ) {
			_sortBy = e.target.value;
		} else {
			_order = e.target.value;
		}
		if ( 'post__in' === e.target.value ) {
			sortOrderContainer.classList.add( 'is-hidden' );
		} else {
			sortOrderContainer.classList.remove( 'is-hidden' );
		}
		// Built from the already-trusted restURL via the URL API, same reasoning
		// as curated-list.js: sortBy/order can only ever land as opaque query
		// values this way, never add a new top-level parameter.
		const requestURL = new URL( restURL );
		requestURL.searchParams.set( 'query[sortBy]', _sortBy );
		requestURL.searchParams.set( 'query[order]', _order );
		if ( hasMoreButton && btnEl ) {
			blockWrapperEl.classList.add( 'has-more-button' );
			btnEl.setAttribute( 'data-next', requestURL.href );
		}
		apiFetchWithRetry( { url: requestURL.href, onSuccess, onError }, 3 );
		function onSuccess( data, next ) {
			if ( ! isPostsDataValid( data ) ) return onError();
			postsContainerEl.textContent = '';
			data.forEach( item => {
				const tempDIV = document.createElement( 'div' );
				tempDIV.innerHTML = item.html.trim();
				postsContainerEl.appendChild( tempDIV.childNodes[ 0 ] );
			} );
			if ( next && btnEl && isNextUrlTrusted( next ) ) btnEl.setAttribute( 'data-next', next );
			isFetching = false;
			blockWrapperEl.classList.remove( 'is-loading' );
		}
		function onError() {
			isFetching = false;
			blockWrapperEl.classList.remove( 'is-loading' );
			blockWrapperEl.classList.add( 'is-error' );
		}
	};
	triggers.forEach( trigger => trigger.addEventListener( 'change', sortHandler ) );
}
function apiFetchWithRetry( options, n ) {
	const xhr = new XMLHttpRequest();
	xhr.onreadystatechange = () => {
		if ( xhr.readyState !== 4 || n === 0 ) return;
		if ( xhr.status >= 200 && xhr.status < 300 ) {
			const data = JSON.parse( xhr.responseText );
			const next = xhr.getResponseHeader( 'next-url' );
			options.onSuccess( data, next );
			return;
		}
		options.onError();
		apiFetchWithRetry( options, n - 1 );
	};
	xhr.open( 'GET', options.url );
	xhr.send();
}
function isPostsDataValid( data ) {
	if (
		data &&
		Array.isArray( data ) &&
		data.length &&
		hasOwnProp( data[ 0 ], 'html' ) &&
		typeof data[ 0 ].html === 'string'
	) {
		return true;
	}

	return false;
}
function hasOwnProp( obj, prop ) {
	return Object.prototype.hasOwnProperty.call( obj, prop );
}
