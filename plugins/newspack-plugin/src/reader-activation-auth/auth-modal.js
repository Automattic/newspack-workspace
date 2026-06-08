/* globals newspack_reader_activation_labels */
export const SIGN_IN_MODAL_HASHES = [ 'signin_modal', 'register_modal' ];
import * as a11y from './accessibility.js';

/**
 * Get the authentication modal container.
 *
 * @return {HTMLElement} The modal container.
 */
export function getModalContainer() {
	return document.querySelector( '.newspack-reader-auth-modal .newspack-reader-auth' );
}

/**
 * Add a callback to be called when the auth form is ready.
 *
 * @param {Function} callback The callback to call when the auth form is ready.
 *
 * @return {void}
 */
export function onAuthFormReady( callback ) {
	// If the auth form is already ready, call the callback immediately.
	if ( document._newspackReaderAuthFormReady ) {
		callback();
		return;
	}
	document.addEventListener( 'newspack-reader-auth-form-ready', callback );
}

/**
 * Open the authentication modal.
 *
 * @param {Object} config Configuration object.
 *
 * @return {void}
 */
export function openAuthModal( config = {} ) {
	const reader = window.newspackReaderActivation.getReader();
	const modalTrigger = config.trigger;

	if ( ! config.skipAuthenticatedCheck && reader?.authenticated ) {
		if ( config.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess();
		}
		return;
	}

	const container = getModalContainer();
	if ( ! container ) {
		if ( config.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess();
		}
		return;
	}

	const modal = container.closest( '.newspack-reader-auth-modal' );
	if ( ! modal ) {
		if ( config.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess();
		}
		return;
	}

	/**
	 * Handle keydown events.
	 *
	 * @param {KeyboardEvent} ev The keyboard event.
	 */
	const handleKeydown = ev => {
		if ( ev.key === 'Escape' ) {
			close();
		}
	};

	/**
	 * Handle close button click.
	 *
	 * @param {MouseEvent} ev The mouse event.
	 */
	const handleCloseButtonClick = ev => {
		ev.preventDefault();
		close();
	};

	/**
	 * Close the auth modal.
	 *
	 * @param {boolean} dismiss Whether it's a dismiss action.
	 */
	let closed = false;
	let succeeded = false;

	const close = () => {
		if ( closed ) {
			return;
		}
		closed = true;

		modal.removeEventListener( 'closeModal', handleModalClose );

		container.config = {};
		modal.setAttribute( 'data-state', 'closed' );
		document.body.classList.remove( 'newspack-signin' );
		if ( modal.overlayId && window.newspackReaderActivation?.overlays ) {
			window.newspackReaderActivation.overlays.remove( modal.overlayId );
		}
		const openerContent = container.querySelector( '.opener-content' );
		if ( openerContent ) {
			openerContent.remove();
		}

		if ( modalTrigger ) {
			modalTrigger.focus();
		}

		if ( ! succeeded && config.onDismiss && typeof config.onDismiss === 'function' ) {
			config.onDismiss();
		}

		if ( config.onClose && typeof config.onClose === 'function' ) {
			config.onClose();
		}

		document.removeEventListener( 'keydown', handleKeydown );
		closeButtons.forEach( closeButton => {
			closeButton.removeEventListener( 'click', handleCloseButtonClick );
		} );
	};

	// Listen for closeModal events dispatched by the newspack-ui modals system.
	const handleModalClose = () => close();
	modal.addEventListener( 'closeModal', handleModalClose );

	const closeButtons = modal.querySelectorAll( 'button[data-close], .newspack-ui__modal__close' );
	if ( closeButtons?.length ) {
		closeButtons.forEach( closeButton => {
			closeButton.addEventListener( 'click', handleCloseButtonClick );
		} );
	}

	document.addEventListener( 'keydown', handleKeydown );

	config.labels = {
		...newspack_reader_activation_labels,
		...config?.labels,
	};

	/** Attach config to the container. */
	container.config = config;

	container.authCallback = ( message, data ) => {
		succeeded = true;
		if ( config?.closeOnSuccess ) {
			close();
		}
		if ( config.onSuccess && typeof config.onSuccess === 'function' ) {
			config.onSuccess( message, data );
		}
	};

	container.formActionCallback = action => {
		const titleEl = modal.querySelector( 'h2' );
		if ( titleEl ) {
			titleEl.textContent = config.labels.signin.title;
		}

		modal.querySelectorAll( '[data-action]' ).forEach( item => {
			if ( 'none' !== item.style.display ) {
				item.prevDisplay = item.style.display;
			}
			item.style.display = 'none';
		} );
		modal.querySelectorAll( '[data-action~="' + action + '"]' ).forEach( item => {
			item.style.display = item.prevDisplay;
		} );
		a11y.trapFocus( modal );
	};

	if ( config.content ) {
		const openerContent = document.createElement( 'div' );
		openerContent.classList.add( 'opener-content' );
		openerContent.innerHTML = config.content;
		const form = container.querySelector( 'form' );
		form.insertBefore( openerContent, form.firstChild );
	}

	const emailInput = container.querySelector( 'input[name="npe"]' );
	if ( emailInput ) {
		emailInput.value = reader?.email || '';
	}

	let initialFormAction = 'signin';
	if ( window.newspackReaderActivation?.hasAuthLink() ) {
		initialFormAction = 'otp';
	}
	if ( config.initialState ) {
		initialFormAction = config.initialState;
	}

	onAuthFormReady( () => {
		container.setFormAction( initialFormAction, true );
		// Default to signin action if otp and timer has expired.
		if ( initialFormAction === 'otp' && window?.newspackReaderActivation?.getOTPTimeRemaining() <= 0 ) {
			container.setFormAction( 'signin' );
		}
	} );

	document.body.classList.add( 'newspack-signin' );
	modal.setAttribute( 'data-state', 'open' );
	if ( window.newspackReaderActivation?.overlays ) {
		modal.overlayId = window.newspackReaderActivation.overlays.add( 'auth-modal' );
		a11y.trapFocus( modal );
	}

	/** Remove the modal hash from the URL if any. */
	if ( SIGN_IN_MODAL_HASHES.includes( window.location.hash.replace( '#', '' ) ) ) {
		history.pushState( '', document.title, window.location.pathname + window.location.search );
	}
}
