import { domReady, getTransitionTime } from './utils';
import { getNoticeIcon } from './icons';
import { announce, getLiveRegion, getWrapper } from './announce';

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
		scheduleAutohide( element, remove );
	}
}

/**
 * Hide a snackbar after 8 seconds of being visible, pausing while the reader
 * hovers it or has focus inside it, so an action such as Undo stays reachable.
 *
 * @param {HTMLElement} element Snackbar item.
 * @param {boolean}     remove  Whether to remove the item once hidden.
 */
function scheduleAutohide( element, remove ) {
	let remaining = 8000;
	let startedAt = 0;
	let timer = null;
	const run = () => {
		if ( timer || element.matches( ':hover' ) || element.contains( element.ownerDocument.activeElement ) ) {
			return;
		}
		startedAt = Date.now();
		timer = setTimeout( () => {
			timer = null;
			closeNotice( element, remove );
		}, remaining );
	};
	const pause = () => {
		if ( ! timer ) {
			return;
		}
		clearTimeout( timer );
		timer = null;
		remaining = Math.max( 0, remaining - ( Date.now() - startedAt ) );
	};
	element.addEventListener( 'mouseenter', pause );
	element.addEventListener( 'focusin', pause );
	element.addEventListener( 'mouseleave', run );
	element.addEventListener( 'focusout', event => {
		if ( ! element.contains( event.relatedTarget ) ) {
			run();
		}
	} );
	run();
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
		setTimeout( () => {
			element.remove();
		}, getTransitionTime( element ) );
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
 * @param {string} type    Severity; drives the ARIA announcement and the type icon ('info', 'error' and 'warning').
 */
function createNotice( message, type = 'success' ) {
	const wrapper = getWrapper();
	let snackbar = wrapper.querySelector( '.newspack-ui__snackbar' );
	if ( ! snackbar ) {
		snackbar = document.createElement( 'div' );
		snackbar.classList.add( 'newspack-ui__snackbar' );
		wrapper.appendChild( snackbar );
	}

	const item = document.createElement( 'div' );
	item.classList.add( 'newspack-ui__snackbar__item' );
	item.dataset.type = type;
	item.setAttribute( 'data-autohide', 'true' );

	const typeIcon = [ 'info', 'error', 'warning' ].includes( type ) ? getNoticeIcon( type ) : null;
	if ( typeIcon ) {
		const icon = document.createElement( 'span' );
		icon.classList.add( 'newspack-ui__snackbar__icon' );
		icon.appendChild( typeIcon );
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
