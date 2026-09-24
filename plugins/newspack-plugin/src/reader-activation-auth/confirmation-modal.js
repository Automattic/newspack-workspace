/* globals newspack_ras_config */

/**
 * Internal dependencies.
 */
import * as a11y from './accessibility.js';

/**
 * Helpers for the pre-registration confirmation modal rendered in wp_footer by
 * Reader_Activation::render_confirmation_modal().
 *
 * When the post-registration verification flow is disabled, registration entry
 * points (auth modal, registration block, newsletter subscribe block) show this
 * modal *before* an account is created so a typo doesn't silently provision
 * one. Continue → proceed with registration; Cancel → abort.
 */

const MODAL_ID = 'newspack-my-account__newspack-reader-registration-confirmation';

/**
 * Module-level handle on the current invocation's AbortController so a later
 * openConfirmationModal() call can drop the previous invocation's listeners
 * before attaching its own — same pattern as verification-modal.js.
 *
 * @type {AbortController|null}
 */
let currentController = null;

/**
 * Open the pre-registration confirmation modal.
 *
 * Returns false (without firing onCancel) when the modal markup isn't rendered;
 * callers decide whether to fail open (`onProceed`) or fail closed for that case.
 * Internal helper for maybeConfirmRegistration() — not exported.
 *
 * @param {Object}   config
 * @param {string}   config.email       Email address to display in the modal copy.
 * @param {Function} [config.onConfirm] Called when the reader clicks Continue, after the modal closes.
 * @param {Function} [config.onCancel]  Called when the reader closes the modal without confirming (X / Escape / Cancel / backdrop).
 *
 * @return {boolean} Whether the modal was found and opened.
 */
function openConfirmationModal( config = {} ) {
	const modal = document.getElementById( MODAL_ID );
	const confirmButton = modal?.querySelector( '[data-confirm-register]' );
	if ( ! modal || ! confirmButton ) {
		// Markup missing — caller decides fail-open vs fail-closed by inspecting the return value.
		return false;
	}

	// Abort any listeners attached by a previous openConfirmationModal() call so a stacked
	// invocation can't fire the previous one's onConfirm/onCancel callbacks.
	if ( currentController ) {
		currentController.abort();
	}
	currentController = new AbortController();
	const { signal } = currentController;

	const emailNode = modal.querySelector( '.email-address' );
	if ( emailNode && config.email ) {
		emailNode.textContent = config.email;
	}

	let confirmed = false;
	const releaseController = () => {
		if ( currentController && currentController.signal === signal ) {
			currentController = null;
		}
	};

	function handleConfirmClick() {
		confirmed = true;
		modal.setAttribute( 'data-state', 'closed' );
		currentController?.abort();
		releaseController();
		if ( typeof config.onConfirm === 'function' ) {
			config.onConfirm();
		}
	}

	function handleClose() {
		if ( confirmed ) {
			return;
		}
		releaseController();
		if ( typeof config.onCancel === 'function' ) {
			config.onCancel();
		}
	}

	confirmButton.disabled = false;
	confirmButton.addEventListener( 'click', handleConfirmClick, { signal } );
	modal.addEventListener( 'closeModal', handleClose, { signal } );

	modal.setAttribute( 'data-state', 'open' );
	a11y.trapFocus( modal );
	return true;
}

/**
 * Gate a registration submit on the pre-registration confirmation step.
 *
 * Behavior:
 *   - Post-registration verification ON (the default): immediately invoke onProceed.
 *     The verification modal will run *after* registration to catch typos.
 *   - Post-registration verification OFF: preflight the check-email endpoint to see
 *     whether the email already belongs to a registered reader.
 *       - Exists → invoke onProceed (the server will route to the appropriate
 *         signin/OTP flow; this isn't a new registration so no confirmation needed).
 *       - Doesn't exist → open the confirmation modal. Continue → onProceed.
 *         Cancel / dismiss → onCancel.
 *       - Network error → fail open and invoke onProceed (the server will still
 *         perform its normal checks; we don't want a flaky preflight to break the
 *         flow).
 *
 * @param {Object}   args
 * @param {string}   args.email      Email to confirm.
 * @param {Function} args.onProceed  Called once the registration should be submitted.
 * @param {Function} [args.onCancel] Called when the reader cancels the confirmation.
 */
export function maybeConfirmRegistration( { email, onProceed, onCancel = () => {} } ) {
	if ( newspack_ras_config?.verify_new_reader_accounts ) {
		onProceed();
		return;
	}
	if ( ! newspack_ras_config?.check_email_url ) {
		// Older newspack-plugin without the helper — fail open.
		onProceed();
		return;
	}

	const proceed = () => onProceed();
	const checkBody = new FormData();
	checkBody.set( 'email', email );

	fetch( newspack_ras_config.check_email_url, {
		method: 'POST',
		headers: { Accept: 'application/json' },
		body: checkBody,
	} )
		.then( res => {
			// Treat non-2xx as a preflight failure and fall open (proceed). Critically,
			// fetch() does NOT reject on HTTP errors — without this guard, a 400
			// (invalid email) or 429 (rate limit) would resolve as { exists: false }
			// and open the confirmation modal instead of letting /register's own
			// validation/rate-limit handle the case.
			if ( ! res.ok ) {
				proceed();
				return null;
			}
			return res.json();
		} )
		.then( json => {
			// `null` signals the non-OK branch above already called proceed().
			if ( null === json ) {
				return;
			}
			if ( json?.exists ) {
				proceed();
				return;
			}
			const opened = openConfirmationModal( {
				email,
				onConfirm: proceed,
				onCancel,
			} );
			// When the confirmation modal markup isn't rendered (older plugin, race with
			// wp_footer, third-party stripping the modal element), fall open so the
			// registration still completes — the modal is a confirmation step, not the
			// authoritative guard.
			if ( ! opened ) {
				proceed();
			}
		} )
		.catch( proceed );
}
