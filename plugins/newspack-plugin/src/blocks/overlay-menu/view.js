/**
 * Overlay Menu Block — Frontend Script
 *
 * Initializes a self-contained overlay menu for each block instance on the
 * page. Multiple instances work independently: each button opens only its own
 * panel. No shared mutable state exists at the module level.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { travelDuration } from 'newspack-motion';

/**
 * Internal dependencies
 */
import { lockScrollbarGutter, unlockScrollbarGutter } from '../../shared/js/scrollbar-gutter';

// Focusable element selector
const FOCUSABLE_SELECTOR =
	'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), ' +
	'textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), iframe, object, embed, ' +
	'[contenteditable="true"]';

/**
 * Returns all visible, focusable elements within a container.
 *
 * @param {HTMLElement} container
 * @return {HTMLElement[]} Visible, focusable elements within the container.
 */
const getVisibleFocusable = container =>
	Array.from( container.querySelectorAll( FOCUSABLE_SELECTOR ) ).filter( el => {
		try {
			const rect = el.getBoundingClientRect();
			const style = window.getComputedStyle( el );
			return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none' && ! el.hasAttribute( 'hidden' );
		} catch {
			return false;
		}
	} );

/**
 * Creates a self-contained overlay menu controller for one block instance.
 *
 * @param {HTMLElement} wrapper The block's root element (.wp-block-newspack-overlay-menu).
 */
const createFlyoutInstance = wrapper => {
	const overlayId = wrapper.dataset.overlayId;
	const trigger = wrapper.querySelector( '.overlay-menu__trigger' );
	const panel = wrapper.querySelector( `#newspack-overlay-panel-${ overlayId }` );

	if ( ! trigger || ! panel ) {
		return;
	}

	const closeBtn = wrapper.querySelector( '.overlay-menu__close' );

	let isOpen = false;
	let lastFocused = null;
	let focusTrapCleanup = null;
	let escCleanup = null;

	// Move the panel to document.body once so position:fixed works without
	// stacking context issues regardless of the wrapper's CSS transforms.
	document.body.appendChild( panel );

	// Capture phase stops core's openMenuOnHover so drawer submenus open only from the chevron; other pointerenter listeners on these items stop too.
	panel.addEventListener(
		'pointerenter',
		e => {
			if ( e.target.matches( '.wp-block-navigation:not(.is-style-flatten) .has-child.open-on-hover-click' ) ) {
				e.stopPropagation();
			}
		},
		true
	);

	const scrim = document.createElement( 'div' );
	scrim.className = 'overlay-menu__scrim alignfull';
	scrim.setAttribute( 'aria-hidden', 'true' );
	if ( panel.dataset.overlayColor ) {
		scrim.style.background = panel.dataset.overlayColor;
	}
	panel.after( scrim );

	// Slide animation
	// CSS owns the transition and all position values; JS only adds/removes the --open modifier class.
	const slideIn = () => {
		// Force reflow so the browser registers the panel's hidden position
		// before the class change triggers the CSS transition.
		void panel.offsetHeight;
		panel.classList.add( 'overlay-menu__panel--open' );
	};

	const slideOut = () => {
		panel.classList.remove( 'overlay-menu__panel--open' );
	};

	// Trap focus within the menu panel when it's open.
	const trapFocus = () => {
		const handleKeyDown = e => {
			if ( e.key !== 'Tab' ) {
				return;
			}
			const focusable = getVisibleFocusable( panel );
			if ( ! focusable.length ) {
				e.preventDefault();
				return;
			}
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			const active = panel.ownerDocument.activeElement;
			if ( e.shiftKey && active === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && active === last ) {
				e.preventDefault();
				first.focus();
			}
		};
		document.addEventListener( 'keydown', handleKeyDown, true );
		return () => document.removeEventListener( 'keydown', handleKeyDown, true );
	};

	// Open menu.
	const openMenu = () => {
		if ( isOpen ) {
			return;
		}
		isOpen = true;
		lastFocused = trigger.ownerDocument.activeElement;

		if ( ! panel.classList.contains( 'overlay-menu__panel--full-screen' ) ) {
			const duration = `${ travelDuration( panel.offsetWidth ) }ms`;
			panel.style.setProperty( '--overlay-menu-slide-duration', duration );
			scrim.style.setProperty( '--overlay-menu-slide-duration', duration );
		}

		slideIn();

		// ARIA state.
		trigger.setAttribute( 'aria-expanded', 'true' );
		panel.setAttribute( 'aria-hidden', 'false' );
		panel.removeAttribute( 'inert' );
		lockScrollbarGutter();
		document.body.classList.add( `menu-open--overlay-menu-${ overlayId }` );

		scrim.classList.add( 'overlay-menu__scrim--open' );

		// Focus trap.
		focusTrapCleanup = trapFocus();

		// ESC key.
		const onEsc = e => {
			if ( e.key === 'Escape' ) {
				closeMenu();
			}
		};
		document.addEventListener( 'keydown', onEsc );
		escCleanup = () => document.removeEventListener( 'keydown', onEsc );

		// Move focus into the panel.
		setTimeout( () => {
			const firstFocusable = getVisibleFocusable( panel )[ 0 ] || closeBtn;
			if ( firstFocusable && document.contains( firstFocusable ) ) {
				firstFocusable.focus();
			}
		}, 50 );
	};

	// Close menu.
	const closeMenu = () => {
		if ( ! isOpen ) {
			return;
		}
		isOpen = false;

		// Release focus trap and ESC listener.
		if ( focusTrapCleanup ) {
			focusTrapCleanup();
			focusTrapCleanup = null;
		}
		if ( escCleanup ) {
			escCleanup();
			escCleanup = null;
		}

		// Restore ARIA state.
		trigger.setAttribute( 'aria-expanded', 'false' );
		panel.setAttribute( 'aria-hidden', 'true' );
		panel.setAttribute( 'inert', '' );
		document.body.classList.remove( `menu-open--overlay-menu-${ overlayId }` );
		unlockScrollbarGutter();

		// Return focus immediately so screen readers don't lose context.
		if ( lastFocused && document.contains( lastFocused ) ) {
			lastFocused.focus();
		}

		scrim.classList.remove( 'overlay-menu__scrim--open' );
		slideOut();
	};

	// Event listeners.
	trigger.addEventListener( 'click', () => ( isOpen ? closeMenu() : openMenu() ) );
	scrim.addEventListener( 'click', closeMenu );

	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', e => {
			e.preventDefault();
			closeMenu();
		} );
	}
};

// Initialization.
domReady( () => {
	document.querySelectorAll( '.wp-block-newspack-overlay-menu[data-overlay-id]' ).forEach( createFlyoutInstance );
} );
