/**
 * Flow — resend pending or expired email invitations.
 *
 * Resending re-issues the invitation to the same address, which mints a fresh key
 * and restarts the expiry window — so it is also how an expired invitation is
 * brought back to life.
 */

/**
 * WordPress dependencies.
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ConfirmFlow from './ConfirmFlow';
import eachInvite from './each-invite';

/**
 * Confirm and resend one or more invitations.
 *
 * @param {Object}   props         Component props.
 * @param {Array}    props.invites The invitations to resend, each carrying `email`.
 * @param {Object}   props.actions The group write calls.
 * @param {Function} props.onClose Close without resending.
 * @param {Function} props.onDone  Report the outcome and refresh.
 */
export default function ResendInviteFlow( { invites, actions, onClose, onDone } ) {
	const list = invites || [];
	const count = list.length;
	const email = list[ 0 ]?.email || __( 'this recipient', 'newspack-plugin' );

	const resend = async () => {
		const { done, failed } = await eachInvite( list, actions.invite );
		if ( failed ) {
			/* translators: 1: number of invitations resent. 2: number selected. */
			onDone( sprintf( __( '%1$d of %2$d invitations resent. Try the rest again.', 'newspack-plugin' ), done, count ) );
			return;
		}
		onDone(
			1 === count
				? __( 'Invitation resent.', 'newspack-plugin' )
				: /* translators: %d: number of invitations resent. */
				  sprintf( _n( '%d invitation resent.', '%d invitations resent.', done, 'newspack-plugin' ), done )
		);
	};

	return (
		<ConfirmFlow
			title={ 1 === count ? __( 'Resend invitation', 'newspack-plugin' ) : __( 'Resend invitations', 'newspack-plugin' ) }
			confirmLabel={ 1 === count ? __( 'Resend invitation', 'newspack-plugin' ) : __( 'Resend invitations', 'newspack-plugin' ) }
			onCancel={ onClose }
			onConfirm={ resend }
		>
			{ 1 === count
				? createInterpolateElement(
						/* translators: <email/> is replaced with the recipient's email address. */
						__( 'Send the invitation email to <email/> again? They get a fresh link and the expiry resets.', 'newspack-plugin' ),
						// The address is a React token, never interpolated into the format
						// string: createInterpolateElement parses its input as markup, so an
						// address — or a translation that mangles the tag — would crash the modal.
						{ email: <strong>{ email }</strong> }
				  )
				: sprintf(
						/* translators: %d: number of invitations. */
						_n(
							'Send %d invitation email again? They get a fresh link and the expiry resets.',
							'Send %d invitation emails again? Each recipient gets a fresh link and the expiry resets.',
							count,
							'newspack-plugin'
						),
						count
				  ) }
		</ConfirmFlow>
	);
}
