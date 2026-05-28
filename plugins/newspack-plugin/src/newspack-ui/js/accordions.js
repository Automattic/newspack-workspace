/**
 * Accordion functionality.
 */

import { domReady } from '../../utils';

domReady( function () {
	const accordions = [ ...document.querySelectorAll( '.newspack-ui__accordion' ) ];
	accordions.forEach( accordion => {
		const toggles = [ ...accordion.querySelectorAll( 'li > input[type="radio"]' ) ];
		const toggleOpen = () => {
			toggles.forEach( t => {
				const parent = t.closest( 'li' );
				if ( t.checked ) {
					parent.classList.add( 'active' );
				} else {
					parent.classList.remove( 'active' );
				}
			} );
		};
		toggles.forEach( toggle => {
			toggle.addEventListener( 'change', toggleOpen );
		} );

		// If the accordion has an --open class, activate the first toggle with a click event.
		if ( accordion.classList.contains( 'newspack-ui__accordion--open' ) ) {
			toggles[ 0 ].click();
		}
	} );
} );
