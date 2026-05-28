/**
 * Account settings (formerly Edit Account)
 */

import { domReady } from '../../utils';

domReady( function () {
	// Reset password modal.
	const resetPasswordButton = document.getElementById( 'newspack-my-account__reset-password' );
	if ( resetPasswordButton ) {
		const resetPasswordModal = document.getElementById( 'newspack-my-account__reset-password-modal' );
		if ( resetPasswordModal ) {
			resetPasswordButton.addEventListener( 'click', event => {
				event.preventDefault();
				resetPasswordModal.setAttribute( 'data-state', 'open' );
			} );
		}
	}

	// Show confirmation dialogs to delete account.
	const deleteButton = document.querySelector( '#delete-account .newspack-ui__button' );
	if ( deleteButton ) {
		const confirmationModal = document.getElementById( 'newspack-my-account__delete-account' );
		if ( confirmationModal ) {
			const confirmDelete = event => {
				event.preventDefault();
				confirmationModal.setAttribute( 'data-state', 'open' );
			};
			deleteButton.addEventListener( 'click', confirmDelete );
		}
	}
} );
