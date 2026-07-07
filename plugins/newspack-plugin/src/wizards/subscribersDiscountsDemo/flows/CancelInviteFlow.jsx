/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Cancel a pending/expired email invitation (admin-only).
 *
 * Small destructive confirmation modal. Cancelling removes the invitation and
 * invalidates the join link in the recipient's email.
 */

import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ConfirmFlow from './ConfirmFlow';

export default function CancelInviteFlow( { invite, onClose, onComplete } ) {
	const email = invite?.label || __( 'this recipient', 'newspack-plugin' );

	const cancel = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message: __( 'Invitation cancelled.', 'newspack-plugin' ),
			mutate: g => ( { ...g, invites: ( g.invites || [] ).filter( i => i.id !== invite.id ) } ),
		} );
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
				sprintf(
					__(
						"Cancel the invitation to <strong>%s</strong>? The link in their email stops working and they can't join unless you invite them again.",
						'newspack-plugin'
					),
					email
				),
				{ strong: <strong /> }
			) }
		</ConfirmFlow>
	);
}
