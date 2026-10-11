/* eslint-disable */

// This is a hand-maintained duplicate of curated-list.js's front-end logic
// for the amp-script sandbox, which can't load that file's webpack bundle.
// Its trust check (isNextUrlTrusted below) differs from the main file's in
// one way, forced by the sandbox: amp-script's worker-dom gives this script
// a virtual `document` scoped to the <amp-script> container alone, with no
// visibility into the real page's <head> - so there is no `<link
// rel="https://api.w.org/">` to anchor a plain-permalink check to. Rather
// than guess at that link's content, this only ever trusts the
// pretty-permalink shape (exact path match against WordPress's REST prefix,
// which is "/wp-json/" unless a site filters `rest_url_prefix`); a
// plain-permalink site, or one with a non-default REST prefix, fails closed
// here, same as the main file already does when the discovery link is
// missing.
const MAX_SAFE_QUERY_PARAMS = 50;
const SAFE_QUERY_KEY = /^[A-Za-z0-9_-]+(\[[A-Za-z0-9_-]*\])*$/;
const ROUTE = 'newspack-listings/v1/listings';

function isNextUrlTrusted( url ) {
	let parsed;
	try {
		parsed = new URL( url );
	} catch ( e ) {
		return false;
	}

	if ( parsed.origin !== window.location.origin ) {
		return false;
	}

	const keys = Array.from( parsed.searchParams.keys() );

	if ( keys.length > MAX_SAFE_QUERY_PARAMS || keys.some( key => ! SAFE_QUERY_KEY.test( key ) ) ) {
		return false;
	}

	return ! keys.includes( 'rest_route' ) && parsed.pathname === '/wp-json/' + ROUTE;
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
