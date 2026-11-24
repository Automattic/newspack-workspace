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
		// A resend that returns without an error is not a delivered email: the new
		// invitation replaces the old one before the send, so an unsent one leaves the
		// recipient with no working link and has to count as a failure.
		const resendOne = async address => {
			const invite = await actions.invite( address );
			if ( false === invite?.email_sent ) {
				throw new Error(
					sprintf(
						/* translators: %s: email address the invitation was addressed to. */
						__( '%s: the invitation was created but the email could not be sent.', 'newspack-plugin' ),
						address
					)
				);
			}
		};
		const { done, failed, firstError } = await eachInvite( list, resendOne );
		if ( failed ) {
			const partial = sprintf(
				/* translators: 1: number of invitations resent. 2: number selected. */
				_n( '%1$d of %2$d invitation resent.', '%1$d of %2$d invitations resent.', count, 'newspack-plugin' ),
				done,
				count
			);
			onDone( `${ partial } ${ firstError?.message || __( 'Try the rest again.', 'newspack-plugin' ) }` );
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
