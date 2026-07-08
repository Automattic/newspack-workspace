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

/**
 * After a successful subscribe, optionally present a "Continue" button that
 * redirects the reader. This mirrors the Checkout Button block's afterSuccess
 * behavior (click-through, not auto-redirect): 'custom' goes to a publisher-set
 * URL, 'referrer' goes back to the previous page. An empty/absent behavior just
 * leaves the success message in place, as before.
 *
 * The Checkout Button implements this inside the modal-checkout iframe
 * (thankyou.php button + modal.js close handler); the subscription form has no
 * modal, so it is re-implemented natively here against the AJAX success path.
 *
 * @param {HTMLElement} container         The block container holding the data-after-success-* config.
 * @param {HTMLElement} responseContainer The success response container to append the button to.
 * @param {HTMLElement} submitButton      The original form submit button, used to copy color styling.
 * @return {void}
 */
function maybeRenderAfterSuccessButton( container, responseContainer, submitButton ) {
	const behavior = container.getAttribute( 'data-after-success-behavior' );
	if ( ! behavior ) {
		return;
	}
	const url = container.getAttribute( 'data-after-success-url' );
	// Match the Checkout Button: a 'custom' redirect with no URL is a no-op.
	if ( 'custom' === behavior && ! url ) {
		return;
	}
	// Idempotency guard. endFlow runs once per submit and the success branch
	// detaches the form, so this can't be reached twice today — but bail if a
	// Continue button already exists so a future control-flow change can't
	// append a duplicate.
	if ( responseContainer.querySelector( '.newspack-newsletters-subscribe__continue' ) ) {
		return;
	}
	const label = container.getAttribute( 'data-after-success-label' ) || 'Continue';
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.textContent = label;
	// Inherit the form button's classes and inline color styling for full visual
	// parity (block-theme `wp-element-button`, named-palette `has-*` color classes,
	// and custom hex styles). The spinner is a child node, and `in-progress` lives
	// on the form, so the submit button carries no transient classes to strip.
	button.className = 'newspack-newsletters-subscribe__continue ' + ( submitButton?.className || 'submit-button' );
	const submitStyle = submitButton?.getAttribute( 'style' );
	if ( submitStyle ) {
		button.setAttribute( 'style', submitStyle );
	}
	button.addEventListener( 'click', () => {
		if ( 'custom' === behavior ) {
			window.location.href = url;
		} else if ( 'referrer' === behavior ) {
			goToPreviousPage();
		}
	} );
	responseContainer.appendChild( button );
	// Move focus to the new control. The success branch replaces the form, so
	// focus would otherwise be left on the now-detached submit button; since the
	// whole point of this button is to move the reader onward, focusing it helps
	// keyboard and screen-reader users act on it.
	button.focus();
}

/**
 * Navigate to the reader's previous page for the 'referrer' behavior.
 *
 * `window.history.back()` is a no-op when there's no in-session history entry
 * (reader landed directly, opened in a new tab, or the previous entry is
 * cross-origin), leaving the reader stuck with no feedback. Prefer the
 * same-origin `document.referrer` when we have one and fall back to
 * `history.back()` otherwise.
 *
 * @return {void}
 */
function goToPreviousPage() {
	const referrer = document.referrer;
	if ( referrer ) {
		try {
			if ( new URL( referrer ).origin === window.location.origin ) {
				window.location.href = referrer;
				return;
			}
		} catch ( e ) {
			// Malformed referrer — fall through to history.back().
		}
	}
	window.history.back();
}

domReady( function () {
	const successEvent = new Event( 'newspack-newsletters-subscribe-success' );
	document.querySelectorAll( '.newspack-newsletters-subscribe' ).forEach( container => {
		const form = container.querySelector( 'form' );
		if ( ! form ) {
			// No form means the reader is already subscribed: render_block emits
			// the block with data-status="200" and no form, so there's nothing to
			// wire up. By design the Continue button only appears for the reader
			// who just subscribed in this page view — a returning/already-subscribed
			// reader (including one who reloads after subscribing) sees the plain
			// success message, matching the "redirect the reader who just engaged"
			// intent. Adding it here would require SSR'ing the button in the
			// no-form render.
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
				maybeRenderAfterSuccessButton( container, responseContainer, submit );
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
