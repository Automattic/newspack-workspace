/**
 * Global functions for My Account pages.
 */

/**
 * Internal dependencies.
 */
import { domReady } from '../../utils';

domReady( () => {
	const interactionElements = [ '.newspack-ui--block-on-interaction' ];
	const blockUIonInteraction = [ ...document.querySelectorAll( interactionElements.join( ',' ) ) ];
	blockUIonInteraction.forEach( element => {
		const parent = element.closest( 'form, div' );
		if (
			( 'button' === element.tagName.toLowerCase() || 'input' === element.tagName.toLowerCase() ) &&
			'form' === parent.tagName.toLowerCase()
		) {
			parent.addEventListener( 'submit', e => {
				e.target.classList.add( 'newspack-ui--loading' );
			} );
		} else {
			element.addEventListener( 'click', e => {
				e.target.closest( 'form, div' ).classList.add( 'newspack-ui--loading' );
			} );
		}
	} );
} );
