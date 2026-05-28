/**
 * Dropdown menu functionality.
 */

import { domReady } from '../../utils';

domReady( function () {
	const dropdowns = [ ...document.querySelectorAll( '.newspack-ui__dropdown' ) ];
	dropdowns.forEach( dropdown => {
		const toggle = dropdown.querySelector( '.newspack-ui__dropdown__toggle' );
		const content = dropdown.querySelector( '.newspack-ui__dropdown__content' );

		if ( toggle && content ) {
			toggle.addEventListener( 'click', e => {
				dropdown.classList.toggle( 'active' );

				const rect = content.getBoundingClientRect();

				// If content would overflow the right edge of viewport.
				if ( rect.right + rect.width > window.innerWidth ) {
					content.style.left = 'auto';
					content.style.right = '0';
				} else {
					// Reset position if no overflow
					content.style.removeProperty( 'left' );
					content.style.removeProperty( 'right' );
					// Remove the entire style attribute if it's empty
					if ( content.style.length === 0 ) {
						content.removeAttribute( 'style' );
					}
				}
				// If content would overflow the bottom edge of the viewport.
				if ( rect.bottom + rect.height > window.innerHeight ) {
					content.style.top = 'auto';
					content.style.bottom = `${ e.currentTarget.clientHeight + 8 }px`;
				} else {
					// Reset position if no overflow
					content.style.removeProperty( 'top' );
					content.style.removeProperty( 'bottom' );
					// Remove the entire style attribute if it's empty
					if ( content.style.length === 0 ) {
						content.removeAttribute( 'style' );
					}
				}
			} );
		}
		document.addEventListener( 'keydown', e => {
			if ( e.key === 'Escape' ) {
				dropdown.classList.remove( 'active' );
			}
		} );
		document.addEventListener( 'click', e => {
			if ( ! dropdown.contains( e.target ) && dropdown.classList.contains( 'active' ) ) {
				dropdown.classList.remove( 'active' );
			}
		} );
	} );
} );
