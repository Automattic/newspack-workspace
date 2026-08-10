/* globals reader_registration_block_config, newspack_ras_config */
/**
 * Internal dependencies
 */
import './style.scss';
import { domReady } from '../../utils';
import { openAuthModal } from '../../reader-activation-auth/auth-modal';
import { openVerificationModal } from '../../reader-activation-auth/verification-modal';
import { maybeConfirmRegistration } from '../../reader-activation-auth/confirmation-modal';
import { openNewslettersSignupModal } from '../../reader-activation-newsletters/newsletters-modal';

window.newspackRAS = window.newspackRAS || [];

window.newspackRAS.push( function ( readerActivation ) {
	/**
	 * Send verification OTP via the dedicated AJAX endpoint.
	 *
	 * @return {Promise} Resolves on success, rejects on failure.
	 */
	const sendVerificationOTP = () => {
		const body = new FormData();
		body.set( 'action', 'newspack_reader_registration_verification' );
		body.set( 'nonce', reader_registration_block_config.verification_nonce );
		return fetch( reader_registration_block_config.verification_url, {
			method: 'POST',
			headers: { Accept: 'application/json' },
			body,
		} ).then( res => {
			if ( ! res.ok ) {
				throw new Error( res.statusText );
			}
			readerActivation.setOTPTimer();
			return res.json();
		} );
	};

	const openAuth = ( initialState = 'otp', overrides = {} ) => {
		openAuthModal( {
			skipAuthenticatedCheck: true,
			skipNewslettersSignup: true,
			backButtonClosesModal: true,
			initialState,
			closeOnSuccess: true,
			skipSuccess: false,
			onClose: () => window.location.reload(),
			...overrides,
		} );
	};

	/**
	 * Show the post-checkout newsletters signup modal (if available) before reloading the page.
	 * Falls back to an immediate reload when the modal isn't on the page.
	 */
	const reloadAfterNewslettersSignup = () => {
		openNewslettersSignupModal( {
			onSuccess: () => window.location.reload(),
			onDismiss: () => window.location.reload(),
			closeOnSuccess: true,
			signupMethod: 'reader-registration',
		} );
	};

	/**
	 * Open the auth modal in OTP state to finish the post-registration verification flow.
	 * Whether the reader submits the OTP form or dismisses it, the newsletters signup modal
	 * is offered (if configured) before the page reload.
	 */
	const openAuthForVerification = () => {
		openAuth( 'otp', {
			onClose: null,
			onSuccess: reloadAfterNewslettersSignup,
			onDismiss: reloadAfterNewslettersSignup,
		} );
	};

	domReady( function () {
		// Wire up inline verification boxes (rendered by the block when the reader is logged in but unverified).
		// The global verification modal is handled by openVerificationModal() instead.
		const inlineVerificationBoxes = [ ...document.querySelectorAll( '.newspack__reader-verification' ) ].filter(
			box => ! box.querySelector( '.newspack-ui__modal-container' )
		);
		inlineVerificationBoxes.forEach( box => {
			const sendOtpButton = box.querySelector( '[data-send-otp]' );
			if ( ! sendOtpButton ) {
				return;
			}
			sendOtpButton.addEventListener( 'click', () => {
				sendOtpButton.disabled = true;
				sendVerificationOTP()
					.then( () => {
						openAuth( 'otp' );
					} )
					.catch( () => {
						sendOtpButton.disabled = false;
						sendOtpButton.textContent = sendOtpButton.textContent.trim();
						const errorP = box.querySelector( 'p:not(:has(button))' );
						if ( errorP ) {
							errorP.textContent = 'Something went wrong. Please try again.';
						}
					} );
			} );
		} );

		document.querySelectorAll( '.newspack-registration' ).forEach( container => {
			const form = container.querySelector( 'form' );

			// Form-specific logic
			if ( ! form ) {
				return;
			}

			let body = new FormData( form );
			let flowCompleted = false; // Guard to prevent re-running endLoginFlow
			const messageElement = container.querySelector( '.newspack-registration__response' );
			const submitElement = form.querySelector( 'button[type="submit"]' );
			const spinner = document.createElement( 'span' );
			spinner.classList.add( 'spinner' );

			form.startLoginFlow = () => {
				messageElement.classList.add( 'newspack-registration--hidden' );
				messageElement.innerHTML = '';
				submitElement.disabled = true;
				submitElement.appendChild( spinner );
				container.classList.add( 'newspack-registration--in-progress' );
			};

			form.endLoginFlow = ( message = null, status = 500, data = null ) => {
				// Prevent re-running after successful completion
				if ( flowCompleted ) {
					return;
				}

				let messageNode;

				// For existing users, open the auth modal with the appropriate state
				if ( data?.existing_user && ! data?.authenticated && data?.action ) {
					const email = data.email || form.npe?.value;
					if ( submitElement.contains( spinner ) ) {
						submitElement.removeChild( spinner );
					}
					submitElement.disabled = false;
					container.classList.remove( 'newspack-registration--in-progress' );

					// Set the reader email before opening the modal
					readerActivation.setReaderEmail( email );

					// For OTP action, check if we have a valid OTP hash cookie
					if ( data.action === 'otp' ) {
						if ( readerActivation.getOTPHash() ) {
							// Valid OTP hash exists, just open the modal
							readerActivation.setOTPTimer();
							openAuth( 'otp' );
						} else {
							// No valid OTP hash, request a fresh one using the email we already have
							const otpBody = new FormData();
							otpBody.set( 'reader-activation-auth-form', '1' );
							otpBody.set( 'npe', email );
							otpBody.set( 'action', 'link' );

							fetch( form.getAttribute( 'action' ) || window.location.pathname, {
								method: 'POST',
								headers: { Accept: 'application/json' },
								body: otpBody,
							} )
								.then( res => {
									if ( res.status === 200 ) {
										readerActivation.setOTPTimer();
										openAuth( 'otp' );
									} else {
										openAuth( 'signin' );
									}
								} )
								.catch( () => openAuth( 'signin' ) );
						}
						return;
					}

					// For password or other actions, just open the modal
					openAuth( data.action );
					return;
				}

				// Check if this is a new registration that needs email verification
				// Note: verified can be false, null, or undefined - we need verification if it's not true
				const needsVerification = ! data?.existing_user && newspack_ras_config.verify_new_reader_accounts && data?.verified !== true;

				// Hide success element first to ensure clean state
				const successElement = container.querySelector( '.newspack-registration__registration-success' );
				successElement?.classList.add( 'newspack-registration--hidden' );

				if ( message ) {
					messageNode = document.createElement( 'p' );
					messageNode.textContent = message;

					const defaultMessage = successElement?.querySelector( 'p' );
					if ( defaultMessage && data?.sso ) {
						defaultMessage.replaceWith( messageNode );
					}
				}

				const isSuccess = status === 200;
				container.classList.add( `newspack-registration--${ isSuccess ? 'success' : 'error' }` );
				if ( isSuccess ) {
					// Set flowCompleted early to prevent 'reader' event listener from interfering
					flowCompleted = true;
					if ( ! needsVerification && ! data?.existing_user ) {
						form.remove();
						successElement.classList.remove( 'newspack-registration--hidden' );
					}
					if ( data?.email ) {
						body = new FormData( form );
						readerActivation.setReaderEmail( data.email );
						readerActivation.setAuthenticated( data?.authenticated );

						if ( needsVerification ) {
							// Use the fresh nonce from the registration response (session changed after login).
							if ( data.verification_nonce ) {
								reader_registration_block_config.verification_nonce = data.verification_nonce;
							}
							openVerificationModal( {
								email: data.email,
								verificationNonce: reader_registration_block_config.verification_nonce,
								setOTPTimer: readerActivation.setOTPTimer,
								onSendCode: openAuthForVerification,
								onDismiss: reloadAfterNewslettersSignup,
							} );
						}
						if ( data.authenticated && ! needsVerification ) {
							const baseActivity = { email: data.email };
							const lists = body.getAll( 'lists[]' );
							if ( body.has( 'newspack_popup_id' ) ) {
								baseActivity.newspack_popup_id = body.get( 'newspack_popup_id' );
							}
							if ( body.has( 'gate_post_id' ) ) {
								baseActivity.gate_post_id = body.get( 'gate_post_id' );
							}
							if ( data?.sso ) {
								baseActivity.sso = true;
							}
							if ( lists?.length ) {
								readerActivation.dispatchActivity( 'newsletter_signup', {
									...baseActivity,
									newsletters_subscription_method: 'reader-registration',
									lists,
								} );
							}
							if ( data?.existing_user ) {
								readerActivation.dispatchActivity( 'reader_logged_in', {
									...baseActivity,
									login_method: data?.metadata?.login_method || 'registration-block',
								} );
							} else {
								readerActivation.dispatchActivity( 'reader_registered', {
									...baseActivity,
									registration_method: data?.metadata?.registration_method || 'registration-block',
								} );
							}
						}
					}
				} else if ( messageNode ) {
					messageElement.appendChild( messageNode );
					messageElement.classList.remove( 'newspack-registration--hidden' );
				}
				if ( submitElement.contains( spinner ) ) {
					submitElement.removeChild( spinner );
				}
				submitElement.disabled = false;
				container.classList.remove( 'newspack-registration--in-progress' );
			};

			form.addEventListener( 'submit', ev => {
				ev.preventDefault();
				form.startLoginFlow();

				if ( ! form.npe?.value ) {
					return form.endLoginFlow( 'Please enter a valid email address.', 400 );
				}

				body = new FormData( form );
				if ( ! body.has( 'npe' ) || ! body.get( 'npe' ) ) {
					return form.endLoginFlow( 'Please enter a valid email address.', 400 );
				}

				const submitForm = () => {
					fetch( form.getAttribute( 'action' ) || window.location.pathname, {
						method: 'POST',
						headers: { Accept: 'application/json' },
						body,
					} )
						.then( res => {
							res.json()
								.then( ( { message, data } ) => form.endLoginFlow( message, res.status, data ) )
								.catch( () => form.endLoginFlow( 'An error occurred.', res.status || 400 ) );
						} )
						.catch( e => {
							form.endLoginFlow( e?.message || 'An error occurred.', 400 );
						} );
				};

				// When post-registration verification is OFF, intercept new-email submissions with a
				// "You're about to create an account for X" confirmation step. Cancel reverts the
				// in-progress UI state so the reader can edit the email and try again.
				maybeConfirmRegistration( {
					email: body.get( 'npe' ),
					onProceed: submitForm,
					onCancel: () => {
						flowCompleted = false;
						if ( submitElement.contains( spinner ) ) {
							submitElement.removeChild( spinner );
						}
						submitElement.disabled = false;
						container.classList.remove( 'newspack-registration--in-progress' );
					},
				} );
			} );

			readerActivation.on( 'reader', ( { detail } ) => {
				if ( detail.authenticated && ! flowCompleted ) {
					form.endLoginFlow( null, 200, { existing_user: true } );
				}
			} );
		} );
	} );
} );
