/**
 * Flow — cancel a pending or expired email invitation.
 *
 * Cancelling removes the invitation and invalidates the join link already sitting
 * in the recipient's inbox, so it is destructive from their point of view even
 * though nothing on the group is lost.
 */

/**
 * WordPress dependencies.
 */
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ConfirmFlow from './ConfirmFlow';

export default function CancelInviteFlow( { invite, actions, onClose, onDone } ) {
	const email = invite?.email || __( 'this recipient', 'newspack-plugin' );

	const cancel = async () => {
		await actions.cancelInvite( invite.email );
		onDone( __( 'Invitation cancelled.', 'newspack-plugin' ) );
	};

	return (
		<ConfirmFlow
			title={ __( 'Cancel invitation', 'newspack-plugin' ) }
			cancelLabel={ __( 'Keep invitation', 'newspack-plugin' ) }
			confirmLabel={ __( 'Cancel invitation', 'newspack-plugin' ) }
			isDestructive
			onCancel={ onClose }
			onConfirm={ cancel }
		>
			{ createInterpolateElement(
				/* translators: <email/> is replaced with the recipient's email address. */
				__(
					"Cancel the invitation to <email/>? The link in their email stops working and they can't join unless you invite them again.",
					'newspack-plugin'
				),
				// The address is a React token, never interpolated into the format
				// string: createInterpolateElement parses its input as markup, so an
				// address — or a translation that mangles the tag — would crash the modal.
				{ email: <strong>{ email }</strong> }
			) }
		</ConfirmFlow>
	);
}
