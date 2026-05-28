import * as a11y from '../reader-activation-auth/accessibility.js';
import { getApiNonce } from '../reader-activation/session';
import { EVENTS, on } from '../reader-activation/events';

/**
 * Get the newsletters signup modal container.
 *
 * @return {HTMLElement} The modal container.
 */
export function getModalContainer() {
	return document.querySelector( '.newspack-newsletters-signup-modal .newspack-newsletters-signup' );
}

/**
 * Refresh the newsletters signup modal content.
 */
export async function refreshNewslettersSignupModal() {
	const container = getModalContainer();
	if ( ! container ) {
		return;
	}

	const modal = container.closest( '.newspack-newsletters-signup-modal' );
	if ( ! modal ) {
		return;
	}

	const nonce = getApiNonce();
	const headers = {};
	if ( nonce ) {
		headers[ 'X-WP-Nonce' ] = nonce;
	}

	try {
		const newslettersUrl =
			window.newspack_reader_activation_newsletters?.newsletters_url || '/wp-json/newspack/v1/reader-newsletter-signup-lists';
		const res = await fetch( newslettersUrl, {
			credentials: 'same-origin',
			headers,
		} );
		if ( ! res.ok ) {
			return;
		}
		const { html } = await res.json();
		if ( html ) {
			const parser = new DOMParser();
			const doc = parser.parseFromString( html, 'text/html' );
			const existingForm = container.querySelector( 'form' );
			if ( existingForm ) {
				existingForm.remove();
			}
			const newForm = doc.querySelector( '.newspack-newsletters-signup form' );
			if ( newForm ) {
				container.appendChild( newForm );
			}
			container.dispatchEvent( new Event( 'newspack:refresh' ) );
		}
	} catch {
		// Silently fail — modal stays as-is.
	}
}

// Automatically refresh newsletter modal when session is hydrated.
on( EVENTS.session, refreshNewslettersSignupModal );

/**
 * Open the newsletters signup modal.
 *
 * @param {Object} config Configuration object.
 *
 * @return {void}
 */
export function openNewslettersSignupModal( config = {} ) {
	const container = getModalContainer();
	if ( ! container ) {
		if ( config?.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess();
		}
		return;
	}

	const modal = container.closest( '.newspack-newsletters-signup-modal' );
	if ( ! modal ) {
		if ( config?.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess();
		}
		return;
	}
	const form = container.querySelector( 'form' );

	/**
	 * Close the modal.
	 *
	 * @param {boolean} dismiss Whether it's a dismiss action.
	 */
	const close = ( dismiss = true ) => {
		container.config = {};
		modal.setAttribute( 'data-state', 'closed' );
		if ( modal?.overlayId && window?.newspackReaderActivation?.overlays ) {
			window.newspackReaderActivation.overlays.remove( modal.overlayId );
		}
		const openerContent = container.querySelector( '.opener-content' );
		if ( openerContent ) {
			openerContent.remove();
		}
		if ( dismiss && config.onDismiss && typeof config.onDismiss === 'function' ) {
			config.onDismiss();
		}
	};

	const closeButtons = modal.querySelectorAll( 'button[data-close], .newspack-ui__modal__close' );
	if ( closeButtons?.length ) {
		closeButtons.forEach( closeButton => {
			closeButton.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				close();
			} );
		} );
	}

	config.labels = {
		...config?.labels,
	};

	/** Attach config to the container. */
	container.config = config;

	container.newslettersSignupCallback = ( message, data ) => {
		if ( config?.closeOnSuccess ) {
			close( false );
		}
		if ( config?.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess( message, data );
		}
	};

	if ( config?.content ) {
		const openerContent = document.createElement( 'div' );
		openerContent.classList.add( 'opener-content' );
		openerContent.innerHTML = config.content;
		form.insertBefore( openerContent, form.firstChild );
	}

	if ( config?.signupMethod ) {
		form.setAttribute( 'data-signup-method', config.signupMethod );
	}

	// Populate email if not already set.
	const emailInput = modal.querySelector( 'strong.email' );
	if ( emailInput && ! emailInput.innerText ) {
		const reader = window?.newspackReaderActivation?.getReader();

		if ( reader?.email ) {
			emailInput.textContent = reader.email;
		} else {
			// Remove parent element of emailInput if no email is found.
			emailInput.parentElement.remove();
		}
	}

	modal.setAttribute( 'data-state', 'open' );
	a11y.trapFocus( modal );
	if ( window?.newspackReaderActivation?.overlays ) {
		modal.overlayId = window.newspackReaderActivation.overlays.add();
	}
}
