/**
 * Shared wiring for inline "send me a verification code" boxes — the registration
 * block's box for a logged-in unverified reader, and the content gate's
 * verification prompt. Both send the same OTP request and then hand the reader to
 * the auth modal's OTP state; only what happens after they verify differs.
 */

/**
 * Send the verification OTP email.
 *
 * @param {Object} config
 * @param {string} config.url   The admin-ajax URL to POST to.
 * @param {string} config.nonce Nonce authorizing the OTP request.
 *
 * @return {Promise<any>} Resolves with the JSON response on success, rejects on failure.
 */
export function sendVerificationOTP( { url, nonce } ) {
	const body = new FormData();
	body.set( 'action', 'newspack_reader_registration_verification' );
	body.set( 'nonce', nonce );
	return fetch( url, {
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
			// caller's .catch() runs and the button is re-enabled, instead of advancing to
			// OTP entry for a code that was never sent.
			if ( ! json?.success ) {
				throw new Error( json?.data || 'Failed to send verification code.' );
			}
			return json;
		} );
}

/**
 * Wire an inline verification box's "Send code" button.
 *
 * `onSent` may throw or return false to report that it could not take the reader
 * onward — the code is already in their inbox by then, so the box has to come back
 * to a state they can act on rather than leaving them a dead button.
 *
 * @param {HTMLElement} box                The box element.
 * @param {Object}      config
 * @param {string}      config.url         The admin-ajax URL to POST to.
 * @param {string}      config.nonce       Nonce authorizing the OTP request.
 * @param {Function}    config.onSent      Called once the code is on its way. Return false if it could not proceed.
 * @param {string}      [config.errorText] Message to show in the box when the send fails.
 *
 * @return {boolean} Whether the box was wired.
 */
export function wireInlineVerificationBox( box, { url, nonce, onSent, errorText } ) {
	const button = box?.querySelector( '[data-send-otp]' );
	// A box can be reachable from more than one script on the page; wiring it twice
	// would fire one POST per handler on a single click.
	if ( ! button || button.dataset.otpWired ) {
		return false;
	}
	button.dataset.otpWired = '1';

	// The box marks its own error element. Deriving one structurally would land on a
	// different paragraph in each of the two markups that use this.
	const showError = () => {
		button.disabled = false;
		button.textContent = button.textContent.trim();
		const errorEl = box.querySelector( '[data-error-target]' );
		if ( errorEl && errorText ) {
			errorEl.textContent = errorText;
		}
	};

	button.addEventListener( 'click', () => {
		button.disabled = true;
		sendVerificationOTP( { url, nonce } )
			.then( () => {
				if ( typeof onSent !== 'function' ) {
					return;
				}
				if ( onSent() === false ) {
					showError();
				}
			} )
			.catch( showError );
	} );
	return true;
}
