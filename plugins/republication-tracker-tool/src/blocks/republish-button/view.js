/* global ClipboardUtils */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

let initialized = false;
let currentTrigger = null;

/**
 * Get the textarea for the currently active tab.
 *
 * @return {HTMLElement|null} The active textarea element.
 */
function getActiveTextarea() {
	const activeTextarea = document.querySelector( '.republish-content.republish-content--active textarea' );
	if ( activeTextarea ) {
		return activeTextarea;
	}
	// Fallback to the original textarea if no tabs are present.
	return document.querySelector( '#republication-tracker-tool-shareable-content' );
}

/**
 * Initialize tab switching between HTML and Plain Text formats.
 *
 * @param {HTMLElement} modal The modal element.
 */
function initTabSwitching( modal ) {
	const tabButtons = modal.querySelectorAll( '.republish-format-tabs__button' );
	const tabContents = modal.querySelectorAll( '.republish-content' );
	const mainCopyButton = modal.querySelector( '.republication-tracker-tool__copy-button--main' );

	tabButtons.forEach( button => {
		button.addEventListener( 'click', e => {
			e.preventDefault();
			const targetTab = button.getAttribute( 'data-tab' );

			// Update active states.
			tabButtons.forEach( btn => btn.classList.remove( 'republish-format-tabs__button--active' ) );
			tabContents.forEach( content => content.classList.remove( 'republish-content--active' ) );

			button.classList.add( 'republish-format-tabs__button--active' );
			const targetContent = modal.querySelector( `[data-tab-content="${ targetTab }"]` );
			if ( targetContent ) {
				targetContent.classList.add( 'republish-content--active' );
			}

			// Show/hide main copy button based on active tab.
			if ( mainCopyButton ) {
				if ( targetTab === 'html' ) {
					mainCopyButton.classList.add( 'show-for-html' );
				} else {
					mainCopyButton.classList.remove( 'show-for-html' );
				}
			}
		} );
	} );

	// Initialize copy buttons for individual plain text fields.
	modal.querySelectorAll( '.plain-text-field__button' ).forEach( btn => {
		btn.addEventListener( 'click', e => {
			e.preventDefault();
			const target = btn.getAttribute( 'data-target' );
			if ( window.ClipboardUtils && target ) {
				ClipboardUtils.copyFromElement( target, btn );
			}
		} );
	} );

	// Bind main copy button via data attribute.
	const copyActiveBtn = modal.querySelector( '[data-copy-active]' );
	if ( copyActiveBtn ) {
		copyActiveBtn.addEventListener( 'click', e => {
			e.preventDefault();
			if ( window.ClipboardUtils ) {
				ClipboardUtils.copyFromElement( getActiveTextarea(), copyActiveBtn );
			}
		} );
	}
}

/**
 * Strip captions from shareable content.
 *
 * @param {HTMLElement} modal The modal element.
 */
function stripCaptions( modal ) {
	const shareable = modal.querySelector( '#republication-tracker-tool-shareable-content' );
	if ( ! shareable ) {
		return;
	}
	const html = shareable.textContent;
	const parser = new DOMParser();
	const doc = parser.parseFromString( html, 'text/html' );
	doc.querySelectorAll( '.wp-caption' ).forEach( el => el.remove() );
	shareable.innerHTML = doc.body.innerHTML;
}

/**
 * Trap focus within the modal for accessibility.
 *
 * @param {HTMLElement} modal The modal element.
 */
function trapFocus( modal ) {
	const focusableSelector =
		'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input[type="text"]:not([disabled]), select:not([disabled])';

	modal.addEventListener( 'keydown', e => {
		if ( e.key !== 'Tab' ) {
			return;
		}
		// Recompute visible focusable elements on each Tab so tab switching
		// (which hides controls like the main copy button) doesn't leave
		// stale references that let focus escape the modal.
		const focusableEls = Array.from( modal.querySelectorAll( focusableSelector ) ).filter( el => el.offsetParent !== null );
		if ( ! focusableEls.length ) {
			return;
		}
		const firstFocusable = focusableEls[ 0 ];
		const lastFocusable = focusableEls[ focusableEls.length - 1 ];
		const active = modal.ownerDocument.activeElement;
		if ( e.shiftKey ) {
			if ( active === firstFocusable ) {
				lastFocusable.focus();
				e.preventDefault();
			}
		} else if ( active === lastFocusable ) {
			firstFocusable.focus();
			e.preventDefault();
		}
	} );
}

/**
 * Close the modal and return focus to the trigger button.
 *
 * @param {HTMLElement} modal The modal element.
 */
function closeModal( modal ) {
	document.body.classList.remove( 'modal-open-disallow-scrolling' );
	modal.style.display = 'none';
	if ( currentTrigger ) {
		currentTrigger.focus();
	}
	currentTrigger = null;
}

/**
 * Initialize modal event listeners once.
 *
 * @param {HTMLElement} modal The modal element.
 */
function initModal( modal ) {
	if ( initialized ) {
		return;
	}
	initialized = true;

	const modalContent = modal.querySelector( '#republication-tracker-tool-modal-content' );
	const closeBtn = modal.querySelector( '.republication-tracker-tool-close' );

	// Move modal to body once.
	if ( modal.parentNode !== document.body ) {
		document.body.appendChild( modal );
	}

	// Strip captions once (not per-open).
	stripCaptions( modal );

	// Tab switching (bind once).
	initTabSwitching( modal );

	// Focus trap (bind once).
	trapFocus( modal );

	// Prevent clicks inside modal content from closing modal.
	if ( modalContent ) {
		modalContent.addEventListener( 'click', e => e.stopPropagation() );
	}

	// Click outside modal content closes modal.
	modal.addEventListener( 'click', () => closeModal( modal ) );

	// Close button.
	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', e => {
			e.stopPropagation();
			closeModal( modal );
		} );
	}

	// Escape key.
	document.addEventListener( 'keydown', e => {
		if ( e.key === 'Escape' && modal.style.display !== 'none' ) {
			closeModal( modal );
		}
	} );
}

/**
 * Show the modal.
 *
 * @param {HTMLElement} modal         The modal element.
 * @param {HTMLElement} triggerButton The button that triggered the modal.
 */
function showModal( modal, triggerButton ) {
	initModal( modal );
	currentTrigger = triggerButton;

	const modalContent = modal.querySelector( '#republication-tracker-tool-modal-content' );

	modal.style.display = '';
	if ( modalContent ) {
		modalContent.style.display = '';
	}
	document.body.classList.add( 'modal-open-disallow-scrolling' );

	// Focus close button.
	const closeBtn = modal.querySelector( '.republication-tracker-tool-close' );
	if ( closeBtn ) {
		closeBtn.focus();
	}
}

domReady( () => {
	const modal = document.getElementById( 'republication-tracker-tool-modal' );
	if ( ! modal ) {
		return;
	}

	// Find all block trigger buttons.
	document.querySelectorAll( '[data-modal-trigger="republish"]' ).forEach( button => {
		button.addEventListener( 'click', e => {
			e.preventDefault();
			showModal( modal, button );
		} );
	} );

	// Auto-open via URL hash.
	if ( window.location.hash === '#show-republish' ) {
		const firstTrigger = document.querySelector( '[data-modal-trigger="republish"]' );
		showModal( modal, firstTrigger );
	}
} );
