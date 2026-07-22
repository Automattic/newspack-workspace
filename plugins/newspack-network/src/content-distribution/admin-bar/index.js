/**
 * Front-end admin bar distribution (Newspack UI modal).
 */
import './style.scss';

const config = window.newspack_network_admin_bar || {};

/**
 * Replace %s and %1$s-style placeholders in a translatable string.
 *
 * @param {string}    string Translatable string with %s or %n$s placeholders.
 * @param {...string} values Replacement values, in order.
 * @return {string} The formatted string.
 */
const formatString = ( string, ...values ) => {
	let i = 0;
	return string.replace( /%(\d+\$)?s/g, ( match, position ) => ( position ? values[ parseInt( position, 10 ) - 1 ] : values[ i++ ] ) );
};

const STATUS_MESSAGE_KEYS = {
	draft: 'distributedAsDraft',
	pending: 'distributedAsPending',
	publish: 'distributedAsPublish',
};

/**
 * Pick the singular or plural form. @wordpress/i18n is not on the front end,
 * so this is a two-form approximation.
 *
 * @param {{singular: string, plural: string}} forms Singular/plural templates.
 * @param {number}                             count The count deciding the form.
 * @return {string} The chosen template.
 */
const pluralize = ( forms, count ) => ( count === 1 ? forms.singular : forms.plural );

/**
 * The "distributed" templates for the configured status, falling back to the
 * generic message when the status is missing or unrecognised.
 *
 * @return {{singular: string, plural: string}} Singular/plural templates.
 */
const getDistributedForms = () => config.i18n[ STATUS_MESSAGE_KEYS[ config.defaultStatus ] ] || config.i18n.distributed;

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

	const dialog = modal.querySelector( '.newspack-ui__modal' );
	const overlay = modal.querySelector( '.newspack-ui__modal-container__overlay' );
	const fieldset = modal.querySelector( '.newspack-network-distribute-form' );
	const selectAll = modal.querySelector( '.newspack-network-distribute-all-toggle' );
	const submit = modal.querySelector( '.newspack-network-distribute-submit' );
	const submitLabel = submit.querySelector( 'span' );
	const baseLabel = submitLabel ? submitLabel.textContent : '';
	const siteBoxes = () => Array.from( fieldset.querySelectorAll( '.newspack-network-distribute-site input[type="checkbox"]' ) );
	const selectable = () => siteBoxes().filter( box => ! box.disabled );
	const selected = () => selectable().filter( box => box.checked );

	let returnFocus = null;

	// A disabled <fieldset> disables its descendants without giving them a
	// disabled attribute, so :disabled is the only reliable test.
	const focusableItems = () =>
		Array.from( modal.querySelectorAll( FOCUSABLE ) ).filter( el => ! el.matches( ':disabled' ) && null !== el.offsetParent );

	const refresh = () => {
		const selectableBoxes = selectable();
		const count = selected().length;
		if ( submitLabel ) {
			submitLabel.textContent = 0 === count ? baseLabel : formatString( config.i18n.submitCount, count );
		}
		submit.disabled = 0 === count;
		if ( selectAll ) {
			selectAll.disabled = 0 === selectableBoxes.length;
			selectAll.checked = selectableBoxes.length > 0 && count === selectableBoxes.length;
			selectAll.indeterminate = count > 0 && count < selectableBoxes.length;
		}
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
		const boxes = selected();
		if ( ! boxes.length ) {
			return;
		}
		const urls = boxes.map( box => box.value );

		submit.disabled = true;
		submit.classList.add( 'newspack-ui__button--loading' );
		// Disabling the fieldset disables every control inside it (busy state).
		fieldset.disabled = true;
		if ( dialog ) {
			dialog.setAttribute( 'aria-busy', 'true' );
		}
		// Disabling the focused submit button blurs it, which would drop focus to
		// <body> and let Tab escape the modal for the length of the request.
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
						throw new Error( config.i18n.invalidResponse );
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
				notify( formatString( pluralize( getDistributedForms(), boxes.length ), boxes.length ), 'success' );
				close();
			} )
			.catch( error => {
				const message = 'AbortError' === error.name ? config.i18n.timeout : formatString( config.i18n.error, error.message );
				notify( message, 'error' );
			} )
			.finally( () => {
				clearTimeout( deadline );
				submit.classList.remove( 'newspack-ui__button--loading' );
				fieldset.disabled = false;
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
