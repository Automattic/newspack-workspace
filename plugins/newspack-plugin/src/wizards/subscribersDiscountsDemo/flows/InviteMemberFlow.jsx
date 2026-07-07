/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Invite member(s) by email on behalf of the group owner.
 *
 * Mirrors the owner-facing invite-by-email modal: each address gets an email
 * with a join link that expires in 30 days. The invite link itself is managed
 * separately from the Invitations header (Copy / Regenerate / Disable). This
 * modal diverges from the real single-email form on purpose, accepting several
 * addresses at once via a FormTokenField.
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { FormTokenField, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';
import { inviteCapacity } from '../data/mock-groups';

const today = () => new Date().toISOString().slice( 0, 10 );
const normalize = tokens => [ ...new Set( ( tokens || [] ).map( t => String( t ).trim() ).filter( Boolean ) ) ];

export default function InviteMemberFlow( { group, onClose, onComplete } ) {
	const [ tokens, setTokens ] = useState( [] );

	// Capacity remaining counts members plus pending email invites, so invites
	// can't over-subscribe the group's seat limit. The invite link reserves
	// nothing, so it doesn't enter into this.
	const invites = group.invites || [];
	const pendingEmailAddrs = new Set(
		invites.filter( inv => inv.type === 'email' && inv.status === 'pending' ).map( inv => ( inv.email || '' ).toLowerCase() )
	);
	const remaining = inviteCapacity( group );

	// De-duplicate entered addresses against each other and existing pending invites.
	const emails = normalize( tokens ).filter( e => ! pendingEmailAddrs.has( e.toLowerCase() ) );
	const acceptedEmails = emails.slice( 0, remaining );
	const overCapacity = emails.length > remaining;

	const sendInvites = () => {
		const newInvites = acceptedEmails.map( ( email, i ) => ( {
			id: `inv_${ group.id }_${ Date.now() }_${ i }`,
			type: 'email',
			email,
			status: 'pending',
			sentAt: today(),
		} ) );
		onComplete( {
			type: 'success',
			transient: true,
			message: sprintf( _n( '%d invitation sent.', '%d invitations sent.', newInvites.length, 'newspack-plugin' ), newInvites.length ),
			mutate: g => ( { ...g, invites: [ ...( g.invites || [] ), ...newInvites ] } ),
		} );
	};

	return (
		<Modal title={ __( 'Invite members', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 }>
				<p className="newspack-subscribers-demo__modal-text">
					{ remaining === 0
						? __(
								'No seats are available. Remove a member, cancel a pending invite, or raise the seat limit before inviting.',
								'newspack-plugin'
						  )
						: __( 'Each address gets an email with a link to join. The link expires in 30 days.', 'newspack-plugin' ) }
				</p>
				<FormTokenField
					label={ __( 'Invite by email', 'newspack-plugin' ) }
					value={ tokens }
					onChange={ setTokens }
					disabled={ remaining === 0 }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ overCapacity && (
					<p className="newspack-subscribers-demo__modal-text">
						{ sprintf(
							_n(
								'Only %d invite will be sent to match the available seats.',
								'Only %d invites will be sent to match the available seats.',
								remaining,
								'newspack-plugin'
							),
							remaining
						) }
					</p>
				) }
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ sendInvites } disabled={ acceptedEmails.length === 0 }>
						{ __( 'Send invites', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
