/* globals newspack_newsletters_subscribe_block */
/**
 * Internal dependencies
 */
import './style.scss';

let nonce;

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
function domReady( callback ) {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if (
		document.readyState === 'complete' || // DOMContentLoaded + Images/Styles/etc loaded, so we call directly.
		document.readyState === 'interactive' // DOMContentLoaded fires at this point, so we call directly.
	) {
		return void callback();
	}
	// DOMContentLoaded has not fired yet, delay callback until then.
	document.addEventListener( 'DOMContentLoaded', callback );
}

domReady( function () {
	const successEvent = new Event( 'newspack-newsletters-subscribe-success' );
	document.querySelectorAll( '.newspack-newsletters-subscribe' ).forEach( container => {
		const form = container.querySelector( 'form' );
		if ( ! form ) {
			return;
		}
		const responseContainer = container.querySelector( '.newspack-newsletters-subscribe__response' );
		const messageContainer = container.querySelector( '.newspack-newsletters-subscribe__message' );
		const emailInput = container.querySelector( 'input[type="email"]' );
		const submit = container.querySelector( 'button[type="submit"]' );
		const spinner = document.createElement( 'span' );
		spinner.classList.add( 'spinner' );

		form.endFlow = ( message, status = 500, wasSubscribed = false, metadata = {} ) => {
			container.setAttribute( 'data-status', status );
			const messageNode = document.createElement( 'p' );
			emailInput.removeAttribute( 'disabled' );
			submit.removeChild( spinner );
			submit.removeAttribute( 'disabled' );
			form.classList.remove( 'in-progress' );
			messageNode.innerHTML = wasSubscribed ? container.getAttribute( 'data-success-message' ) : message;
			messageContainer.appendChild( messageNode );
			messageNode.className = `message status-${ status }`;
			if ( status === 200 ) {
				container.replaceChild( responseContainer, form );
				form.dispatchEvent( successEvent );
				window.newspackRAS = window.newspackRAS || [];
				const formData = new FormData( form );
				const lists = formData.getAll( 'lists[]' );
				const baseActivity = { email: emailInput.value };
				if ( metadata?.newspack_popup_id ) {
					baseActivity.newspack_popup_id = metadata.newspack_popup_id;
				}
				if ( metadata?.gate_post_id ) {
					baseActivity.gate_post_id = metadata.gate_post_id;
				}
				if ( lists.length && wasSubscribed ) {
					window.newspackRAS.push( function ( ras ) {
						ras.dispatchActivity( 'newsletter_signup', {
							...baseActivity,
							lists,
							newsletters_subscription_method: metadata?.newsletters_subscription_method || 'newsletters-subscription-block',
						} );
					} );
				}
				if ( metadata?.registered ) {
					window.newspackRAS.push( function ( ras ) {
						ras.dispatchActivity( 'reader_registered', {
							...baseActivity,
							registration_method: metadata?.registration_method || 'newsletters-subscription',
						} );
					} );
				}
			}
		};
		form.addEventListener( 'submit', ev => {
			ev.preventDefault();
			messageContainer.innerHTML = '';
			form.classList.add( 'in-progress' );
			submit.disabled = true;
			submit.appendChild( spinner );

			if ( ! form.npe?.value ) {
				return form.endFlow( newspack_newsletters_subscribe_block.invalid_email, 400 );
			}

			const body = new FormData( form );
			if ( ! body.has( 'npe' ) || ! body.get( 'npe' ) ) {
				return form.endFlow( newspack_newsletters_subscribe_block.invalid_email, 400 );
			}
			if ( nonce ) {
				body.set( 'newspack_newsletters_subscribe', nonce );
			}
			emailInput.setAttribute( 'disabled', 'true' );
			submit.setAttribute( 'disabled', 'true' );

			const submitSubscribe = () => {
				fetch( form.getAttribute( 'action' ) || window.location.pathname, {
					method: 'POST',
					headers: {
						Accept: 'application/json',
					},
					body,
				} ).then( res => {
					res.json().then( data => {
						const { message, newspack_newsletters_subscribed: wasSubscribed, newspack_newsletters_subscribe, metadata } = data;
						nonce = newspack_newsletters_subscribe;
						form.endFlow( message, res.status, wasSubscribed, metadata );

						// Post-registration email verification. When newspack-plugin signals that the
						// freshly registered reader needs to verify, hand off to the verification modal
						// exposed on window.newspackReaderActivation. The flow:
						//   1. Verification prompt → reader clicks "Send code" or dismisses.
						//   2. On Send code: auth modal opens in OTP state (newsletters signup is skipped
						//      because the reader just subscribed via this form).
						// Degrades gracefully when running against an older newspack-plugin that doesn't
						// expose the helpers.
						if ( res.status === 200 && data?.registered && data?.verified !== true && data?.verification_nonce ) {
							window.newspackRAS = window.newspackRAS || [];
							window.newspackRAS.push( ras => {
								if ( typeof ras?.openVerificationModal !== 'function' ) {
									return;
								}
								ras.openVerificationModal( {
									email: data.email,
									verificationNonce: data.verification_nonce,
									onSendCode: () => {
										if ( typeof ras?.openAuthModal !== 'function' ) {
											return;
										}
										ras.openAuthModal( {
											skipAuthenticatedCheck: true,
											skipNewslettersSignup: true,
											backButtonClosesModal: true,
											initialState: 'otp',
											closeOnSuccess: true,
											onClose: null,
										} );
									},
								} );
							} );
						}
					} );
				} );
			};

			// When post-registration verification is OFF in Audience → Configuration, intercept
			// new-email subscriptions with a "You're about to create an account for X" confirmation
			// step before any account is provisioned. Verification ON → fall through to subscribe
			// immediately; the verification modal still runs after registration. Degrades to
			// immediate subscribe when running against a newspack-plugin that doesn't expose the
			// helper.
			//
			// Look the helper up synchronously instead of going through window.newspackRAS.push():
			// the push queue is drained on domReady, so if RAS never initialises (older plugin,
			// runtime error in the RAS bundle) the callback never fires and the form gets stuck
			// "in progress" forever. A direct global check fails open to immediate submit.
			const ras = window.newspackReaderActivation;
			if ( typeof ras?.maybeConfirmRegistration !== 'function' ) {
				submitSubscribe();
				return;
			}
			ras.maybeConfirmRegistration( {
				email: body.get( 'npe' ),
				onProceed: submitSubscribe,
				onCancel: () => {
					emailInput.removeAttribute( 'disabled' );
					submit.removeAttribute( 'disabled' );
					if ( submit.contains( spinner ) ) {
						submit.removeChild( spinner );
					}
					form.classList.remove( 'in-progress' );
				},
			} );
		} );
	} );
} );
