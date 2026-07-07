/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Accept pending invitation(s) on the invitee's behalf (single row action
 * or bulk). Converts each pending invite into a member; the seat it reserved
 * becomes the membership, so capacity is unchanged.
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CheckboxControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';
import { addMembersByEmail } from '../data/mock-groups';

const today = () => new Date().toISOString().slice( 0, 10 );

export default function AcceptInviteFlow( { invites, onClose, onComplete } ) {
	const [ notify, setNotify ] = useState( true );
	const emails = ( invites || [] ).map( inv => inv.label ).filter( Boolean );
	const count = emails.length;

	const accept = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message: notify
				? sprintf( _n( '%d member added and notified.', '%d members added and notified.', count, 'newspack-plugin' ), count )
				: sprintf( _n( '%d member added.', '%d members added.', count, 'newspack-plugin' ), count ),
			mutate: g => addMembersByEmail( g, emails, today() ),
		} );
	};

	return (
		<Modal title={ __( 'Accept on behalf', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 }>
				<p className="newspack-subscribers-demo__modal-text">
					{ sprintf(
						_n(
							'%d pending invitation will be accepted and the person added to the group.',
							'%d pending invitations will be accepted and the people added to the group.',
							count,
							'newspack-plugin'
						),
						count
					) }
				</p>
				<CheckboxControl
					label={ __( 'Send a welcome email', 'newspack-plugin' ) }
					help={ __( 'Let the new members know they were added to the group.', 'newspack-plugin' ) }
					checked={ notify }
					onChange={ setNotify }
					__nextHasNoMarginBottom
				/>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" onClick={ accept }>
						{ __( 'Add to group', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
