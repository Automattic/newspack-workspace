/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — resend a pending or expired email invitation.
 *
 * Resending re-issues the invitation to the same address, which mints a fresh key
 * and restarts the expiry window — so it is also how an expired invitation is
 * brought back to life.
 */

/**
 * WordPress dependencies.
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ConfirmFlow from './ConfirmFlow';

export default function ResendInviteFlow( { invite, actions, onClose, onDone } ) {
	const email = invite?.email || __( 'this recipient', 'newspack-plugin' );

	const resend = async () => {
		await actions.invite( invite.email );
		onDone( __( 'Invitation resent.', 'newspack-plugin' ) );
	};

	return (
		<ConfirmFlow
			title={ __( 'Resend invitation', 'newspack-plugin' ) }
			confirmLabel={ __( 'Resend invitation', 'newspack-plugin' ) }
			onCancel={ onClose }
			onConfirm={ resend }
		>
			{ createInterpolateElement(
				sprintf(
					__( 'Send the invitation email to <strong>%s</strong> again? They get a fresh link and the expiry resets.', 'newspack-plugin' ),
					email
				),
				{ strong: <strong /> }
			) }
		</ConfirmFlow>
	);
}
