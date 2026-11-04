/**
 * Where new accounts aren't verified after registering, a reader confirms their
 * address before the account is created. Cancelling lets them fix a typo and
 * try again, so the account must be created for the address they confirmed,
 * not the one they cancelled.
 */

import { maybeConfirmRegistration } from './confirmation-modal';

const MODAL_ID = 'newspack-my-account__newspack-reader-registration-confirmation';

/**
 * Wait for the email check to resolve and the confirmation step to open.
 */
const flushRequests = () => new Promise( resolve => setTimeout( resolve, 0 ) );

/**
 * Close the modal the way the newspack-ui modals script reports it.
 *
 * @param {HTMLElement} modal The modal element.
 */
function closeModal( modal ) {
	modal.setAttribute( 'data-state', 'closed' );
	modal.dispatchEvent( new CustomEvent( 'closeModal' ) );
}

describe( 'pre-registration confirmation step', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<div id="${ MODAL_ID }" class="newspack-ui__modal-container" data-state="closed">
				<p>We'll create a new account for <strong class="email-address"></strong>.</p>
				<button type="button" data-confirm-register>Continue</button>
			</div>
		`;
		window.newspack_ras_config = { verify_new_reader_accounts: false, check_email_url: 'https://example.test/check-email' };
		global.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( { exists: false } ) } ) );
	} );

	afterEach( () => {
		delete global.fetch;
	} );

	it( 'creates the account for the corrected address after the reader cancels a typo', async () => {
		const modal = document.getElementById( MODAL_ID );
		const registerTypo = jest.fn();
		const registerCorrected = jest.fn();

		maybeConfirmRegistration( { email: 'raeder@example.test', onProceed: registerTypo } );
		await flushRequests();
		closeModal( modal );

		maybeConfirmRegistration( { email: 'reader@example.test', onProceed: registerCorrected } );
		await flushRequests();
		modal.querySelector( '[data-confirm-register]' ).click();

		expect( registerTypo ).not.toHaveBeenCalled();
		expect( registerCorrected ).toHaveBeenCalledTimes( 1 );
	} );
} );
