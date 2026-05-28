/* globals newspack_ras_config */
/**
 * Internal dependencies
 */
import { SIGN_IN_MODAL_HASHES, getModalContainer, openAuthModal } from './auth-modal.js';

import { domReady } from '../utils';

import './auth-form.js';

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( readerActivation => {
	domReady( function () {
		/** Expose the openAuthModal function to the RAS scope */
		readerActivation._openAuthModal = openAuthModal;

		/**
		 * Handle hash change.
		 *
		 * @param {Event} ev Hash change event.
		 */
		function handleHashChange( ev ) {
			const container = getModalContainer();
			if ( ! container ) {
				return;
			}

			const currentHash = window.location.hash.replace( '#', '' );
			if ( SIGN_IN_MODAL_HASHES.includes( currentHash ) ) {
				if ( ev ) {
					ev.preventDefault();
				}

				container.setFormAction( currentHash === 'register_modal' ? 'register' : 'signin' );
				openAuthModal( { closeOnSuccess: true } );
			}
		}
		window.addEventListener( 'hashchange', handleHashChange );
		handleHashChange();

		/**
		 * Handle account link click.
		 *
		 * @param {Event} ev Click event.
		 */
		function handleAccountLinkClick( ev ) {
			ev.preventDefault();
			const modalTrigger = ev.target;
			let callback, redirect;
			if ( ev.target.getAttribute( 'data-redirect' ) ) {
				redirect = ev.target.getAttribute( 'data-redirect' );
			} else {
				redirect = ev.target.getAttribute( 'href' );
			}
			if ( ! redirect ) {
				const closestEl = ev.target.closest( 'a' );
				if ( closestEl ) {
					if ( closestEl.getAttribute( 'data-redirect' ) ) {
						redirect = closestEl.getAttribute( 'data-redirect' );
					} else {
						redirect = closestEl.getAttribute( 'href' );
					}
				}
			}
			if ( redirect && redirect !== '#' ) {
				callback = () => {
					window.location.href = redirect;
				};
			}

			openAuthModal( {
				onSuccess: callback,
				onError: callback,
				trigger: modalTrigger,
				closeOnSuccess: true,
			} );
		}

		/**
		 * Initialize trigger links.
		 */
		function initializeTriggerLinks() {
			const triggerLinks = document.querySelectorAll(
				// The href selector excludes the My Account Button block.
				`[data-newspack-reader-account-link],[href="${ newspack_ras_config.account_url }"]:not(.wp-block-newspack-my-account-button)`
			);
			triggerLinks.forEach( link => {
				link.addEventListener( 'click', handleAccountLinkClick );
			} );
		}
		initializeTriggerLinks();
		/** Re-initialize links in case the navigation DOM was modified by a third-party. */
		setTimeout( initializeTriggerLinks, 1000 );

		/**
		 * Handle reader changes.
		 */
		function handleReaderChanges() {
			const reader = window.newspackReaderActivation.getReader();
			const accountLinks = document.querySelectorAll( '.newspack-reader__account-link' );
			if ( accountLinks?.length ) {
				accountLinks.forEach( link => {
					const labels = JSON.parse( link.getAttribute( 'data-labels' ) );
					const labelEl = link.querySelector( '.newspack-reader__account-link__label' );
					if ( labelEl ) {
						// Change the label for the My Account button only.
						const isLoggedIn = link.getAttribute( 'data-newspack-logged-in' ) === '1';
						if ( isLoggedIn ) {
							labelEl.textContent = labels.signedin;
							return;
						}
						labelEl.textContent = reader?.authenticated ? labels.signedin : labels.signedout;

						// Set my account link href if the reader is authenticated.
						if ( reader?.authenticated ) {
							link.setAttribute( 'href', newspack_ras_config.account_url );
						}
					}
				} );
			}
		}
		window.newspackReaderActivation.on( 'reader', handleReaderChanges );
		handleReaderChanges();
	} );

	/**
	 * Detect a reader login via magic link token.
	 */
	const queryString = window.location.search;
	const params = new URLSearchParams( queryString );
	const reader = readerActivation.getReader();
	if ( params.get( newspack_ras_config?.auth_action_result ) && reader?.email && reader?.authenticated ) {
		// Remove the auth action result from the URL.
		params.delete( newspack_ras_config?.auth_action_result );
		const newQueryString = params.toString() ? '?' + params.toString() : '';
		window.history.replaceState( {}, '', window.location.pathname + newQueryString );
		readerActivation.dispatchActivity( 'reader_logged_in', {
			email: reader.email,
			login_method: 'auth-token',
		} );
	}
} );
