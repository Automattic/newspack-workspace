/* global ClipboardUtils */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

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
 * Select a format tab and show its panel.
 *
 * @param {HTMLElement} modal The modal element.
 * @param {HTMLElement} tab   The tab to select.
 */
function selectTab( modal, tab ) {
	const targetTab = tab.getAttribute( 'data-tab' );

	modal.querySelectorAll( '.republish-format-tabs__button' ).forEach( btn => {
		const selected = btn === tab;
		btn.classList.toggle( 'republish-format-tabs__button--active', selected );
		btn.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
		btn.setAttribute( 'tabindex', selected ? '0' : '-1' );
	} );

	modal.querySelectorAll( '.republish-content' ).forEach( content => {
		content.classList.toggle( 'republish-content--active', content.getAttribute( 'data-tab-content' ) === targetTab );
	} );

	const mainCopyButton = modal.querySelector( '.republication-tracker-tool__copy-button--main' );
	if ( mainCopyButton ) {
		mainCopyButton.classList.toggle( 'show-for-html', targetTab === 'html' );
	}
}

/**
 * Initialize tab switching between HTML and Plain Text formats, and the copy buttons.
 *
 * @param {HTMLElement} modal The modal element.
 */
function initTabSwitching( modal ) {
	const tabs = Array.from( modal.querySelectorAll( '.republish-format-tabs__button' ) );

	tabs.forEach( ( tab, index ) => {
		tab.addEventListener( 'click', e => {
			e.preventDefault();
			selectTab( modal, tab );
		} );

		tab.addEventListener( 'keydown', e => {
			if ( e.defaultPrevented ) {
				return;
			}
			let next = null;
			if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
				next = tabs[ ( index + 1 ) % tabs.length ];
			} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
				next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
			} else if ( e.key === 'Home' ) {
				next = tabs[ 0 ];
			} else if ( e.key === 'End' ) {
				next = tabs[ tabs.length - 1 ];
			}
			if ( next ) {
				e.preventDefault();
				selectTab( modal, next );
				next.focus();
			}
		} );
	} );

	modal.querySelectorAll( '.plain-text-field__button' ).forEach( btn => {
		btn.addEventListener( 'click', e => {
			e.preventDefault();
			const target = btn.getAttribute( 'data-target' );
			if ( window.ClipboardUtils && target ) {
				ClipboardUtils.copyFromElement( target, btn );
			}
		} );
	} );

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
 * Keep keyboard focus inside the open modal.
 *
 * @param {HTMLElement}   modal The modal element.
 * @param {KeyboardEvent} e     The Tab keydown event.
 */
function containFocus( modal, e ) {
	const focusableSelector =
		'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input[type="text"]:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
	// Recomputed on each Tab because switching tabs hides controls.
	const focusableEls = Array.from( modal.querySelectorAll( focusableSelector ) ).filter( el => el.offsetParent !== null );
	if ( ! focusableEls.length ) {
		return;
	}
	const firstFocusable = focusableEls[ 0 ];
	const lastFocusable = focusableEls[ focusableEls.length - 1 ];
	const active = modal.ownerDocument.activeElement;
	const inside = focusableEls.includes( active );

	if ( e.shiftKey && ( active === firstFocusable || ! inside ) ) {
		lastFocusable.focus();
		e.preventDefault();
	} else if ( ! e.shiftKey && ( active === lastFocusable || ! modal.contains( active ) ) ) {
		firstFocusable.focus();
		e.preventDefault();
	}
}

/**
 * Whether the modal is built from newspack-ui, whose script opens and closes it.
 *
 * @param {HTMLElement} modal The modal element.
 * @return {boolean} Whether it uses newspack-ui.
 */
function usesNewspackUI( modal ) {
	return modal.classList.contains( 'newspack-ui__modal-container' );
}

/**
 * Whether the modal is open.
 *
 * @param {HTMLElement} modal The modal element.
 * @return {boolean} Whether it's open.
 */
function isModalOpen( modal ) {
	if ( usesNewspackUI( modal ) ) {
		return modal.getAttribute( 'data-state' ) === 'open';
	}
	return modal.style.display !== 'none';
}

/**
 * Close the modal and return focus to the trigger button.
 *
 * @param {HTMLElement} modal The modal element.
 */
function closeModal( modal ) {
	if ( ! isModalOpen( modal ) ) {
		return;
	}
	if ( usesNewspackUI( modal ) ) {
		modal.setAttribute( 'data-state', 'closed' );
		return;
	}
	document.body.classList.remove( 'modal-open-disallow-scrolling' );
	modal.style.display = 'none';
	if ( modal.rttTrigger ) {
		modal.rttTrigger.focus();
	}
	modal.rttTrigger = null;
}

/**
 * Initialize modal event listeners once.
 *
 * @param {HTMLElement} modal The modal element.
 */
function initModal( modal ) {
	if ( modal.rttReady ) {
		return;
	}
	modal.rttReady = true;

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

	// Prevent clicks inside modal content from closing modal.
	if ( modalContent ) {
		modalContent.addEventListener( 'click', e => e.stopPropagation() );
	}

	// Click outside modal content closes modal.
	modal.addEventListener( 'click', e => {
		if ( usesNewspackUI( modal ) && ! e.target.classList.contains( 'newspack-ui__modal-container__overlay' ) ) {
			return;
		}
		closeModal( modal );
	} );

	// Close button.
	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', e => {
			e.stopPropagation();
			closeModal( modal );
		} );
	}

	document.addEventListener( 'keydown', e => {
		if ( usesNewspackUI( modal ) || ! isModalOpen( modal ) ) {
			return;
		}
		if ( e.key === 'Escape' ) {
			closeModal( modal );
		} else if ( e.key === 'Tab' && ! e.defaultPrevented ) {
			containFocus( modal, e );
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
	if ( usesNewspackUI( modal ) ) {
		triggerButton?.focus();
		modal.setAttribute( 'data-state', 'open' );
		return;
	}
	modal.rttTrigger = triggerButton;

	const modalContent = modal.querySelector( '#republication-tracker-tool-modal-content' );

	modal.style.display = '';
	if ( modalContent ) {
		modalContent.style.display = '';
	}
	document.body.classList.add( 'modal-open-disallow-scrolling' );

	const title = modal.querySelector( '#republish-modal-label' );
	if ( title ) {
		title.focus();
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
