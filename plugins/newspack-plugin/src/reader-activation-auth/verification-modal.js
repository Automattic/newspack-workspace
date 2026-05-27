/* globals newspack_ras_config */

/**
 * Helpers for the post-registration verification modal rendered in wp_footer
 * by Reader_Activation::render_verification_modal().
 */

const MODAL_ID = 'newspack-my-account__newspack-reader-verification';

/**
 * Send the verification OTP email.
 *
 * @param {string} nonce Verification nonce.
 * @return {Promise<any>} Resolves with the JSON response on success, rejects on network/HTTP error.
 */
function sendVerificationOTP( nonce ) {
	const body = new FormData();
	body.set( 'action', 'newspack_reader_registration_verification' );
	body.set( 'nonce', nonce );
	return fetch( newspack_ras_config.verification_url, {
		method: 'POST',
		headers: { Accept: 'application/json' },
		body,
	} ).then( res => {
		if ( ! res.ok ) {
			throw new Error( res.statusText );
		}
		return res.json();
	} );
}

/**
 * Open the post-registration verification modal.
 *
 * @param {Object}   config
 * @param {string}   config.email             Email to display in the modal copy.
 * @param {string}   config.verificationNonce Nonce authorizing the OTP request (typically the fresh nonce returned by the register response).
 * @param {Function} [config.onSendCode]      Called once the OTP request succeeds, after the modal closes.
 * @param {Function} [config.onDismiss]       Called when the modal closes without a successful OTP request.
 *
 * @return {boolean} Whether the modal was found and opened.
 */
export function openVerificationModal( config = {} ) {
	const modal = document.getElementById( MODAL_ID );
	const sendOtpButton = modal?.querySelector( '[data-send-otp]' );
	if ( ! modal || ! sendOtpButton ) {
		if ( typeof config.onDismiss === 'function' ) {
			config.onDismiss();
		}
		return false;
	}

	const emailNode = modal.querySelector( '.email-address' );
	if ( emailNode && config.email ) {
		emailNode.textContent = config.email;
	}

	let codeSent = false;

	const cleanup = () => {
		sendOtpButton.removeEventListener( 'click', handleSendClick );
		modal.removeEventListener( 'closeModal', handleClose );
	};

	function handleSendClick() {
		sendOtpButton.disabled = true;
		sendVerificationOTP( config.verificationNonce )
			.then( () => {
				codeSent = true;
				if ( window.newspackReaderActivation?.setOTPTimer ) {
					window.newspackReaderActivation.setOTPTimer();
				}
				modal.setAttribute( 'data-state', 'closed' );
				cleanup();
				if ( typeof config.onSendCode === 'function' ) {
					config.onSendCode();
				}
			} )
			.catch( () => {
				sendOtpButton.disabled = false;
				sendOtpButton.textContent = sendOtpButton.textContent.trim();
				const errorP = modal.querySelector( '.newspack-ui__box p:not(:has(button))' );
				if ( errorP ) {
					errorP.textContent = 'Something went wrong. Please try again.';
				}
			} );
	}

	function handleClose() {
		if ( codeSent ) {
			return;
		}
		cleanup();
		if ( typeof config.onDismiss === 'function' ) {
			config.onDismiss();
		}
	}

	sendOtpButton.disabled = false;
	sendOtpButton.addEventListener( 'click', handleSendClick );
	modal.addEventListener( 'closeModal', handleClose );

	modal.setAttribute( 'data-state', 'open' );
	return true;
}
