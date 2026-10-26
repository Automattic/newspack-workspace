/**
 * Dropdown menu functionality.
 *
 * Uses event delegation so dropdowns added to the DOM after init (e.g. tab panels
 * that the tabs controller restores on switch) still respond.
 */

import { domReady } from './utils';

const positionContent = content => {
	content.style.transition = 'none';
	content.style.removeProperty( 'left' );
	content.style.removeProperty( 'right' );
	content.style.removeProperty( 'top' );
	content.style.removeProperty( 'bottom' );
	content.classList.remove( 'newspack-ui__dropdown__content--above' );

	const rect = content.getBoundingClientRect();

	// If content would overflow the right edge of the viewport.
	if ( rect.right > window.innerWidth ) {
		content.style.left = 'auto';
		content.style.right = '0';
	}
	// If content would overflow the bottom edge of the viewport.
	if ( rect.bottom > window.innerHeight ) {
		content.style.top = 'auto';
		content.style.bottom = 'calc(100% + var(--newspack-ui-spacer-half))';
		content.classList.add( 'newspack-ui__dropdown__content--above' );
	}
	content.getBoundingClientRect();
	content.style.removeProperty( 'transition' );
	if ( content.style.length === 0 ) {
		content.removeAttribute( 'style' );
	}
};

/**
 * Mark every dropdown toggle as collapsed, so its state is announced before the first click.
 *
 * @param {Document|Element} root Where to look for toggles.
 */
export const initDropdowns = ( root = document ) => {
	root.querySelectorAll( '.newspack-ui__dropdown__toggle:not([aria-expanded])' ).forEach( toggle => {
		toggle.setAttribute( 'aria-expanded', 'false' );
	} );
};

domReady( () => initDropdowns() );

const setOpen = ( dropdown, open ) => {
	dropdown.classList.toggle( 'active', open );
	const toggle = dropdown.querySelector( '.newspack-ui__dropdown__toggle' );
	if ( toggle ) {
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	}
};

document.addEventListener( 'click', e => {
	const toggle = e.target.closest( '.newspack-ui__dropdown__toggle' );
	if ( toggle ) {
		const dropdown = toggle.closest( '.newspack-ui__dropdown' );
		if ( ! dropdown ) {
			return;
		}
		const content = dropdown.querySelector( '.newspack-ui__dropdown__content' );
		if ( ! content ) {
			return;
		}
		// Close any other open dropdowns before activating this one.
		document.querySelectorAll( '.newspack-ui__dropdown.active' ).forEach( other => {
			if ( other !== dropdown ) {
				setOpen( other, false );
			}
		} );
		const open = ! dropdown.classList.contains( 'active' );
		// Reopening mid-close keeps the current position, so the close can reverse instead of restarting.
		if ( open && 'hidden' === window.getComputedStyle( content ).visibility ) {
			positionContent( content );
		}
		setOpen( dropdown, open );
		return;
	}

	// Outside click — close any open dropdowns.
	document.querySelectorAll( '.newspack-ui__dropdown.active' ).forEach( dropdown => {
		if ( ! dropdown.contains( e.target ) ) {
			setOpen( dropdown, false );
		}
	} );
} );

document.addEventListener( 'keydown', e => {
	if ( e.key === 'Escape' ) {
		const open = [ ...document.querySelectorAll( '.newspack-ui__dropdown.active' ) ];
		if ( open.length ) {
			// An open dropdown takes the Escape, so a surrounding modal stays open.
			e.preventDefault();
		}
		open.forEach( dropdown => {
			const hadFocus = dropdown.contains( dropdown.ownerDocument.activeElement );
			setOpen( dropdown, false );
			if ( hadFocus ) {
				dropdown.querySelector( '.newspack-ui__dropdown__toggle' )?.focus();
			}
		} );
	}
} );
