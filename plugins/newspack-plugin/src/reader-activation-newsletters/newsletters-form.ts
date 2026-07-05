/* globals newspack_reader_activation_newsletters */

/**
 * Internal dependencies.
 */
import { domReady } from '../utils';

import './style.scss';

window.newspackRAS = window.newspackRAS || [];
window.newspackRAS.push( function ( readerActivation ) {
	domReady( function () {
		const containers = [ ...document.querySelectorAll( '.newspack-newsletters-signup' ) ];
		if ( ! containers?.length ) {
			return;
		}

		const setupReveal = container => {
			const seeAllButton = container.querySelector( '.see-all-button' );
			const newsletterContainer = container.querySelector( '.newsletter-list-container' );
			if ( ! seeAllButton || ! newsletterContainer ) {
				return;
			}
			const divider = newsletterContainer.querySelector( '.newspack-ui__gradient-divider' );
			const peekItem = newsletterContainer.querySelector( '.newspack-ui__input-card[inert]:not(.hidden)' );

			seeAllButton.addEventListener( 'click', () => {
				const firstRevealed = newsletterContainer.querySelector( '.newspack-ui__input-card[inert]' );
				newsletterContainer.querySelectorAll( '.newspack-ui__input-card[inert]' ).forEach( item => {
					item.classList.remove( 'hidden' );
					item.removeAttribute( 'inert' );
				} );
				newsletterContainer.style.maxHeight = 'none';
				if ( divider ) {
					divider.classList.add( 'hidden' );
				}
				seeAllButton.classList.add( 'hidden' );
				firstRevealed?.querySelector( 'input' )?.focus();
			} );

			if ( peekItem ) {
				const peekAmount = divider?.offsetHeight || 32;
				newsletterContainer.style.maxHeight = `${ peekItem.offsetTop + peekAmount }px`;
			}
		};

		containers.forEach( container => {
			let form = container.querySelector( 'form' );
			if ( ! form ) {
				return;
			}

			setupReveal( container );

			const handleSubmit = ev => {
				ev.preventDefault();

				if ( form.classList.contains( 'processing' ) ) {
					return;
				}

				form.classList.add( 'processing' );
				form.querySelector( 'button' ).setAttribute( 'disabled', 'disabled' );

				// Populate email if not already set.
				const emailInput = form.querySelector( 'input[name="email_address"]' );
				if ( emailInput && ! emailInput.value ) {
					const reader = readerActivation?.getReader();
					emailInput.value = reader?.email || '';
				}

				const data = new FormData( form );

				data.append( 'action', 'newspack_reader_activation_newsletters_signup' );

				// Ajax request.
				fetch( newspack_reader_activation_newsletters.newspack_ajax_url, {
					method: 'POST',
					body: data,
				} )
					.then( () => {
						const lists = data.getAll( 'lists[]' );
						if ( lists.length ) {
							const signupMethod = form.getAttribute( 'data-signup-method' ) || 'post-checkout';
							readerActivation.dispatchActivity( 'newsletter_signup', {
								email: emailInput.value,
								lists,
								newsletters_subscription_method: signupMethod,
							} );
						}
					} )
					.finally( () => {
						if ( container?.newslettersSignupCallback ) {
							container.newslettersSignupCallback();
						}
						form.classList.remove( 'processing' );
						form.querySelector( 'button' ).removeAttribute( 'disabled' );
					} );
			};

			/**
			 * Handle newsletters signup form submission.
			 */
			form.addEventListener( 'submit', handleSubmit );

			/**
			 * Handle container refresh.
			 */
			container.addEventListener( 'newspack:refresh', () => {
				form = container.querySelector( 'form' );
				if ( ! form ) {
					return;
				}
				// Make sure we aren't adding multiple event listeners to the form.
				form.removeEventListener( 'submit', handleSubmit );
				form.addEventListener( 'submit', handleSubmit );
				setupReveal( container );
			} );
		} );
	} );
} );
