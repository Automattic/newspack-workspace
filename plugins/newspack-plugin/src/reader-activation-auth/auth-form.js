/* globals newspack_reader_activation_labels, newspack_ras_config */

/**
 * Internal dependencies.
 */
import { domReady, formatTime, isButtonLoading, setButtonLoading } from '../utils';
import { getPendingCheckout } from '../reader-activation/checkout';
import { openNewslettersSignupModal } from '../reader-activation-newsletters/newsletters-modal';
import { openVerificationModal } from './verification-modal';
import { maybeConfirmRegistration } from './confirmation-modal';
import { shouldReuseActiveCode } from './auth-form-helpers';

import './google-oauth';
import { clearOTPRejection, initOTPInput, rejectOTPCode } from './otp-input';
import { getNoticeIcon } from '../newspack-ui/js/icons';
import { announce } from '../newspack-ui/js/announce';
import { getInitialFocus } from '../newspack-ui/js/focus';
import { setPasswordVisible } from '../newspack-ui/js/password-visibility';

import './style.scss';

const FORM_ALLOWED_ACTIONS = [ 'signin', 'pwd', 'otp', 'success' ];

window.newspackRAS = window.newspackRAS || [];

window.newspackRAS.push( function ( readerActivation ) {
	domReady( function () {
		const containers = [ ...document.querySelectorAll( '.newspack-reader-auth' ) ];
		if ( ! containers?.length ) {
			return;
		}

		containers.forEach( container => {
			const form = container.querySelector( 'form' );
			if ( ! form ) {
				return;
			}

			const actionInput = form.querySelector( 'input[name="action"]' );
			const emailInput = form.querySelector( 'input[name="npe"]' );
			const otpCodeInput = initOTPInput( form.querySelector( 'input[name="otp_code"]' ) );
			const passwordInput = form.querySelector( 'input[name="password"]' );
			const submitButtons = form.querySelectorAll( '[type="submit"]' );
			const backButtons = container.querySelectorAll( '[data-back]' );
			const sendCodeButton = container.querySelector( '[data-send-code]' );
			const resendCodeButton = container.querySelector( '[data-resend-code]' );
			const clearLoading = () =>
				[ ...submitButtons, sendCodeButton, resendCodeButton, ...container.querySelectorAll( '.newspack-ui__button--google-oauth' ) ].forEach(
					control => {
						if ( isButtonLoading( control ) ) {
							setButtonLoading( control, false );
						}
					}
				);
			const messageContentElement = container.querySelector( '.response' );
			if ( messageContentElement && ! messageContentElement.id ) {
				messageContentElement.id = `newspack-reader-auth-response-${ containers.indexOf( container ) }`;
			}

			/**
			 * Check if the current URL has a redirect parameter.
			 * Used to determine if we should pass the full URL to the backend during OAuth flows.
			 *
			 * @return {boolean} True if URL has a 'redirect' query parameter.
			 */
			const hasRedirectInUrl = () => {
				const urlParams = new URLSearchParams( window.location.search );
				return urlParams.has( 'redirect' );
			};

			/**
			 * Set action listener on the given item.
			 */
			const setActionListener = item => {
				item.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					container.setFormAction( ev.target.getAttribute( 'data-set-action' ), true );
				} );
			};

			/**
			 * Sets response message content.
			 *
			 * @param {string|HTMLElement} message Message content.
			 * @param {boolean}            isError Whether the message is an error.
			 *
			 * @return {void}
			 */
			form.setMessageContent = ( message = '', isError = false ) => {
				if ( message ) {
					if ( typeof message === 'string' ) {
						messageContentElement.innerHTML = message;
					} else {
						messageContentElement.appendChild( message );
					}
					if ( isError ) {
						messageContentElement.classList.remove( 'newspack-ui__helper-text', 'newspack-ui__helper-text--success' );
						messageContentElement.classList.add( 'newspack-ui__inline-error' );
					} else {
						messageContentElement.classList.remove( 'newspack-ui__inline-error' );
						messageContentElement.classList.add( 'newspack-ui__helper-text', 'newspack-ui__helper-text--success' );
					}
					messageContentElement.querySelector( ':scope > [data-message-icon]' )?.remove();
					const icon = getNoticeIcon( isError ? 'error' : 'check' );
					if ( icon ) {
						icon.setAttribute( 'data-message-icon', '' );
						messageContentElement.prepend( icon );
					}
					messageContentElement.style.display = 'block';
					announce( messageContentElement.textContent.trim(), isError );
				} else {
					messageContentElement.style.display = 'none';
					messageContentElement.innerHTML = '';
					messageContentElement.classList.remove(
						'newspack-ui__inline-error',
						'newspack-ui__helper-text',
						'newspack-ui__helper-text--success'
					);
				}
			};

			/**
			 * Handle auth form action selection.
			 */
			let formAction;
			// The address a one-time code has been sent to in this modal session. Per session,
			// not the np_otp_hash cookie, which persists ~29 minutes across sessions and would
			// make a genuine first "email me a code" click skip the send (NPPM-3054).
			let codeSentTo = '';

			/**
			 * Whether going back would close the modal rather than return to the email step.
			 *
			 * @return {boolean} True when a back control would close the modal.
			 */
			const backClosesModal = () =>
				!! container.closest( '.newspack-ui__modal-container' ) &&
				!! ( container.config?.backButtonClosesModal || readerActivation.getReader()?.authenticated );
			container.setFormAction = ( action, shouldFocus = false ) => {
				if ( ! FORM_ALLOWED_ACTIONS.includes( action ) ) {
					action = 'signin';
				}
				// Moving to any form step ends the previous step's in-flight request, so release the
				// loading state of every control here: startLoginFlow() sets it, every step change
				// clears it. Same-state errors clear it in endLoginFlow() instead.
				clearLoading();
				// Signin and success steps should clear any modal errors or messages.
				if ( 'signin' === action || 'success' === action ) {
					form.setMessageContent();
				}
				const newspack_grecaptcha = window.newspack_grecaptcha || {};
				if ( 'v2_invisible' === newspack_grecaptcha?.version ) {
					// The unified signin form may result in a registration, so the captcha is rendered
					// for it as well. Other states (otp, pwd, success) skip the captcha challenge.
					if ( 'signin' === action ) {
						form.removeAttribute( 'data-skip-recaptcha' );
						newspack_grecaptcha.render( [ form ], error => form.setMessageContent( error, true ) );
					} else {
						form.setAttribute( 'data-skip-recaptcha', '1' );
					}
				}
				if ( 'otp' === action && ! readerActivation.getOTPHash() ) {
					return;
				}
				if ( 'otp' === action || 'pwd' === action ) {
					const email = readerActivation.getReader()?.email || emailInput?.value || '';
					container.querySelectorAll( '.email-address' ).forEach( element => {
						element.textContent = email;
					} );
				}
				clearOTPRejection( otpCodeInput );
				if ( 'pwd' !== action && passwordInput ) {
					setPasswordVisible( passwordInput.closest( '.newspack-ui__password-field' ), false );
				}
				formAction = action;
				actionInput.value = action;
				container.dataset.formAction = action;
				container.removeAttribute( 'data-form-status' );
				const dialog = container.closest( '.newspack-ui__modal-container[aria-labelledby]' );
				const successTitle = container.querySelector( '.success-title[id]' );
				if ( dialog && successTitle ) {
					dialog.defaultLabelledBy ??= dialog.getAttribute( 'aria-labelledby' );
					dialog.setAttribute( 'aria-labelledby', 'success' === action ? successTitle.id : dialog.defaultLabelledBy );
				}
				container.querySelectorAll( '[data-action]' ).forEach( item => {
					if ( 'none' !== item.style.display ) {
						item.prevDisplay = item.style.display;
					}
					item.style.display = 'none';
				} );
				container.querySelectorAll( '[data-action~="' + action + '"]' ).forEach( item => {
					item.style.display = item.prevDisplay;
				} );
				const hideBack = backClosesModal();
				container.querySelectorAll( '[data-back-target]' ).forEach( control => {
					control.hidden = hideBack;
				} );
				if ( shouldFocus ) {
					if ( action === 'pwd' && emailInput.value ) {
						passwordInput.focus();
					} else if ( action === 'otp' ) {
						otpCodeInput.focus();
					} else {
						emailInput.focus();
					}
				}
				if ( container.formActionCallback ) {
					container.formActionCallback( action );
				}
			};
			container.setFormAction( 'signin' );

			/**
			 * Handle reader changes.
			 */
			const handleReaderChanges = () => {
				const reader = readerActivation.getReader();
				if ( emailInput ) {
					emailInput.value = reader?.email || '';
				}
				setTimeout( function () {
					// A reader who has just registered is authenticated but still owes the
					// verification step. Resolving the flow here would report success to the
					// opener while the verification modal is up, and modal checkout answers
					// that by opening the checkout over it.
					if ( reader?.authenticated && formAction !== 'success' && ! form.isVerifying ) {
						form.endLoginFlow( null, 200 );
					}
				}, 1000 );
			};
			readerActivation.on( 'reader', handleReaderChanges );
			handleReaderChanges();

			backButtons.forEach( backButton => {
				backButton.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					if ( backClosesModal() ) {
						container.closest( '.newspack-ui__modal-container' ).setAttribute( 'data-state', 'closed' );
						return;
					}
					form.setMessageContent();
					container.setFormAction( backButton.dataset.backTarget || 'signin', true );
				} );
			} );

			/**
			 * Handle OTP Timer.
			 */
			const handleOTPTimer = () => {
				if ( ! resendCodeButton ) {
					return;
				}
				resendCodeButton.originalButtonText = resendCodeButton.textContent.replace( /\s\(\d{1,}:\d{2}\)/, '' );
				const updateButton = () => {
					const remaining = readerActivation.getOTPTimeRemaining();
					if ( remaining ) {
						resendCodeButton.textContent = `${ resendCodeButton.originalButtonText } (${ formatTime( remaining ) })`;
					} else {
						resendCodeButton.textContent = resendCodeButton.originalButtonText;
						clearInterval( resendCodeButton.otpTimerInterval );
					}
					if ( remaining ) {
						resendCodeButton.setAttribute( 'aria-disabled', 'true' );
					} else {
						resendCodeButton.removeAttribute( 'aria-disabled' );
					}
				};
				const remaining = readerActivation.getOTPTimeRemaining();
				if ( remaining ) {
					if ( resendCodeButton.otpTimerInterval ) {
						clearInterval( resendCodeButton.otpTimerInterval );
					}
					resendCodeButton.otpTimerInterval = setInterval( updateButton, 1000 );
					updateButton();
				}
			};

			if ( sendCodeButton || resendCodeButton ) {
				[ sendCodeButton, resendCodeButton ].forEach( button => {
					button.addEventListener( 'click', function ( ev ) {
						ev.preventDefault();
						if ( 'true' === ev.currentTarget.getAttribute( 'aria-disabled' ) ) {
							return;
						}
						form.setMessageContent();
						// A reader who already requested a code and returned to the password step can
						// choose the code again. Show the existing code-entry step instead of asking the
						// server for a new code, which would restart the resend cooldown and strand the
						// code already in their inbox. Only the "email me a code" button reuses; resend
						// always requests a fresh code.
						if ( shouldReuseActiveCode( ev.currentTarget === sendCodeButton, !! codeSentTo && codeSentTo === emailInput.value ) ) {
							container.setFormAction( 'otp', true );
							handleOTPTimer();
							return;
						}
						form.startLoginFlow( ev.currentTarget );
						const body = new FormData();
						body.set( 'reader-activation-auth-form', 1 );
						body.set( 'npe', emailInput.value );
						body.set( 'action', 'link' );
						const pendingCheckout = getPendingCheckout();
						if ( pendingCheckout || hasRedirectInUrl() ) {
							const url = new URL( window.location.href );
							if ( pendingCheckout ) {
								url.searchParams.set( 'checkout', 1 );
							}
							body.set( 'redirect_url', url.toString() );
						}
						fetch( form.getAttribute( 'action' ) || window.location.pathname, {
							method: 'POST',
							headers: {
								Accept: 'application/json',
							},
							body,
						} )
							.then( response => {
								if ( 200 !== response.status ) {
									return response.json().then( ( { message } ) => {
										form.endLoginFlow( message, response.status );
									} );
								}
								form.setMessageContent(
									formAction === 'pwd' ? newspack_reader_activation_labels.code_sent : newspack_reader_activation_labels.code_resent
								);
								container.setFormAction( 'otp', true );
								codeSentTo = body.get( 'npe' );
								if ( ! readerActivation.getOTPTimeRemaining() ) {
									readerActivation.setOTPTimer();
								}
							} )
							.finally( () => {
								// startLoginFlow() set the loading state for this send/resend request; this
								// branch doesn't route through setFormAction() on error, so clear it here.
								// Before handleOTPTimer(), so the resend cooldown keeps its aria-disabled.
								clearLoading();
								handleOTPTimer();
							} );
					} );
				} );
			}

			container.querySelectorAll( '[data-set-action]' ).forEach( setActionListener );

			form.startLoginFlow = ( trigger = submitButtons[ 0 ] ) => {
				container.removeAttribute( 'data-form-status' );
				// Set the loading state here for both the modal and the inline auth form (e.g.
				// /my-account). Removal is centralized in setFormAction() (on every step change)
				// and endLoginFlow() (on errors), with the few AJAX branches that bypass those
				// clearing it directly.
				setButtonLoading( trigger, true );
				form.setMessageContent();
			};

			form.isVerifying = false;
			form.endLoginFlow = ( message = null, status = 500, data = null ) => {
				container.setAttribute( 'data-form-status', status );
				if ( status !== 200 ) {
					// Any non-success outcome must restore the form so the reader can retry: clear the
					// loading spinner and re-enable the button. This is the only reset for the
					// null-message network/parse failures from the fetch .catch() paths — they have no
					// other spinner restore and would otherwise leave a stuck spinner. Show an inline
					// error only when we actually have a message.
					form.isVerifying = false;
					if ( message ) {
						form.setMessageContent( message, true );
						messageContentElement.querySelectorAll( '[data-set-action]' ).forEach( setActionListener );
					}
					clearLoading();
				}
				if ( status === 200 ) {
					if ( data?.email ) {
						readerActivation.setReaderEmail( data.email );
						readerActivation.setAuthenticated( !! data.authenticated );
						const activity = { email: data.email };
						const body = new FormData( form );
						if ( data.metadata?.gate_post_id || body.has( 'gate_post_id' ) ) {
							activity.gate_post_id = data.metadata.gate_post_id || body.get( 'gate_post_id' );
						}
						if ( data.metadata?.newspack_popup_id || body.has( 'newspack_popup_id' ) ) {
							activity.newspack_popup_id = data.metadata.newspack_popup_id || body.get( 'newspack_popup_id' );
						}
						if ( data?.sso ) {
							activity.sso = true;
						}
						if ( data?.existing_user ) {
							readerActivation.dispatchActivity( 'reader_logged_in', {
								...activity,
								login_method: data?.metadata?.login_method || 'auth-form',
							} );
						} else {
							readerActivation.dispatchActivity( 'reader_registered', {
								...activity,
								registration_method: data?.metadata?.registration_method || 'auth-form',
							} );
						}
					}

					// Post-registration email verification: detour the new reader through the verification
					// modal before completing the auth flow. The newsletters signup modal is shown after the
					// verification step (or its dismissal) and before the original onSuccess/onClose callbacks.
					// An opener whose own flow verifies the reader, as a checkout does, passes skipVerification.
					const needsVerification =
						! container.config?.skipVerification &&
						data?.registered &&
						newspack_ras_config?.verify_new_reader_accounts &&
						data?.verified !== true &&
						data?.verification_nonce;

					let callback;
					if ( ! container.config?.skipNewslettersSignup && data?.registered && container.authCallback ) {
						// One-shot guard: this callback may run twice — once from the "Continue"
						// click on the success screen, and again when `container.authCallback()`
						// fires `close()` which in turn fires `config.onClose`. Without the guard
						// we'd re-open the newsletter modal and re-reload the page.
						let newslettersShown = false;
						callback = ( authMessage, authData ) => {
							if ( newslettersShown ) {
								return;
							}
							newslettersShown = true;
							openNewslettersSignupModal( {
								onSuccess: () => {
									container.authCallback( authMessage, authData );
									window.location.reload();
								},
								closeOnSuccess: true,
								signupMethod: 'reader-registration',
								onDismiss: () => window.location.reload(),
							} );
						};
						// Also fire the chain if the reader dismisses the success screen via Escape
						// / backdrop / close button without clicking Continue. The guard above
						// prevents the double-fire when they did click Continue.
						container.config.onClose = callback;
					} else {
						callback = container.authCallback;
					}

					if ( needsVerification ) {
						form.isVerifying = true;
						const authModal = container.closest( '.newspack-reader-auth-modal' );
						// Visually hide the auth modal while the verification flow is active.
						if ( authModal ) {
							authModal.style.display = 'none';
						}

						// startLoginFlow() set the loading state for the in-flight register request. This
						// branch opens the verification modal instead of routing through setFormAction(),
						// so clear it now, leaving the auth form interactive when we switch back to it in
						// OTP state on Send code.
						clearLoading();

						// Restore the auth modal's inline display before invoking any callback
						// that might close or re-open it. Skipping this leaves data-state='open'
						// on an element still styled `display:none`, making the modal appear
						// broken on subsequent re-opens.
						const restoreAuthModal = () => {
							if ( authModal ) {
								authModal.style.display = '';
							}
						};

						openVerificationModal( {
							email: data.email,
							verificationNonce: data.verification_nonce,
							setOTPTimer: readerActivation.setOTPTimer,
							onSendCode: () => {
								restoreAuthModal();
								// The verification dialog stays modal until the modal script closes it in a
								// microtask, and focus can't move into the auth modal before then.
								queueMicrotask( () => container.setFormAction( 'otp', true ) );
							},
							onDismiss: () => {
								restoreAuthModal();
								if ( callback ) {
									callback( message, data );
								} else {
									// An inline form has no opener to hand back to, so it finishes by
									// itself. No data: passing the registration would reopen this step.
									form.endLoginFlow( null, 200 );
								}
								form.isVerifying = false;
							},
						} );
						return;
					}

					/** Resolve the modal immediately or display the "success" state. */
					if ( container.config?.skipSuccess ) {
						if ( callback ) {
							callback( message, data );
						}
					} else {
						let labels = newspack_reader_activation_labels.signin;
						if ( data?.registered ) {
							labels = newspack_reader_activation_labels.register;
						}
						container.setFormAction( 'success' );
						container.querySelector( '.success-title' ).innerHTML = labels.success_title || '';
						const successDescription = container.querySelector( '.success-description' );
						successDescription.innerHTML = labels.success_description || '';
						successDescription.hidden = ! labels.success_description;
						const callbackButton = container.querySelector( '.auth-callback' );
						if ( callbackButton && callback ) {
							callbackButton.addEventListener( 'click', ev => {
								ev.preventDefault();
								callback( message, data );
							} );
						}

						const setPasswordButton = container.querySelector( '.set-password' );
						if ( setPasswordButton ) {
							const originalDisplay = setPasswordButton.style.display;
							if ( data?.password_url ) {
								setPasswordButton.style.display = originalDisplay;
								setPasswordButton.setAttribute( 'href', data.password_url );
							} else {
								setPasswordButton.style.display = 'none';
								setPasswordButton.setAttribute( 'href', '#' );
								const continueButton = container.querySelector( '.auth-callback' );
								if ( continueButton ) {
									continueButton.classList.add( 'newspack-ui__last-child' );
								}
							}
						}

						const continueButton = container.querySelector( '.auth-callback' );

						if ( data?.redirect_to && continueButton ) {
							continueButton.setAttribute( 'href', data.redirect_to );
						}
						const modal = container.closest( '.newspack-ui__modal-container' );
						( modal ? getInitialFocus( modal ) : continueButton )?.focus();

						// Auto-redirect if we have a redirect query parameter.
						const urlParams = new URLSearchParams( window.location.search );
						if (
							urlParams.has( 'redirect' ) &&
							continueButton?.href &&
							continueButton.href !== window.location.href &&
							continueButton.href !== '#'
						) {
							try {
								const redirectUrl = new URL( continueButton.href );
								if ( redirectUrl.origin === window.location.origin ) {
									continueButton.style.opacity = 0.5;
									continueButton.style.pointerEvents = 'none';
									window.location.href = redirectUrl.href;
								}
							} catch ( e ) {
								// Invalid URL - continue with normal flow.
							}
						}
					}
				}
			};

			/**
			 * Handle auth form submission.
			 */
			form.addEventListener( 'submit', ev => {
				ev.preventDefault();
				form.startLoginFlow( ev.submitter || undefined );

				const action = form.action?.value;

				if ( ! form.npe?.value ) {
					return form.endLoginFlow( newspack_reader_activation_labels.invalid_email, 400 );
				}

				if ( 'pwd' === action && ! form.password?.value ) {
					return form.endLoginFlow( newspack_reader_activation_labels.invalid_password, 400 );
				}

				const body = new FormData( ev.target );
				if ( ! body.has( 'npe' ) || ! body.get( 'npe' ) ) {
					return form.endLoginFlow( newspack_reader_activation_labels.invalid_email, 400 );
				}
				const pendingCheckout = getPendingCheckout();
				if ( pendingCheckout || hasRedirectInUrl() ) {
					const url = new URL( window.location.href );
					if ( pendingCheckout ) {
						url.searchParams.set( 'checkout', 1 );
					}
					body.set( 'redirect_url', url.toString() );
				}

				if ( 'otp' === action ) {
					readerActivation
						.authenticateOTP( ( body.get( 'otp_code' ) || '' ).replace( /\D/g, '' ) )
						.then( data => {
							if ( form.isVerifying ) {
								data.registered = true;
								form.isVerifying = false;
							}
							form.endLoginFlow( data.message, 200, data );
						} )
						.catch( data => {
							if ( data.expired ) {
								container.setFormAction( 'signin' );
							}
							form.endLoginFlow( data.message, 400 );
							if ( data.invalid_code && otpCodeInput ) {
								rejectOTPCode( otpCodeInput, [ ...submitButtons ], messageContentElement );
							}
						} );
				} else {
					const submitForm = () =>
						fetch( form.getAttribute( 'action' ) || window.location.pathname, {
							method: 'POST',
							headers: {
								Accept: 'application/json',
							},
							body,
						} )
							.then( res => {
								container.setAttribute( 'data-form-status', res.status );
								res.json()
									.then( ( { message, data } ) => {
										const status = res.status;
										if ( status === 200 ) {
											readerActivation.setReaderEmail( body.get( 'npe' ) );
										}
										if ( data.action ) {
											container.setFormAction( data.action, true );
											if ( data.action === 'otp' ) {
												readerActivation.setOTPTimer();
												handleOTPTimer();
											}
										} else {
											form.endLoginFlow( message, status, data );
										}
									} )
									.catch( () => {
										form.endLoginFlow();
									} );
							} )
							.catch( () => {
								form.endLoginFlow();
							} );

					// Only the unified `signin` action can become a new registration server-side;
					// `pwd` and `link` always require an existing user, so they skip the confirmation
					// step. When verification is OFF and the email is new, the confirmation modal
					// appears before the actual register POST.
					if ( 'signin' === action ) {
						maybeConfirmRegistration( {
							email: body.get( 'npe' ),
							onProceed: submitForm,
							onCancel: () => {
								// startLoginFlow() set the loading state; cancelling keeps us on the signin step
								// without routing through setFormAction(), so clear it here.
								clearLoading();
							},
						} );
					} else {
						submitForm();
					}
				}
			} );
		} );

		// Dispatch an event to notify that the auth form is ready.
		document._newspackReaderAuthFormReady = true;
		document.dispatchEvent( new CustomEvent( 'newspack-reader-auth-form-ready', { detail: { containers } } ) );
	} );
} );
