/* globals newspackScreenReaderText */

/**
 * Internal dependencies.
 */
import { domReady } from '../utils';
import { createMenu } from './index';

domReady( function () {
	createMenu( {
		menuType: 'search-menu',
		containerSelector: '.search-menu',
		toggleSelector: '.search-menu__toggle',
		contentsSelector: '.search-menu__contents',
		overlayAnimationDuration: 250,
		onOpen: contents => {
			// Focus search input
			const searchInput = contents?.querySelector( 'input[type="search"]' );
			if ( searchInput ) {
				searchInput.focus();
			}
		},
		specialHandling: container => {
			// If Jetpack Instant Search is enabled, add a CSS class to the search toggle and bail.
			// See: https://jetpack.com/support/search/customizing-jetpack-search/#add-search-button
			if ( newspackScreenReaderText.jetpack_instant_search ) {
				const searchOpenButton = container.querySelector( '.search-menu__toggle a' );
				if ( searchOpenButton ) {
					searchOpenButton.classList.add( 'jetpack-search-filter__link' );
				}
				return false; // Don't set up the menu
			}
		},
	} ).init();
} );
