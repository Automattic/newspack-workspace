/**
 * Flow — cancel pending or expired email invitations.
 *
 * Cancelling removes the invitation and invalidates the join link already sitting
 * in the recipient's inbox, so it is destructive from their point of view even
 * though nothing on the group is lost.
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
 * Confirm and cancel one or more pending invitations.
 *
 * @param {Object}   props         Component props.
 * @param {Array}    props.invites The invitations to cancel, each carrying `email`.
 * @param {Object}   props.actions The group write calls.
 * @param {Function} props.onClose Close without cancelling.
 * @param {Function} props.onDone  Report the outcome and refresh.
 */
export default function CancelInviteFlow( { invites, actions, onClose, onDone } ) {
	const list = invites || [];
	const count = list.length;
	const email = list[ 0 ]?.email || __( 'this recipient', 'newspack-plugin' );

	const cancel = async () => {
		const { done, failed, firstError } = await eachInvite( list, actions.cancelInvite );
		if ( failed ) {
			const partial = sprintf(
				/* translators: 1: number of invitations cancelled. 2: number selected. */
				_n( '%1$d of %2$d invitation cancelled.', '%1$d of %2$d invitations cancelled.', count, 'newspack-plugin' ),
				done,
				count
			);
			onDone( `${ partial } ${ firstError?.message || __( 'Try the rest again.', 'newspack-plugin' ) }` );
			return;
		}
		onDone(
			1 === count
				? __( 'Invitation cancelled.', 'newspack-plugin' )
				: /* translators: %d: number of invitations cancelled. */
				  sprintf( _n( '%d invitation cancelled.', '%d invitations cancelled.', done, 'newspack-plugin' ), done )
		);
	};

	return (
		<ConfirmFlow
			title={ 1 === count ? __( 'Cancel invitation', 'newspack-plugin' ) : __( 'Cancel invitations', 'newspack-plugin' ) }
			cancelLabel={ 1 === count ? __( 'Keep invitation', 'newspack-plugin' ) : __( 'Keep invitations', 'newspack-plugin' ) }
			confirmLabel={ 1 === count ? __( 'Cancel invitation', 'newspack-plugin' ) : __( 'Cancel invitations', 'newspack-plugin' ) }
			isDestructive
			onCancel={ onClose }
			onConfirm={ cancel }
		>
			{ 1 === count
				? createInterpolateElement(
						/* translators: <email/> is replaced with the recipient's email address. */
						__(
							"Cancel the invitation to <email/>? The link in their email stops working and they can't join unless you invite them again.",
							'newspack-plugin'
						),
						// The address is a React token, never interpolated into the format
						// string: createInterpolateElement parses its input as markup, so an
						// address — or a translation that mangles the tag — would crash the modal.
						{ email: <strong>{ email }</strong> }
				  )
				: sprintf(
						/* translators: %d: number of invitations. */
						_n(
							"Cancel %d invitation? The link in the email stops working and they can't join unless you invite them again.",
							"Cancel %d invitations? The links in their emails stop working and they can't join unless you invite them again.",
							count,
							'newspack-plugin'
						),
						count
				  ) }
		</ConfirmFlow>
	);
}
