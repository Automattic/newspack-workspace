/* globals newspack_ras_config, newspack_reader_activation_labels */

/**
 * Internal dependencies.
 */
import * as a11y from './accessibility.js';

/**
 * Helpers for the post-registration verification modal rendered in wp_footer
 * by Reader_Activation::render_verification_modal().
 */

const MODAL_ID = 'newspack-my-account__newspack-reader-verification';

/**
 * Module-level handle on the current invocation's AbortController so a later
 * openVerificationModal() call can drop the previous invocation's listeners
 * before attaching its own. Without this, repeated calls would stack click and
 * closeModal handlers on the same DOM nodes — clicking "Send code" would fire
 * one POST per stacked handler.
 *
 * @type {AbortController|null}
 */
let currentController = null;

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
	} )
		.then( res => {
			if ( ! res.ok ) {
				throw new Error( res.statusText );
			}
			return res.json();
		} )
		.then( json => {
			// wp_send_json_error() returns HTTP 200 with { success: false }. Reject so the
			// caller's .catch() runs and the "Send code" button is re-enabled, instead of the
			// modal silently advancing to OTP entry for an OTP that was never sent.
			if ( ! json?.success ) {
				throw new Error( json?.data || 'Failed to send verification code.' );
			}
			return json;
		} );
}

/**
 * Open the post-registration verification modal.
 *
 * @param {Object}   config
 * @param {string}   config.email             Email to display in the modal copy.
 * @param {string}   config.verificationNonce Nonce authorizing the OTP request (typically the fresh nonce returned by the register response).
 * @param {Function} [config.setOTPTimer]     Called when the OTP send succeeds, to mark the OTP timer. Typically `readerActivation.setOTPTimer`. When omitted the helper is a no-op for timing.
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

	// Abort any listeners attached by a previous openVerificationModal() call so the
	// "Send code" button can't fan out multiple POSTs and so dismiss handlers don't
	// fire for stale invocations.
	if ( currentController ) {
		currentController.abort();
	}
	currentController = new AbortController();
	const { signal } = currentController;

	const emailNode = modal.querySelector( '.email-address' );
	if ( emailNode && config.email ) {
		emailNode.textContent = config.email;
	}

	let codeSent = false;
	const releaseController = () => {
		if ( currentController && currentController.signal === signal ) {
			currentController = null;
		}
	};

	function handleSendClick() {
		sendOtpButton.disabled = true;
		sendVerificationOTP( config.verificationNonce )
			.then( () => {
				codeSent = true;
				if ( typeof config.setOTPTimer === 'function' ) {
					config.setOTPTimer();
				}
				modal.setAttribute( 'data-state', 'closed' );
				currentController?.abort();
				releaseController();
				if ( typeof config.onSendCode === 'function' ) {
					config.onSendCode();
				}
			} )
			.catch( () => {
				sendOtpButton.disabled = false;
				sendOtpButton.textContent = sendOtpButton.textContent.trim();
				const errorP = modal.querySelector( '.newspack-ui__box p:not(:has(button))' );
				if ( errorP ) {
					errorP.textContent = newspack_reader_activation_labels?.verification_error || '';
				}
			} );
	}

	function handleClose() {
		if ( codeSent ) {
			return;
		}
		releaseController();
		if ( typeof config.onDismiss === 'function' ) {
			config.onDismiss();
		}
	}

	sendOtpButton.disabled = false;
	sendOtpButton.addEventListener( 'click', handleSendClick, { signal } );
	modal.addEventListener( 'closeModal', handleClose, { signal } );

	modal.setAttribute( 'data-state', 'open' );
	a11y.trapFocus( modal );
	return true;
}
