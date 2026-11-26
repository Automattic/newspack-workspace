/**
 * Overlay Search Block — Frontend Script
 *
 * Initializes a self-contained search overlay for each block instance on the
 * page. Multiple instances work independently: each trigger opens only its own
 * panel. When Jetpack Instant Search rendered the trigger as
 * `.jetpack-search-filter__link`, no panel exists and this controller bails.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import { lockScrollbarGutter, unlockScrollbarGutter } from '../../shared/js/scrollbar-gutter';
import { getContrastColorFromRGB } from '../../../packages/components/src/utils/contrast-color';

const FOCUSABLE_SELECTOR =
	'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), ' +
	'textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), iframe, object, embed, ' +
	'[contenteditable="true"]';

/**
 * Layered token chains for the close-button color. The block theme custom var
 * is preferred when present; Newspack UI is the second tier; an explicit hex
 * is the last-resort literal so the icon stays legible even with no theme.
 */
const CONTRAST_BLACK = 'var(--wp--custom--color--neutral-100, var(--newspack-ui-color-neutral-100, #000000))';
const CONTRAST_WHITE = 'var(--wp--custom--color--neutral-0, var(--newspack-ui-color-neutral-0, #ffffff))';

/**
 * Pick whichever of `CONTRAST_BLACK`/`CONTRAST_WHITE` reads better by APCA (ties
 * to black) against the element's resolved `background-color`. Reads via
 * `getComputedStyle()` so theme tokens, hex values, and rgba() all work the
 * same way. Falls back to white when the background can't be parsed
 * (transparent, none, or non-rgb keywords).
 *
 * @param {HTMLElement} el Element whose background drives the choice.
 * @return {string} CSS color value (a `var(...)` chain).
 */
const pickContrastColor = el => {
	const bg = window.getComputedStyle( el ).backgroundColor;
	const match = bg.match( /rgba?\(([^)]+)\)/ );
	if ( ! match ) {
		return CONTRAST_WHITE;
	}
	const [ r, g, b ] = match[ 1 ].split( ',' ).map( s => parseFloat( s.trim() ) );
	if ( ! Number.isFinite( r ) || ! Number.isFinite( g ) || ! Number.isFinite( b ) ) {
		return CONTRAST_WHITE;
	}
	return getContrastColorFromRGB( [ r, g, b ] ) === 'black' ? CONTRAST_BLACK : CONTRAST_WHITE;
};

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

const init = trigger => {
	const panelId = trigger.getAttribute( 'aria-controls' );
	const panel = panelId ? document.getElementById( panelId ) : null;
	if ( ! panel ) {
		return;
	}

	const closeBtn = panel.querySelector( '.newspack-overlay-search__close' );

	document.body.appendChild( panel );

	let isOpen = false;
	let lastFocused = null;
	let focusTrapCleanup = null;
	let escCleanup = null;

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

	const openOverlay = () => {
		if ( isOpen ) {
			return;
		}
		isOpen = true;

		lastFocused = trigger.ownerDocument.activeElement;

		// Recompute on each open so the contrast color tracks any runtime change
		// to the panel's background (theme switches, overlayColor edits, etc.).
		if ( closeBtn ) {
			closeBtn.style.color = pickContrastColor( panel );
		}

		trigger.setAttribute( 'aria-expanded', 'true' );
		panel.setAttribute( 'aria-hidden', 'false' );
		panel.removeAttribute( 'inert' );
		lockScrollbarGutter();
		document.body.classList.add( `menu-open--overlay-search-${ panelId }` );

		focusTrapCleanup = trapFocus();

		const onEsc = e => {
			if ( e.key === 'Escape' ) {
				e.stopPropagation();
				closeOverlay();
			}
		};
		document.addEventListener( 'keydown', onEsc, true );
		escCleanup = () => document.removeEventListener( 'keydown', onEsc, true );

		// Focus the search input on the next paint. Two `requestAnimationFrame`s
		// guarantee that the panel's `aria-hidden`/`inert` flips and the CSS
		// transition's first frame have committed before we try to focus —
		// otherwise the input may still be considered hidden by some browsers.
		// The `isOpen` guard prevents the callback from stealing focus back into
		// the panel if the user closed the overlay before the frame landed.
		requestAnimationFrame( () => {
			requestAnimationFrame( () => {
				if ( ! isOpen ) {
					return;
				}
				const searchInput = panel.querySelector( 'input[type="search"]' );
				const focusTarget = searchInput || getVisibleFocusable( panel )[ 0 ] || closeBtn;
				if ( focusTarget && document.contains( focusTarget ) ) {
					focusTarget.focus();
				}
			} );
		} );
	};

	const closeOverlay = () => {
		if ( ! isOpen ) {
			return;
		}
		isOpen = false;

		if ( focusTrapCleanup ) {
			focusTrapCleanup();
			focusTrapCleanup = null;
		}
		if ( escCleanup ) {
			escCleanup();
			escCleanup = null;
		}

		trigger.setAttribute( 'aria-expanded', 'false' );
		panel.setAttribute( 'aria-hidden', 'true' );
		panel.setAttribute( 'inert', '' );
		document.body.classList.remove( `menu-open--overlay-search-${ panelId }` );
		unlockScrollbarGutter();

		// Return focus to the element that had focus when we opened — usually
		// the trigger, but fall back to it if for some reason that element is
		// gone.
		const focusTarget = lastFocused && document.contains( lastFocused ) ? lastFocused : trigger;
		focusTarget.focus();
	};

	trigger.addEventListener( 'click', e => {
		e.preventDefault();
		if ( isOpen ) {
			closeOverlay();
		} else {
			openOverlay();
		}
	} );

	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', e => {
			e.preventDefault();
			closeOverlay();
		} );
	}

	// Click on the panel background (outside the search form / close button)
	// closes the overlay. Clicks on any child element bubble with that element
	// as e.target, so only true scrim clicks satisfy this check.
	panel.addEventListener( 'click', e => {
		if ( e.target === panel ) {
			closeOverlay();
		}
	} );
};

domReady( () => {
	document.querySelectorAll( '.newspack-overlay-search__trigger[aria-controls]' ).forEach( init );
} );
