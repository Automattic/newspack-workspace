import { domReady } from './utils';

domReady( function () {
	// Create the live regions up front so they are registered before any message is injected.
	getLiveRegion( false );
	getLiveRegion( true );

	const notices = [ ...document.querySelectorAll( '.newspack-ui__snackbar__item' ) ];
	notices.forEach( notice => {
		const interactiveElements = notice.querySelectorAll( 'a, button' );
		[ ...interactiveElements ].forEach( element => {
			element.addEventListener( 'click', () => {
				closeNotice( notice );
			} );
		} );
	} );

	// Activate after two frames so the live regions are parsed before their text is injected;
	// a live region will not announce content that changed before it existed.
	requestAnimationFrame( () =>
		requestAnimationFrame( () => {
			notices.forEach( notice => {
				if ( notice.dataset.activeOnLoad === 'true' ) {
					openNotice( notice );
				}
			} );
		} )
	);
} );

/**
 * Get (or create) the shared `.newspack-ui` wrapper that hosts snackbars and the live regions.
 *
 * @return {Element} The wrapper element.
 */
function getWrapper() {
	let wrapper = document.querySelector( '.newspack-ui' );
	if ( ! wrapper ) {
		wrapper = document.createElement( 'div' );
		wrapper.classList.add( 'newspack-ui' );
		document.body.appendChild( wrapper );
	}
	return wrapper;
}

/**
 * Get (or create) a visually-hidden ARIA live region.
 *
 * @param {boolean} assertive - Whether the region announces assertively (errors) instead of politely.
 * @return {Element} The live region element.
 */
function getLiveRegion( assertive ) {
	const id = assertive ? 'newspack-ui__sr-live-assertive' : 'newspack-ui__sr-live-polite';
	let region = document.getElementById( id );
	if ( ! region ) {
		region = document.createElement( 'div' );
		region.id = id;
		region.classList.add( 'screen-reader-text' );
		region.setAttribute( 'role', assertive ? 'alert' : 'status' );
		region.setAttribute( 'aria-live', assertive ? 'assertive' : 'polite' );
		getWrapper().appendChild( region );
	}
	return region;
}

/**
 * Announce a message to screen readers via the appropriate live region.
 *
 * @param {string}  message   - The message to announce.
 * @param {boolean} assertive - Whether to announce assertively (errors) instead of politely.
 */
function announce( message, assertive ) {
	if ( ! message ) {
		return;
	}
	const region = getLiveRegion( assertive );
	const line = document.createElement( 'div' );
	line.textContent = message;
	region.appendChild( line );
	// Remove once announced so repeated messages re-announce and the region stays tidy.
	setTimeout( () => line.remove(), 1000 );
}

/**
 * Open a notice.
 *
 * @param {Element} element - The notice element.
 * @param {boolean} remove  - Whether to remove the notice element on close.
 */
function openNotice( element, remove = true ) {
	element.classList.add( 'active' );
	const content = element.querySelector( '.newspack-ui__snackbar__content' );
	announce( content ? content.textContent.trim() : '', element.dataset.type === 'error' );
	if ( element.dataset.autohide !== 'false' ) {
		setTimeout( () => {
			closeNotice( element, remove );
		}, 8000 );
	}
}

/**
 * Close a notice.
 *
 * @param {Element} element - The notice element.
 * @param {boolean} remove  - Whether to remove the notice element on dismiss.
 */
function closeNotice( element, remove = true ) {
	element.classList.remove( 'active' );
	if ( remove ) {
		// Wait for the slide-out and slot collapse before removing from the DOM. This 500ms must
		// stay >= the exit transition in _notices.scss (transform 250ms + height 200ms delayed 250ms);
		// shortening it there without updating this clips the animation.
		setTimeout( () => {
			element.remove();
		}, 500 );
	}
	if ( element.dataset.noticeId ) {
		wp.ajax.send( 'newspack_ui_notice_dismissed', {
			data: {
				id: element.dataset.noticeId,
				nonce: element.dataset.nonce,
			},
		} );
	}
}

/**
 * Dynamically create and show a snackbar notice.
 *
 * @param {string} message Message text to show.
 * @param {string} type    Severity; drives the ARIA announcement and the type icon ('error' and 'warning').
 */
function createNotice( message, type = 'success' ) {
	let snackbar = document.querySelector( '.newspack-ui__snackbar' );
	if ( ! snackbar ) {
		snackbar = document.createElement( 'div' );
		snackbar.classList.add( 'newspack-ui__snackbar' );
		getWrapper().appendChild( snackbar );
	}

	const item = document.createElement( 'div' );
	item.classList.add( 'newspack-ui__snackbar__item' );
	item.dataset.type = type;
	item.setAttribute( 'data-autohide', 'true' );

	const typeIcon = window.newspackUIData?.icons?.[ type ];
	if ( typeIcon ) {
		const icon = document.createElement( 'span' );
		icon.classList.add( 'newspack-ui__snackbar__icon' );
		icon.innerHTML = typeIcon;
		item.appendChild( icon );
	}

	const content = document.createElement( 'div' );
	content.classList.add( 'newspack-ui__snackbar__content' );
	content.textContent = message;

	item.appendChild( content );
	snackbar.appendChild( item );
	// Force a reflow so the off-screen start state is painted before `.active`
	// is added, otherwise the enter transition is skipped.
	void item.offsetWidth;
	openNotice( item, true );
}

// Expose notice functions to the global API.
export default { openNotice, closeNotice, createNotice };
