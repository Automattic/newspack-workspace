/**
 * JS for custom My Account nav menu UI.
 */

/**
 * Internal dependencies.
 */
import { domReady } from '../../utils';

domReady( () => {
	// Open and close navigation menu.
	const openNavigationButton = document.querySelector( '.newspack-my-account__navigation-topbar__button .newspack-ui__button' );
	let setButtonState;

	if ( openNavigationButton ) {
		const openLabel = openNavigationButton.dataset.labelOpen || openNavigationButton.getAttribute( 'aria-label' );
		const closeLabel = openNavigationButton.dataset.labelClose || openLabel;
		setButtonState = isOpen => {
			openNavigationButton.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			openNavigationButton.setAttribute( 'aria-label', isOpen ? closeLabel : openLabel );
		};

		setButtonState( document.body.classList.contains( 'navigation-open' ) );

		openNavigationButton.addEventListener( 'click', () => {
			const isOpen = document.body.classList.toggle( 'navigation-open' );
			setButtonState( isOpen );
		} );
	}

	// Close navigation on Escape key press.
	document.addEventListener( 'keydown', event => {
		if ( event.key === 'Escape' && document.body.classList.contains( 'navigation-open' ) ) {
			document.body.classList.remove( 'navigation-open' );
			if ( setButtonState ) {
				setButtonState( false );
			}
		}
	} );
} );
