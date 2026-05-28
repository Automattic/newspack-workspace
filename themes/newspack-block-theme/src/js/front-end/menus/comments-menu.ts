/**
 * Internal dependencies.
 */
import { domReady } from '../utils'; // Global utils.
import { MENU_OPEN_CLASS_NAME } from './consts'; // Menu constants.

const openClassName = MENU_OPEN_CLASS_NAME + 'comments-menu';

domReady( function () {
	const body = document.body,
		pageContain = document.querySelector( '.comments-menu' ),
		commentsToggle = document.querySelectorAll( '.comments-menu__toggle' ),
		commentsContents = document.querySelector( '.comments-menu__contents' );

	if ( ! pageContain || ! commentsToggle.length || ! commentsContents ) {
		return;
	}

	const commentsOpenButton: HTMLAnchorElement | null = pageContain.querySelector( '.comments-menu__toggle a' );
	const commentsCloseButton: HTMLAnchorElement | null = commentsContents.querySelector( '.comments-menu__toggle a' );

	const menuToggle = ( event: Event ) => {
		event.preventDefault();
		if ( body.classList.contains( openClassName ) ) {
			closeMenu();
		} else {
			openMenu();
		}
	};

	const openMenu = () => {
		body.classList.add( openClassName );
		commentsContents.classList.add( openClassName );
		if ( commentsCloseButton ) {
			commentsCloseButton.focus();
		}
	};

	const closeMenu = () => {
		body.classList.remove( openClassName );
		commentsContents.classList.remove( openClassName );
		if ( commentsOpenButton ) {
			commentsOpenButton.focus();
		}
	};

	// Find each comments toggle and attaches an event listener.
	for ( let i = 0; i < commentsToggle.length; i++ ) {
		commentsToggle[ i ].addEventListener( 'click', menuToggle, false );
	}
} );
