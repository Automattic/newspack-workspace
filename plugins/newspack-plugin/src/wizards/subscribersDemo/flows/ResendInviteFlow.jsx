/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Resend a pending/expired email invitation (admin-only).
 *
 * Small confirmation modal. Resending refreshes the sent date, which restarts
 * the 30-day expiry and clears an expired invite back to pending.
 */

import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ConfirmFlow from './ConfirmFlow';

const today = () => new Date().toISOString().slice( 0, 10 );

export default function ResendInviteFlow( { invite, onClose, onComplete } ) {
	const email = invite?.label || __( 'this recipient', 'newspack-plugin' );

	const resend = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message: __( 'Invitation resent.', 'newspack-plugin' ),
			mutate: g => ( {
				...g,
				invites: ( g.invites || [] ).map( i => ( i.id === invite.id ? { ...i, sentAt: today() } : i ) ),
			} ),
		} );
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
					__(
						'Send the invitation email to <strong>%s</strong> again? They get a fresh link and the 30-day expiry resets.',
						'newspack-plugin'
					),
					email
				),
				{ strong: <strong /> }
			) }
		</ConfirmFlow>
	);
}
