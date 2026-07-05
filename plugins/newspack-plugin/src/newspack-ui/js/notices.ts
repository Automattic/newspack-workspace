import { domReady } from './utils';

domReady( function () {
	const notices = [ ...document.querySelectorAll( '.newspack-ui__snackbar__item' ) ];
	notices.forEach( notice => {
		if ( notice.dataset.activeOnLoad === 'true' ) {
			openNotice( notice );
		}
		const interactiveElements = notice.querySelectorAll( 'a, button' );
		[ ...interactiveElements ].forEach( element => {
			element.addEventListener( 'click', () => {
				closeNotice( notice );
			} );
		} );
	} );
} );

/**
 * Open a notice.
 *
 * @param {Element} element - The notice element.
 * @param {boolean} remove  - Whether to remove the notice element on close.
 */
function openNotice( element, remove = true ) {
	element.classList.add( 'active' );
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
		setTimeout( () => {
			element.remove();
		}, 250 );
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
 * @param {string} type    Notice type.
 */
function createNotice( message, type = 'success' ) {
	let snackbar = document.querySelector( '.newspack-ui__snackbar--top-right' );
	if ( ! snackbar ) {
		let wrapper = document.querySelector( '.newspack-ui' );
		if ( ! wrapper ) {
			wrapper = document.createElement( 'div' );
			wrapper.classList.add( 'newspack-ui' );
			document.body.appendChild( wrapper );
		}

		snackbar = document.createElement( 'div' );
		snackbar.classList.add( 'newspack-ui__snackbar', 'newspack-ui__snackbar--top-right' );

		wrapper.appendChild( snackbar );
	}

	const item = document.createElement( 'div' );
	item.classList.add( 'newspack-ui__snackbar__item', `newspack-ui__snackbar__item--${ type }` );
	item.setAttribute( 'data-autohide', 'true' );

	const content = document.createElement( 'div' );
	content.classList.add( 'newspack-ui__snackbar__content' );
	content.textContent = message;

	item.appendChild( content );
	snackbar.appendChild( item );
	openNotice( item, true );
}

// Expose notice functions to the global API.
export default { openNotice, closeNotice, createNotice };
