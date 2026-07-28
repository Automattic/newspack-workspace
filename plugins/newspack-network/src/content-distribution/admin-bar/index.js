/**
 * Front-end admin bar distribution (Newspack UI modal).
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import './style.scss';

const config = window.newspack_network_admin_bar || {};

/**
 * The success message for the configured distribution status, in the locale's
 * own plural form for the given count.
 *
 * @param {number} count The number of network sites distributed to.
 * @return {string} The message.
 */
const getDistributedMessage = count => {
	let template;

	switch ( config.defaultStatus ) {
		case 'draft':
			/* translators: %s is the number of network sites distributed to. */
			template = _n( 'Distributed to %s network site as a draft.', 'Distributed to %s network sites as drafts.', count, 'newspack-network' );
			break;
		case 'pending':
			/* translators: %s is the number of network sites distributed to. */
			template = _n(
				'Distributed to %s network site as pending review.',
				'Distributed to %s network sites as pending review.',
				count,
				'newspack-network'
			);
			break;
		case 'publish':
			/* translators: %s is the number of network sites distributed to. */
			template = _n(
				'Distributed to %s network site and published.',
				'Distributed to %s network sites and published.',
				count,
				'newspack-network'
			);
			break;
		default:
			/* translators: %s is the number of network sites distributed to. */
			template = _n( 'Distributed to %s network site.', 'Distributed to %s network sites.', count, 'newspack-network' );
	}

	return sprintf( template, count );
};

const REQUEST_TIMEOUT = 30000;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Show a Newspack UI snackbar; no-op if the API is unavailable.
 *
 * @param {string} message The message.
 * @param {string} type    'success' or 'error'.
 */
const notify = ( message, type ) => {
	if ( window.newspackUI && window.newspackUI.notices && typeof window.newspackUI.notices.createNotice === 'function' ) {
		window.newspackUI.notices.createNotice( message, type );
	}
};

const init = () => {
	if ( ! config.restUrl ) {
		return;
	}

	const trigger = document.querySelector( '#wp-admin-bar-newspack-network-distribute .ab-item' );
	const modal = document.getElementById( 'newspack-network-distribute-modal' );
	if ( ! trigger || ! modal ) {
		return;
	}

	const fieldset = modal.querySelector( '.newspack-network-distribute-form' );
	const submit = modal.querySelector( '.newspack-network-distribute-submit' );
	const confirmPanel = modal.querySelector( '.newspack-network-distribute-confirm' );
	const confirmMessage = modal.querySelector( '.newspack-network-distribute-confirm-message' );
	const confirmSubmit = modal.querySelector( '.newspack-network-distribute-confirm-submit' );
	const backButton = modal.querySelector( '.newspack-network-distribute-back' );

	// These are dereferenced directly below. They come from one template, so a
	// missing one means the markup isn't ours to drive.
	if ( ! fieldset || ! submit || ! confirmPanel || ! confirmMessage || ! confirmSubmit || ! backButton ) {
		return;
	}

	// Genuinely optional, so these stay null-checked at their use sites.
	const dialog = modal.querySelector( '.newspack-ui__modal' );
	const overlay = modal.querySelector( '.newspack-ui__modal-container__overlay' );
	const selectAll = modal.querySelector( '.newspack-network-distribute-all-toggle' );

	const siteBoxes = () => Array.from( fieldset.querySelectorAll( '.newspack-network-distribute-site input[type="checkbox"]' ) );
	const selectable = () => siteBoxes().filter( box => ! box.disabled );
	const selected = () => selectable().filter( box => box.checked );

	let returnFocus = null;

	// Only what can take focus right now: the panel that isn't showing has a null
	// offsetParent, and disabled controls are out of the tab ring.
	const focusableItems = () =>
		Array.from( modal.querySelectorAll( FOCUSABLE ) ).filter( el => ! el.matches( ':disabled' ) && null !== el.offsetParent );

	const refresh = () => {
		const selectableBoxes = selectable();
		const count = selected().length;
		submit.disabled = 0 === count;
		if ( selectAll ) {
			selectAll.disabled = 0 === selectableBoxes.length;
			selectAll.checked = selectableBoxes.length > 0 && count === selectableBoxes.length;
			selectAll.indeterminate = count > 0 && count < selectableBoxes.length;
		}
	};

	// Distribution cannot be undone from here, and select-all makes fanning a post
	// out to the whole network one click, so the selection is confirmed first.
	const showSelection = () => {
		confirmPanel.hidden = true;
		fieldset.hidden = false;
	};

	const showConfirm = count => {
		/* translators: %s is the number of network sites selected. */
		const question = _n( 'Distribute this post to %s network site?', 'Distribute this post to %s network sites?', count, 'newspack-network' );

		confirmMessage.textContent = [ sprintf( question, count ), __( 'This can’t be undone from here.', 'newspack-network' ) ].join( ' ' );
		fieldset.hidden = true;
		confirmPanel.hidden = false;
		// Focused rather than the first button, so the question is announced before
		// the action that answers it. tabindex="-1" keeps it out of the tab ring.
		confirmMessage.focus();
	};

	const close = () => {
		modal.setAttribute( 'data-state', 'closed' );
	};

	const onKeydown = event => {
		if ( 'Escape' === event.key ) {
			close();
			return;
		}
		if ( 'Tab' !== event.key ) {
			return;
		}
		const focusable = focusableItems();
		if ( ! focusable.length ) {
			return;
		}
		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		if ( event.shiftKey && modal.ownerDocument.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && modal.ownerDocument.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	const open = () => {
		if ( 'open' === modal.getAttribute( 'data-state' ) ) {
			return;
		}
		returnFocus = modal.ownerDocument.activeElement;
		modal.setAttribute( 'data-state', 'open' );
		trigger.setAttribute( 'aria-expanded', 'true' );
		document.addEventListener( 'keydown', onKeydown );
		refresh();
		const focusable = focusableItems();
		if ( focusable.length ) {
			focusable[ 0 ].focus();
		}
	};

	// newspack-ui.js dispatches closeModal when data-state flips to closed
	// (its close button, or our close()). Do all teardown here so every close
	// path converges.
	modal.addEventListener( 'closeModal', () => {
		document.removeEventListener( 'keydown', onKeydown );
		trigger.setAttribute( 'aria-expanded', 'false' );
		// Discard any in-progress (non-distributed) selection so a reopen starts fresh.
		selectable().forEach( box => {
			box.checked = false;
		} );
		showSelection();
		refresh();
		if ( returnFocus && typeof returnFocus.focus === 'function' ) {
			returnFocus.focus();
		}
		returnFocus = null;
	} );

	// WP_Admin_Bar only renders an attribute allowlist, so these are set here.
	trigger.setAttribute( 'aria-haspopup', 'dialog' );
	trigger.setAttribute( 'aria-expanded', 'false' );

	trigger.addEventListener( 'click', event => {
		event.preventDefault();
		open();
	} );

	if ( overlay ) {
		overlay.addEventListener( 'click', close );
	}

	if ( selectAll ) {
		selectAll.addEventListener( 'change', () => {
			selectable().forEach( box => {
				box.checked = selectAll.checked;
			} );
			refresh();
		} );
	}

	fieldset.addEventListener( 'change', event => {
		if ( event.target.matches( '.newspack-network-distribute-site input[type="checkbox"]' ) ) {
			refresh();
		}
	} );

	submit.addEventListener( 'click', () => {
		const count = selected().length;
		if ( count ) {
			showConfirm( count );
		}
	} );

	backButton.addEventListener( 'click', () => {
		showSelection();
		submit.focus();
	} );

	confirmSubmit.addEventListener( 'click', () => {
		const boxes = selected();
		if ( ! boxes.length ) {
			return;
		}
		const urls = boxes.map( box => box.value );

		confirmSubmit.disabled = true;
		backButton.disabled = true;
		confirmSubmit.classList.add( 'newspack-ui__button--loading' );
		if ( dialog ) {
			dialog.setAttribute( 'aria-busy', 'true' );
		}
		// Disabling the focused button blurs it, which would drop focus to <body>
		// and let Tab escape the modal for the length of the request.
		const stillFocusable = focusableItems();
		if ( stillFocusable.length ) {
			stillFocusable[ 0 ].focus();
		}

		const controller = new AbortController();
		const deadline = setTimeout( () => controller.abort(), REQUEST_TIMEOUT );

		fetch( config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			signal: controller.signal,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( { urls, status_on_publish: config.defaultStatus } ),
		} )
			.then( response =>
				response
					.json()
					.catch( () => {
						throw new Error( __( 'The site did not return a valid response.', 'newspack-network' ) );
					} )
					.then( body => {
						if ( ! response.ok ) {
							throw new Error( body && body.message ? body.message : response.statusText );
						}
						return body;
					} )
			)
			.then( () => {
				// Lock in exactly the rows we sent so a reopen renders them distributed.
				boxes.forEach( box => {
					box.checked = true;
					box.disabled = true;
				} );
				notify( getDistributedMessage( boxes.length ), 'success' );
				close();
			} )
			.catch( error => {
				const message =
					'AbortError' === error.name
						? __( 'The request timed out. Please try again.', 'newspack-network' )
						: /* translators: %s is the error message. */
						  sprintf( __( 'Could not distribute: %s', 'newspack-network' ), error.message );
				notify( message, 'error' );
				// Back to the list with the selection intact, so a retry is one click.
				// Not if the modal was closed while the request was in flight: closeModal
				// has already reset it, and focus belongs to whatever it returned to.
				if ( 'open' === modal.getAttribute( 'data-state' ) ) {
					showSelection();
					submit.focus();
				}
			} )
			.finally( () => {
				clearTimeout( deadline );
				confirmSubmit.classList.remove( 'newspack-ui__button--loading' );
				confirmSubmit.disabled = false;
				backButton.disabled = false;
				if ( dialog ) {
					dialog.removeAttribute( 'aria-busy' );
				}
				refresh();
			} );
	} );

	refresh();
};

// A script-strategy or optimisation plugin can run this after DOMContentLoaded.
if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
