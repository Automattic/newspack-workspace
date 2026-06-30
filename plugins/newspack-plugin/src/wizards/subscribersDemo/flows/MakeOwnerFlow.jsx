/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Promote a specific member to group owner (admin-only).
 *
 * Small confirmation modal launched from a member's row kebab. The chosen
 * member becomes the owner and the previous owner is demoted to a regular
 * member; the change takes effect immediately.
 */

import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ConfirmFlow from './ConfirmFlow';
import { getSubscriberById } from '../data/mock-subscribers';
import { GROUP_LABEL_LOWER } from '../labels';

const today = () => new Date().toISOString().slice( 0, 10 );

export default function MakeOwnerFlow( { group, member, memberName, onClose, onComplete } ) {
	const name = memberName || __( 'this member', 'newspack-plugin' );
	const currentOwnerName = getSubscriberById( group.ownerId )?.name || __( 'the current owner', 'newspack-plugin' );
	const newOwnerId = member.subscriberId;

	const transfer = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message: sprintf( __( 'Ownership transferred to %s.', 'newspack-plugin' ), name ),
			mutate: g => {
				let members = ( g.members || [] ).map( m => ( m.subscriberId === g.ownerId ? { ...m, role: 'member' } : m ) );
				if ( members.some( m => m.subscriberId === newOwnerId ) ) {
					members = members.map( m => ( m.subscriberId === newOwnerId ? { ...m, role: 'owner' } : m ) );
				} else {
					members = [ ...members, { subscriberId: newOwnerId, joinedAt: today(), role: 'owner' } ];
				}
				return { ...g, ownerId: newOwnerId, members };
			},
		} );
	};

	return (
		<ConfirmFlow
			title={ sprintf( __( 'Change %s owner', 'newspack-plugin' ), GROUP_LABEL_LOWER ) }
			confirmLabel={ __( 'Change owner', 'newspack-plugin' ) }
			onCancel={ onClose }
			onConfirm={ transfer }
		>
			{ createInterpolateElement(
				sprintf(
					__(
						'Make <strong>%1$s</strong> the owner of this %2$s? %3$s becomes a regular member. This takes effect immediately.',
						'newspack-plugin'
					),
					name,
					GROUP_LABEL_LOWER,
					currentOwnerName
				),
				{ strong: <strong /> }
			) }
		</ConfirmFlow>
	);
}
