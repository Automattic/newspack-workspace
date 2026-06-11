/**
 * Dropdown menu functionality.
 *
 * Uses event delegation so dropdowns added to the DOM after init (e.g. tab panels
 * that the tabs controller restores on switch) still respond.
 */

const positionContent = ( content, toggle ) => {
	const rect = content.getBoundingClientRect();

	// If content would overflow the right edge of the viewport.
	if ( rect.right > window.innerWidth ) {
		content.style.left = 'auto';
		content.style.right = '0';
	} else {
		content.style.removeProperty( 'left' );
		content.style.removeProperty( 'right' );
		if ( content.style.length === 0 ) {
			content.removeAttribute( 'style' );
		}
	}
	// If content would overflow the bottom edge of the viewport.
	if ( rect.bottom > window.innerHeight ) {
		content.style.top = 'auto';
		content.style.bottom = `${ toggle.clientHeight + 8 }px`;
	} else {
		content.style.removeProperty( 'top' );
		content.style.removeProperty( 'bottom' );
		if ( content.style.length === 0 ) {
			content.removeAttribute( 'style' );
		}
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
				other.classList.remove( 'active' );
			}
		} );
		dropdown.classList.toggle( 'active' );
		if ( dropdown.classList.contains( 'active' ) ) {
			positionContent( content, toggle );
		}
		return;
	}

	// Outside click — close any open dropdowns.
	document.querySelectorAll( '.newspack-ui__dropdown.active' ).forEach( dropdown => {
		if ( ! dropdown.contains( e.target ) ) {
			dropdown.classList.remove( 'active' );
		}
	} );
} );

document.addEventListener( 'keydown', e => {
	if ( e.key === 'Escape' ) {
		[ ...document.querySelectorAll( '.newspack-ui__dropdown.active' ) ].forEach( dropdown => {
			dropdown.classList.remove( 'active' );
		} );
	}
} );
